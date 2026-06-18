import sys
import json
import numpy as np

def validate_and_preprocess(data):
    num_days = data.get("days", 7)
    shifts = data.get("shifts", ["Morning", "Evening", "Night"])
    shift_hours = data.get("shiftHours", 8)
    night_premium = data.get("nightPremium", 1.5)
    
    workers_raw = data.get("workers", [])
    demand_raw = data.get("demand", [])

    if not demand_raw:
        return {
            "is_trivial": True, 
            "output": {
                "roster": [], 
                "metrics": {
                    "coveragePct": 100.0, 
                    "totalCost": 0.0, 
                    "loadFairnessStdDev": 0.0, 
                    "nightFairnessStdDev": 0.0, 
                    "hardViolations": 0
                }
            }
        }

    worker_ids = [w["id"] for w in workers_raw]
    worker_map = {w["id"]: w for w in workers_raw}
    
    all_skills = set()
    for w in workers_raw: 
        all_skills.update(w.get("skills", []))
    for d in demand_raw: 
        all_skills.update(d.get("requiredSkills", {}).keys())
    all_skills = list(all_skills)
    skill_to_idx = {skill: idx for idx, skill in enumerate(all_skills)}

    shift_to_idx = {shift: idx for idx, shift in enumerate(shifts)}
    num_workers = len(workers_raw)
    num_shifts = len(shifts)
    
    availability_matrix = np.ones((num_workers, num_days, num_shifts), dtype=int)
    worker_skills_matrix = np.zeros((num_workers, len(all_skills)), dtype=int)
    worker_rates = np.zeros(num_workers)
    worker_max_hours = np.zeros(num_workers)

    for w_idx, w_id in enumerate(worker_ids):
        w = worker_map[w_id]
        worker_rates[w_idx] = w.get("hourlyRate", 0.0)
        worker_max_hours[w_idx] = w.get("maxHours", 0)
        
        for skill in w.get("skills", []):
            if skill in skill_to_idx: 
                worker_skills_matrix[w_idx, skill_to_idx[skill]] = 1
                
        for unavail in w.get("unavailable", []):
            d_unavail = unavail.get("day")
            s_unavail = unavail.get("shift")
            if d_unavail is not None and s_unavail in shift_to_idx:
                availability_matrix[w_idx, d_unavail, shift_to_idx[s_unavail]] = 0

    demand_min_staff = np.zeros((num_days, num_shifts), dtype=int)
    demand_skills_matrix = np.zeros((num_days, num_shifts, len(all_skills)), dtype=int)

    for d_req in demand_raw:
        day = d_req.get("day")
        shift = d_req.get("shift")
        if day is not None and shift in shift_to_idx:
            s_idx = shift_to_idx[shift]
            demand_min_staff[day, s_idx] = d_req.get("minStaff", 0)
            for skill, count in d_req.get("requiredSkills", {}).items():
                if skill in skill_to_idx: 
                    demand_skills_matrix[day, s_idx, skill_to_idx[skill]] = count

    return {
        "is_trivial": False, "num_days": num_days, "shifts": shifts, "shift_to_idx": shift_to_idx,
        "shift_hours": shift_hours, "night_premium": night_premium, "worker_ids": worker_ids,
        "worker_map": worker_map, "availability_matrix": availability_matrix, "worker_skills_matrix": worker_skills_matrix,
        "worker_rates": worker_rates, "worker_max_hours": worker_max_hours, "demand_min_staff": demand_min_staff,
        "demand_skills_matrix": demand_skills_matrix, "all_skills": all_skills, "skill_to_idx": skill_to_idx
    }

if __name__ == "__main__":
    if len(sys.argv) > 1:
        with open(sys.argv[1], 'r') as f: raw_data = json.load(f)
    else:
        raw_data = json.load(sys.stdin)
    parsed_meta = validate_and_preprocess(raw_data)
    print("Parsed data structures validated successfully.")