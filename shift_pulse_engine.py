#!/usr/bin/env python3
"""
SHIFTPULSE CORE OPTIMIZATION ENGINE
Designed for UENR Tech Fair 2026 - Best Programmer Challenge
Implements Constraint Programming (CP-SAT) to build legal, optimal, low-cost rosters.
"""
import sys
import json
import math
from ortools.sat.python import cp_model

def solve_roster(input_data, sick_worker_id=None):
    # 1. PARSE ENVIRONMENT AND ENGINE PARAMETERS
    num_days = input_data.get("days", 7)
    shifts = input_data.get("shifts", ["Morning", "Evening", "Night"])
    num_shifts = len(shifts)
    shift_hours = input_data.get("shiftHours", 8)
    night_premium = input_data.get("nightPremium", 1.5)
    
    workers_raw = input_data.get("workers", [])
    demand_raw = input_data.get("demand", [])
    
    # Process Sick Call Overrides (Bonus Feature)
    if sick_worker_id:
        workers_raw = [w for w in workers_raw if w["id"] != sick_worker_id]
        
    workers = {w["id"]: w for w in workers_raw}
    worker_ids = list(workers.keys())
    
    # Extract unique skill universe
    all_skills = set()
    for w in workers_raw:
        all_skills.update(w.get("skills", []))
    for d in demand_raw:
        all_skills.update(d.get("requiredSkills", {}).keys())
    all_skills = list(all_skills)
    
    # Map index shortcuts
    shift_to_idx = {s: i for i, s in enumerate(shifts)}
    idx_to_shift = {i: s for i, s in enumerate(shifts)}
    skill_to_idx = {sk: i for i, sk in enumerate(all_skills)}
    
    # 2. INITIALIZE CP-SAT MODEL BUILDER
    model = cp_model.CpModel()
    
    # Decision Matrix: x[w, d, s] = 1 if worker w works on day d during shift s
    x = {}
    for w in worker_ids:
        for d in range(num_days):
            for s in range(num_shifts):
                x[w, d, s] = model.NewBoolVar(f'x_{w}_{d}_{s}')
                
    # 3. ENFORCE HARD CONSTRAINTS
    # Availability rule
    for w_id, w in workers.items():
        for unavail in w.get("unavailable", []):
            d_un = unavail.get("day")
            s_un = unavail.get("shift")
            if d_un is not None and s_un in shift_to_idx:
                model.Add(x[w_id, d_un, shift_to_idx[s_un]] == 0)
                
    # No Double Booking & Rest Rules
    for w_id in worker_ids:
        for d in range(num_days):
            # One shift per day max
            model.Add(sum(x[w_id, d, s] for s in range(num_shifts)) <= 1)
            
            # Night shift layout boundary conditions (Mandatory rest rule)
            if "Night" in shift_to_idx and "Morning" in shift_to_idx:
                n_idx = shift_to_idx["Night"]
                m_idx = shift_to_idx["Morning"]
                if d < num_days - 1:
                    model.Add(x[w_id, d, n_idx] + x[w_id, d+1, m_idx] <= 1)

    # Maximum Weekly Hours Rule
    for w_id, w in workers.items():
        max_h = w.get("maxHours", 40)
        total_shifts_allowed = max_h // shift_hours
        model.Add(sum(x[w_id, d, s] for d in range(num_days) for s in range(num_shifts)) <= total_shifts_allowed)

    # 4. PARSE OPERATIONAL DEMANDS GRID
    demand_matrix = {}
    skill_demand_matrix = {}
    
    for d in range(num_days):
        for s in range(num_shifts):
            demand_matrix[d, s] = 0
            for sk_idx in range(len(all_skills)):
                skill_demand_matrix[d, s, sk_idx] = 0
                
    for dem in demand_raw:
        d_tgt = dem.get("day")
        s_tgt = dem.get("shift")
        if d_tgt is not None and s_tgt in shift_to_idx:
            s_idx = shift_to_idx[s_tgt]
            demand_matrix[d_tgt, s_idx] = dem.get("minStaff", 0)
            for sk, qty in dem.get("requiredSkills", {}).items():
                if sk in skill_to_idx:
                    skill_demand_matrix[d_tgt, s_idx, skill_to_idx[sk]] = qty

    # Apply Demands via constraints
    for d in range(num_days):
        for s in range(num_shifts):
            # Min Staff
            model.Add(sum(x[w_id, d, s] for w_id in worker_ids) >= demand_matrix[d, s])
            # Skills coverage Matrix
            for sk_idx, sk_name in enumerate(all_skills):
                req_qty = skill_demand_matrix[d, s, sk_idx]
                if req_qty > 0:
                    model.Add(sum(x[w_id, d, s] for w_id in worker_ids if sk_name in workers[w_id].get("skills", [])) >= req_qty)

    # 5. MULTI-OBJECTIVE COST MINIMIZATION ENGINE
    # Objective weights tracking scale parameters
    cost_expr = []
    for w_id, w in workers.items():
        rate = w.get("hourlyRate", 10)
        for d in range(num_days):
            for s_idx, s_name in enumerate(shifts):
                multiplier = night_premium if s_name == "Night" else 1.0
                shift_cost = int(rate * shift_hours * multiplier * 100) # Convert to integer scale cents
                cost_expr.append(x[w_id, d, s_idx] * shift_cost)
                
    model.Minimize(sum(cost_expr))
    
    # 6. EXECUTE THE OPTIMIZER SOLVER
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 10.0 # Set hard timeout
    status = solver.Solve(model)
    
    # 7. PARSE AND STRUCTURE OUTPUT OBJECTS
    roster_output = []
    hard_violations = 0
    
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # Generate complete roster array structure
        for d in range(num_days):
            for s_idx, s_name in enumerate(shifts):
                assigned_workers = []
                for w_id in worker_ids:
                    if solver.Value(x[w_id, d, s_idx]) == 1:
                        assigned_workers.append(w_id)
                roster_output.append({
                    "day": d,
                    "shift": s_name,
                    "workers": assigned_workers
                })
    else:
        # Fallback to empty roster if completely infeasible
        return {
            "roster": [],
            "metrics": {"coveragePct": 0.0, "totalCost": 0.0, "loadFairnessStdDev": 0.0, "nightFairnessStdDev": 0.0, "hardViolations": 1},
            "infeasibleReason": "Instance parameters are too constrained or insufficient worker pool counts."
        }

    # 8. METRICS STATISTICAL CALCULATOR ENGINE
    total_cost = 0.0
    worker_total_shifts = {w_id: 0 for w_id in worker_ids}
    worker_night_shifts = {w_id: 0 for w_id in worker_ids}
    total_demands_slots = 0
    satisfied_demand_slots = 0

    for slot in roster_output:
        d = slot["day"]
        s_name = slot["shift"]
        s_idx = shift_to_idx[s_name]
        assigned = slot["workers"]
        
        # Verify demand achievements
        total_demands_slots += 1
        is_covered = len(assigned) >= demand_matrix[d, s_idx]
        for sk_idx, sk_name in enumerate(all_skills):
            req = skill_demand_matrix[d, s_idx, sk_idx]
            if req > 0:
                has_sk_count = sum(1 for w_id in assigned if sk_name in workers[w_id].get("skills", []))
                if has_sk_count < req:
                    is_covered = False
        if is_covered:
            satisfied_demand_slots += 1
            
        # Accumulate metrics
        for w_id in assigned:
            worker_total_shifts[w_id] += 1
            rate = workers[w_id].get("hourlyRate", 10)
            multiplier = night_premium if s_name == "Night" else 1.0
            total_cost += float(rate * shift_hours * multiplier)
            if s_name == "Night":
                worker_night_shifts[w_id] += 1

    # Calculate Standard Deviations (Fairness Scores)
    def calc_std_dev(data_dict):
        vals = list(data_dict.values())
        if not vals: return 0.0
        avg = sum(vals) / len(vals)
        variance = sum((v - avg) ** 2 for v in vals) / len(vals)
        return round(math.sqrt(variance), 2)

    coverage_pct = round((satisfied_demand_slots / total_demands_slots) * 100, 1) if total_demands_slots > 0 else 100.0

    return {
        "roster": roster_output,
        "metrics": {
            "coveragePct": coverage_pct,
            "totalCost": round(total_cost, 2),
            "loadFairnessStdDev": calc_std_dev(worker_total_shifts),
            "nightFairnessStdDev": calc_std_dev(worker_night_shifts),
            "hardViolations": hard_violations
        }
    }

if __name__ == "__main__":
    # Handle Standard CLI I/O Pipeline execution interface
    try:
        if len(sys.argv) > 1:
            with open(sys.argv[1], 'r') as f:
                input_json = json.load(f)
        else:
            input_json = json.load(sys.stdin)
            
        output_data = solve_roster(input_json)
        print(json.dumps(output_data, indent=2))
    except Exception as e:
        sys.stderr.write(f"Engine Exception Error: {str(e)}\n")
        sys.exit(1)