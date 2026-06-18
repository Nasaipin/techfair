<?php
/**
 * SHIFTPULSE CENTRAL OPERATIONS CONTROL PORTAL
 * Production Interface connected directly to Python CP-SAT Solver Kernels
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establish Database Handlers Connection Parameters array
try {
    $dsn = "mysql:host=localhost;dbname=techfair_db;charset=utf8mb4";
    $db = new PDO($dsn, "root", ""); 
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div style='background:#ef4444; color:#fff; padding:15px; font-family:sans-serif;'><strong>Database Connection Failure:</strong> Please verify phpMyAdmin table imports. Error: " . $e->getMessage() . "</div>");
}

// REST API BACKEND PIPELINE ACTIONS DISPATCHER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_worker') {
        $stmt = $db->prepare("INSERT INTO workers (id, name, hourly_rate, max_hours, skills, is_active) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE name=?, hourly_rate=?, max_hours=?, skills=?");
        $stmt->execute([$_POST['id'], $_POST['name'], $_POST['hourly_rate'], $_POST['max_hours'], $_POST['skills'], $_POST['name'], $_POST['hourly_rate'], $_POST['max_hours'], $_POST['skills']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_worker') {
        $stmt = $db->prepare("DELETE FROM workers WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'save_settings') {
        $stmt = $db->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$_POST['shift_hours'], 'shift_hours']);
        $stmt->execute([$_POST['night_premium'], 'night_premium']);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'get_optimized_roster') {
        // Compile complete runtime settings map
        $settingsRaw = $db->query("SELECT * FROM global_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $workersRaw = $db->query("SELECT * FROM workers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $demandsRaw = $db->query("SELECT * FROM demand_requirements")->fetchAll(PDO::FETCH_ASSOC);
        $unavailRaw = $db->query("SELECT * FROM unavailabilities")->fetchAll(PDO::FETCH_ASSOC);

        // Process configuration values
        $workersPayload = [];
        foreach ($workersRaw as $w) {
            $w_id = $w['id'];
            
            // Collect exclusions matching specific worker
            $exclusions = [];
            foreach ($unavailRaw as $u) {
                if ($u['worker_id'] === $w_id) {
                    $exclusions[] = ["day" => (int)$u['day_index'], "shift" => $u['shift_name']];
                }
            }

            $workersPayload[] = [
                "id" => $w_id,
                "name" => $w['name'],
                "skills" => array_filter(array_map('trim', explode(',', $w['skills']))),
                "hourlyRate" => (float)$w['hourly_rate'],
                "maxHours" => (int)$w['max_hours'],
                "unavailable" => $exclusions
            ];
        }

        $demandPayload = [];
        foreach ($demandsRaw as $d) {
            $demandPayload[] = [
                "day" => (int)$d['day_index'],
                "shift" => $d['shift_name'],
                "minStaff" => (int)$d['min_staff'],
                "requiredSkills" => json_decode($d['required_skills'], true) ?? (object)[]
            ];
        }

        $inputData = [
            "days" => isset($settingsRaw['days']) ? (int)$settingsRaw['days'] : 7,
            "shifts" => ["Morning", "Evening", "Night"],
            "shiftHours" => isset($settingsRaw['shift_hours']) ? (int)$settingsRaw['shift_hours'] : 8,
            "nightPremium" => isset($settingsRaw['night_premium']) ? (float)$settingsRaw['night_premium'] : 1.5,
            "workers" => $workersPayload,
            "demand" => $demandPayload
        ];

        // Package transaction file array down to Python execution layer via stdin pipeline channel
        $jsonInputString = json_encode($inputData);
        $sickWorkerId = !empty($_POST['sick_worker_id']) ? $_POST['sick_worker_id'] : '';
        
        $pythonCommand = "python shift_pulse_engine.py";
        if (!empty($sickWorkerId)) {
            $pythonCommand = "python -c \"import sys, json, shift_pulse_engine; inp=json.load(sys.stdin); print(json.dumps(shift_pulse_engine.solve_roster(inp, '" . escapeshellcmd($sickWorkerId) . "')))\"";
        }

        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($pythonCommand, $descriptorspec, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $jsonInputString);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            
            proc_close($process);

            if (empty($output)) {
                echo json_encode(["error" => "Python Engine Execution Failure.", "details" => $stderr]);
            } else {
                echo $output; // Deliver compiled matrix array objects to web layout engine
            }
        } else {
            echo json_encode(["error" => "Failed to spin up backend engine resource thread."]);
        }
        exit;
    }
}

// Fetch Initial View Datasets
$workersList = $db->query("SELECT * FROM workers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$settingsRaw = $db->query("SELECT * FROM global_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$demandList = $db->query("SELECT * FROM demand_requirements ORDER BY day_index ASC, FIELD(shift_name, 'Morning', 'Evening', 'Night')")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShiftPulse Workforce Management Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        :root {
            --bg-dark: #0b1329; --panel-bg: #1c2541; --accent: #48cae4;
            --accent-glow: #00b4d8; --text-main: #f7fff7; --text-muted: #94a3b8;
            --danger: #ff5a5f; --success: #06d6a0; --warning: #ffd166;
        }
        html { scroll-behavior: smooth; }
        body { background-color: var(--bg-dark); color: var(--text-main); padding-top: 80px; padding-bottom: 80px; min-height: 100vh; }
        
        /* Animated Navbar */
        .navbar { 
            position: fixed; top: 0; width: 100%; height: 75px; 
            background: rgba(11, 15, 25, 0.92); backdrop-filter: blur(12px);
            border-bottom: 2px solid rgba(72, 202, 228, 0.2);
            display: flex; align-items: center; justify-content: space-between; 
            padding: 0 2rem; z-index: 1000;
            animation: slideDown 0.6s ease-out;
            box-shadow: 0 4px 30px rgba(0,0,0,0.5);
        }
        @keyframes slideDown {
            0% { transform: translateY(-100%); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }
        .nav-brand { 
            font-size: 1.6rem; font-weight: 800; color: #fff; 
            display: flex; align-items: center; gap: 0.6rem; 
        }
        .nav-brand i { 
            color: var(--accent); 
            text-shadow: 0 0 20px rgba(72, 202, 228, 0.3);
            animation: pulseGlow 2.5s infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { filter: drop-shadow(0 0 8px rgba(72, 202, 228, 0.2)); }
            50% { filter: drop-shadow(0 0 25px rgba(72, 202, 228, 0.6)); }
        }
        .nav-links { 
            display: flex; gap: 1.5rem; list-style: none; align-items: center;
        }
        .nav-links li a { 
            color: var(--text-muted); text-decoration: none; font-weight: 600; 
            cursor: pointer; padding: 0.5rem 1rem; border-radius: 8px; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; align-items: center; gap: 0.5rem;
            position: relative;
            font-size: 0.95rem;
        }
        .nav-links li a::after {
            content: '';
            position: absolute; bottom: 0; left: 50%;
            width: 0; height: 2px; background: var(--accent);
            transition: all 0.3s ease;
        }
        .nav-links li a:hover::after, .nav-links li a.active::after {
            width: 60%; left: 20%;
        }
        .nav-links li a.active, .nav-links li a:hover { 
            color: var(--text-main); 
            background: rgba(72, 202, 228, 0.08);
        }
        .nav-hamburger {
            display: none; font-size: 1.8rem; color: var(--text-muted);
            cursor: pointer; transition: color 0.3s ease;
        }
        .nav-hamburger:hover { color: var(--text-main); }

        .container { max-width: 1400px; margin: 0 auto; width: 100%; padding: 0 1.5rem; }
        .view-panel { display: none; animation: viewFade 0.4s ease-in-out forwards; }
        .view-panel.active { display: block; }
        @keyframes viewFade { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .panel-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.06); 
            padding-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; 
        }
        .panel-header h2 { 
            font-size: 1.4rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .panel-header h2 i { color: var(--accent); }
        
        .btn { 
            background: linear-gradient(135deg, var(--accent), var(--accent-glow)); 
            color: #0b1329; border: none; padding: 0.7rem 1.4rem; 
            border-radius: 8px; font-weight: 700; cursor: pointer; 
            display: inline-flex; align-items: center; gap: 0.5rem; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(72, 202, 228, 0.2);
            font-size: 0.95rem;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(72, 202, 228, 0.3); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #e63946); color: white; box-shadow: 0 4px 15px rgba(255, 90, 95, 0.2); }
        .btn-danger:hover { box-shadow: 0 8px 25px rgba(255, 90, 95, 0.3); }
        .btn-success { background: linear-gradient(135deg, var(--success), #05b88a); color: white; box-shadow: 0 4px 15px rgba(6, 214, 160, 0.2); }

        /* KPI Dashboard Metrics Cards Grid */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; margin-bottom: 2rem; }
        .metric-card { 
            background: var(--panel-bg); padding: 1.5rem; border-radius: 12px; 
            border-bottom: 4px solid var(--accent); position: relative; overflow: hidden;
            transition: all 0.3s ease;
        }
        .metric-card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.3); }
        .metric-card i { position: absolute; right: 15px; bottom: 15px; font-size: 2.5rem; color: rgba(255,255,255,0.05); }
        .metric-title { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-val { font-size: 2rem; font-weight: 700; margin-top: 0.4rem; color: #fff; }
        
        .grid-split { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
        .card { 
            background: var(--panel-bg); border-radius: 12px; padding: 1.5rem; 
            margin-bottom: 1.5rem; border: 1px solid rgba(255,255,255,0.05);
            transition: all 0.3s ease;
        }
        .card:hover { border-color: rgba(72, 202, 228, 0.15); }
        .card h3 { 
            margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; 
            border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 0.7rem;
            font-size: 1.1rem;
        }
        
        /* Table Responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -0.5rem;
            padding: 0 0.5rem;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 0.5rem;
            min-width: 600px;
        }
        th, td { 
            padding: 0.85rem; 
            text-align: left; 
            border-bottom: 1px solid rgba(255,255,255,0.05);
            word-break: break-word;
        }
        th { 
            color: var(--text-muted); 
            font-size: 0.8rem; 
            text-transform: uppercase; 
            font-weight: 700; 
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { 
            background: rgba(255,255,255,0.06); padding: 0.25rem 0.7rem; 
            border-radius: 20px; font-size: 0.75rem; font-weight: 600; 
            display: inline-block; margin: 2px; border: 1px solid rgba(255,255,255,0.06);
        }
        .badge-accent { background: rgba(72,202,228,0.12); color: var(--accent); border-color: rgba(72,202,228,0.15); }
        .badge-warning { background: rgba(255,209,102,0.12); color: var(--warning); border-color: rgba(255,209,102,0.15); }
        
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0.4rem; font-weight: 600; }
        .form-control { 
            width: 100%; background: #0b1329; border: 1px solid rgba(255,255,255,0.06); 
            color: #fff; padding: 0.7rem 1rem; border-radius: 8px; font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        .form-control:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px rgba(72, 202, 228, 0.1); }

        .modal-overlay { 
            position: fixed; top:0; left:0; width:100%; height:100%; 
            background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); 
            z-index:2000; display:none; align-items:center; justify-content:center; 
            animation: modalFade 0.3s ease;
        }
        @keyframes modalFade { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .modal-box { 
            background: var(--panel-bg); padding: 2rem; border-radius: 16px; 
            max-width: 500px; width: 90%; border: 1px solid rgba(72,202,228,0.2); 
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            max-height: 90vh; overflow-y: auto;
        }

        /* Enhanced Animated Footer */
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            background: rgba(11, 15, 25, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(72, 202, 228, 0.1);
            padding: 0.8rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            flex-wrap: wrap;
            gap: 0.5rem;
            animation: footerSlideUp 0.6s ease-out 0.3s both;
        }
        @keyframes footerSlideUp {
            0% { transform: translateY(100%); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }
        .footer-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--text-muted);
            font-size: 0.8rem;
            flex-wrap: wrap;
        }
        .footer-left i {
            color: var(--accent);
            margin-right: 0.3rem;
        }
        .footer-socials {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }
        .footer-socials a {
            color: var(--text-muted);
            transition: all 0.3s ease;
            font-size: 1.1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .footer-socials a:hover {
            color: var(--accent);
            background: rgba(72, 202, 228, 0.1);
            transform: translateY(-3px) scale(1.1);
        }
        .footer-socials a:hover i {
            animation: socialBounce 0.6s ease;
        }
        @keyframes socialBounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.3); }
        }
        .footer-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulseDot 1.5s infinite;
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        /* Scroll to Top Button */
        .scroll-top-btn {
            position: fixed;
            bottom: 90px;
            right: 30px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-glow));
            color: #0b1329;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            box-shadow: 0 6px 25px rgba(72, 202, 228, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            transform: translateY(30px) scale(0.9);
            pointer-events: none;
            z-index: 998;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        .scroll-top-btn.visible {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .scroll-top-btn:hover {
            transform: translateY(-5px) scale(1.08);
            box-shadow: 0 10px 40px rgba(72, 202, 228, 0.5);
            border-color: rgba(255, 255, 255, 0.4);
        }
        .scroll-top-btn:active { transform: scale(0.92); }
        .scroll-top-btn i { transition: transform 0.3s ease; }
        .scroll-top-btn:hover i { transform: translateY(-3px); }
        .scroll-top-btn::after {
            content: 'Back to Top';
            position: absolute;
            right: 65px;
            background: rgba(11, 15, 25, 0.9);
            color: var(--text-main);
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .scroll-top-btn:hover::after {
            opacity: 1;
            transform: translateX(-5px);
        }

        .error-message-block { 
            padding: 1.2rem 1.5rem; 
            background: rgba(255,90,95,0.08); 
            border-left: 4px solid var(--danger); 
            color: #ffb3b5; 
            border-radius: 8px; 
            display: none; 
            margin-bottom: 1.5rem; 
            font-size: 0.95rem;
        }

        /* Sick list items */
        .sick-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            background: rgba(11,19,41,0.6);
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.05);
            transition: all 0.2s;
            flex-wrap: wrap;
            gap: 0.3rem;
        }
        .sick-item:hover {
            border-color: rgba(72, 202, 228, 0.2);
        }
        .sick-item .worker-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .sick-item .worker-name i {
            color: var(--accent);
        }

        /* ===== RESPONSIVE STYLES ===== */
        
        /* Tablets & Small Laptops */
        @media (max-width: 1024px) {
            .container { padding: 0 1.2rem; }
            .metrics-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
            .grid-split { gap: 1rem; }
        }

        /* Mobile Landscape & Tablets */
        @media (max-width: 768px) {
            .navbar { padding: 0 1.2rem; height: 70px; }
            body { padding-top: 75px; }
            .nav-brand { font-size: 1.3rem; }
            .nav-links {
                display: none;
                flex-direction: column;
                position: absolute;
                top: 70px;
                right: 1rem;
                background: var(--panel-bg);
                padding: 0.8rem;
                border-radius: 12px;
                min-width: 170px;
                border: 1px solid rgba(255,255,255,0.06);
                box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            }
            .nav-links.open { display: flex; }
            .nav-hamburger { display: block; }
            .nav-links li a { 
                padding: 0.5rem 0.8rem; 
                font-size: 0.9rem;
            }
            .nav-links li a::after { display: none; }
            
            .container { padding: 0 1rem; }
            .panel-header { flex-direction: column; align-items: stretch; gap: 0.8rem; }
            .panel-header h2 { font-size: 1.2rem; }
            .btn { 
                padding: 0.5rem 1rem; 
                font-size: 0.85rem;
                justify-content: center;
            }
            
            .metrics-grid { 
                grid-template-columns: repeat(2, 1fr); 
                gap: 0.8rem; 
            }
            .metric-card { padding: 1rem; }
            .metric-val { font-size: 1.5rem; }
            .metric-title { font-size: 0.75rem; }
            .metric-card i { font-size: 1.8rem; right: 10px; bottom: 10px; }
            
            .grid-split { grid-template-columns: 1fr; gap: 0.8rem; }
            .card { padding: 1rem; }
            .card h3 { font-size: 1rem; }
            
            .table-responsive { margin: 0 -0.8rem; padding: 0 0.8rem; }
            table { min-width: 500px; font-size: 0.85rem; }
            th, td { padding: 0.6rem; }
            
            .footer {
                flex-direction: column;
                padding: 0.6rem 1rem;
                text-align: center;
                gap: 0.3rem;
                height: auto;
            }
            .footer-left { 
                justify-content: center; 
                font-size: 0.75rem;
                gap: 0.5rem;
            }
            .footer-socials { gap: 0.6rem; }
            .footer-socials a { 
                width: 28px; height: 28px; font-size: 0.9rem; 
            }
            .footer-status { font-size: 0.65rem; }
            
            .scroll-top-btn {
                bottom: 100px;
                right: 20px;
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
                box-shadow: 0 6px 30px rgba(72, 202, 228, 0.5);
            }
            .scroll-top-btn::after { display: none; }
            
            .modal-box { padding: 1.5rem; max-width: 95%; }
            
            .sick-item { 
                padding: 0.5rem 0.6rem;
                flex-wrap: wrap;
                gap: 0.3rem;
            }
            .sick-item .worker-name { font-size: 0.9rem; }
        }

        /* Mobile Portrait */
        @media (max-width: 480px) {
            .navbar { padding: 0 0.8rem; height: 60px; }
            body { padding-top: 65px; padding-bottom: 90px; }
            .nav-brand { font-size: 1rem; }
            .nav-brand i { font-size: 1.2rem; }
            .nav-hamburger { font-size: 1.4rem; }
            
            .container { padding: 0 0.6rem; }
            .panel-header h2 { font-size: 1rem; }
            .btn { 
                padding: 0.4rem 0.8rem; 
                font-size: 0.75rem;
                border-radius: 6px;
            }
            .btn i { font-size: 0.8rem; }
            
            .metrics-grid { 
                grid-template-columns: 1fr 1fr; 
                gap: 0.5rem;
            }
            .metric-card { 
                padding: 0.7rem; 
                border-radius: 8px;
            }
            .metric-val { font-size: 1.2rem; }
            .metric-title { font-size: 0.65rem; }
            .metric-card i { 
                font-size: 1.4rem; 
                right: 8px; 
                bottom: 8px; 
            }
            
            .card { 
                padding: 0.8rem; 
                border-radius: 8px;
                margin-bottom: 0.8rem;
            }
            .card h3 { 
                font-size: 0.9rem; 
                padding-bottom: 0.5rem;
                margin-bottom: 0.6rem;
            }
            
            .table-responsive { margin: 0 -0.6rem; padding: 0 0.6rem; }
            table { 
                min-width: 400px; 
                font-size: 0.75rem;
            }
            th, td { 
                padding: 0.4rem 0.5rem; 
            }
            th { font-size: 0.65rem; }
            .badge { 
                font-size: 0.65rem; 
                padding: 0.15rem 0.5rem;
            }
            
            .footer { 
                padding: 0.4rem 0.6rem;
                gap: 0.2rem;
            }
            .footer-left { 
                font-size: 0.65rem;
                gap: 0.3rem;
                flex-wrap: wrap;
                justify-content: center;
            }
            .footer-left span { 
                display: inline-flex;
                align-items: center;
            }
            .footer-socials a { 
                width: 24px; 
                height: 24px; 
                font-size: 0.75rem;
            }
            .footer-status { font-size: 0.55rem; }
            .status-dot { width: 6px; height: 6px; }
            
            .scroll-top-btn {
                bottom: 95px;
                right: 15px;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
                box-shadow: 0 4px 25px rgba(72, 202, 228, 0.6);
                border-width: 2px;
            }
            
            .modal-box { 
                padding: 1.2rem; 
                border-radius: 12px;
                max-width: 98%;
            }
            .modal-box h3 { font-size: 1rem; }
            .form-group { margin-bottom: 0.8rem; }
            .form-group label { font-size: 0.75rem; }
            .form-control { 
                padding: 0.5rem 0.7rem; 
                font-size: 0.85rem;
                border-radius: 6px;
            }
            
            .sick-item { 
                padding: 0.4rem 0.5rem;
                font-size: 0.8rem;
            }
            .sick-item .worker-name { 
                font-size: 0.8rem;
                gap: 0.3rem;
            }
            .sick-item .btn { 
                padding: 0.15rem 0.5rem; 
                font-size: 0.65rem;
            }
            
            .error-message-block { 
                padding: 0.8rem 1rem; 
                font-size: 0.8rem;
            }
        }

        /* Extra Small Devices */
        @media (max-width: 360px) {
            .metrics-grid { grid-template-columns: 1fr 1fr; gap: 0.4rem; }
            .metric-card { padding: 0.5rem; }
            .metric-val { font-size: 1rem; }
            .metric-title { font-size: 0.55rem; }
            .metric-card i { display: none; }
            
            table { min-width: 320px; }
            th, td { padding: 0.3rem 0.4rem; }
            
            .scroll-top-btn {
                bottom: 90px;
                right: 12px;
                width: 42px;
                height: 42px;
                font-size: 1rem;
            }
            
            .nav-brand { font-size: 0.9rem; }
            .nav-links { min-width: 150px; }
            .nav-links li a { font-size: 0.8rem; padding: 0.4rem 0.6rem; }
        }

        /* Small height screens */
        @media (max-height: 600px) {
            .navbar { height: 55px; }
            body { padding-top: 60px; }
            .footer { padding: 0.3rem 0.8rem; }
            .scroll-top-btn { bottom: 70px; width: 40px; height: 40px; font-size: 1rem; }
        }
    </style>
</head>
<body>

<!-- Animated Navbar -->
<nav class="navbar">
    <div class="nav-brand"><i class="fas fa-cubes-pool"></i> ShiftPulse Portal</div>
    <ul class="nav-links" id="navLinks">
        <li><a onclick="switchView('dashboard')" id="tab-dashboard" class="active"><i class="fas fa-gauge-high"></i> <span>Dashboard</span></a></li>
        <li><a onclick="switchView('roster')" id="tab-roster"><i class="fas fa-calendar-week"></i> <span>7-Day Matrix</span></a></li>
        <li><a onclick="switchView('staff')" id="tab-staff"><i class="fas fa-user-tie"></i> <span>Staff</span></a></li>
        <li><a onclick="switchView('demand')" id="tab-demand"><i class="fas fa-chart-gantt"></i> <span>Demand</span></a></li>
        <li><a onclick="switchView('settings')" id="tab-settings"><i class="fas fa-sliders"></i> <span>Settings</span></a></li>
    </ul>
    <div class="nav-hamburger" id="hamburger"><i class="fas fa-bars"></i></div>
</nav>

<div class="container">
    <div id="engine-error-alert" class="error-message-block"></div>
    
    <!-- Dashboard View -->
    <div id="view-dashboard" class="view-panel active">
        <div class="panel-header">
            <h2><i class="fas fa-chart-line"></i> System Analytics Overview</h2>
            <button class="btn" onclick="triggerSolverPipeline()"><i class="fas fa-arrows-spin"></i> Refresh Data</button>
        </div>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-shield-halved"></i> Schedule Coverage</div>
                <div class="metric-val" id="kpi-coverage">--%</div>
                <i class="fas fa-shield-halved"></i>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-money-bill-wave"></i> Projected Payroll</div>
                <div class="metric-val" id="kpi-cost">GHS 0.00</div>
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-scale-balanced"></i> Load Fairness</div>
                <div class="metric-val" id="kpi-load">--</div>
                <i class="fas fa-scale-balanced"></i>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fas fa-circle-exclamation"></i> Rule Violations</div>
                <div class="metric-val" id="kpi-violations" style="color:var(--success)">0</div>
                <i class="fas fa-circle-exclamation"></i>
            </div>
        </div>
        
        <div class="grid-split">
            <div class="card">
                <h3><i class="fas fa-clock-rotate-left" style="color:var(--accent);"></i> Today's Schedule Overview</h3>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Day</th><th>Shift</th><th>Assigned Crew</th></tr></thead>
                        <tbody id="dash-summary-rows"><tr><td colspan="3" style="color:var(--text-muted);text-align:center;padding:1.5rem;"><i class="fas fa-spinner fa-spin"></i> Loading schedule...</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <h3><i class="fas fa-house-medical-flag" style="color:var(--danger);"></i> Absence Exception</h3>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1rem;">Simulate sick calls to trigger CP-SAT re-optimization.</p>
                <div id="dash-sick-list"></div>
            </div>
        </div>
    </div>

    <!-- Roster View -->
    <div id="view-roster" class="view-panel">
        <div class="panel-header">
            <h2><i class="fas fa-calendar-week"></i> 7-Day Workforce Allocation</h2>
            <button class="btn" onclick="triggerSolverPipeline()"><i class="fas fa-arrows-spin"></i> Recalculate</button>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Day</th><th>Shift</th><th>Assigned Staff</th></tr></thead>
                    <tbody id="full-roster-rows"><tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading roster...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Staff View -->
    <div id="view-staff" class="view-panel">
        <div class="panel-header">
            <h2><i class="fas fa-user-tie"></i> Workforce Registry</h2>
            <button class="btn btn-success" onclick="openWorkerModal()"><i class="fas fa-plus"></i> Onboard Staff</button>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Rate</th><th>Max Hrs</th><th>Skills</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($workersList as $w): ?>
                        <tr>
                            <td><code style="color:var(--accent);"><?= htmlspecialchars($w['id']) ?></code></td>
                            <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                            <td>GHS <?= number_format($w['hourly_rate'], 2) ?></td>
                            <td><?= $w['max_hours'] ?>h</td>
                            <td>
                                <?php foreach(array_filter(explode(',', $w['skills'])) as $skill): ?>
                                    <span class="badge badge-accent"><?= htmlspecialchars($skill) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <button class="btn btn-danger" style="padding:0.25rem 0.8rem;font-size:0.75rem;" onclick="deleteWorkerAccount('<?= $w['id'] ?>')"><i class="fas fa-trash-can"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Demand View -->
    <div id="view-demand" class="view-panel">
        <div class="panel-header">
            <h2><i class="fas fa-chart-gantt"></i> Shift Demand Requirements</h2>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Day</th><th>Shift</th><th>Min Staff</th><th>Required Skills</th></tr></thead>
                    <tbody>
                        <?php foreach ($demandList as $d): ?>
                        <tr>
                            <td>Day <?= $d['day_index'] ?></td>
                            <td><strong><?= htmlspecialchars($d['shift_name']) ?></strong></td>
                            <td><span class="badge badge-warning"><?= $d['min_staff'] ?> workers</span></td>
                            <td>
                                <?php 
                                $skills = json_decode($d['required_skills'], true) ?? [];
                                foreach ($skills as $skName => $qty) {
                                    echo "<span class='badge badge-accent'>" . htmlspecialchars($skName) . " ($qty)</span> ";
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Settings View -->
    <div id="view-settings" class="view-panel">
        <div class="panel-header">
            <h2><i class="fas fa-sliders"></i> Global Constraints Configuration</h2>
        </div>
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <form id="settingsForm">
                <div class="form-group">
                    <label><i class="fas fa-clock" style="color:var(--accent);"></i> Standard Shift Duration (Hours)</label>
                    <input type="number" class="form-control" name="shift_hours" value="<?= htmlspecialchars($settingsRaw['shift_hours'] ?? 8) ?>" min="1" max="24">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-moon" style="color:var(--accent);"></i> Night Premium Multiplier</label>
                    <input type="number" step="0.1" class="form-control" name="night_premium" value="<?= htmlspecialchars($settingsRaw['night_premium'] ?? 1.5) ?>" min="1" max="3">
                </div>
                <button type="button" class="btn" onclick="saveSystemSettings()" style="width:100%;justify-content:center;"><i class="fas fa-floppy-disk"></i> Save Settings</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="worker-modal">
    <div class="modal-box">
        <h3 style="margin-bottom:1.5rem;"><i class="fas fa-user-plus" style="color:var(--accent);"></i> Onboard New Staff</h3>
        <form id="workerForm">
            <div class="form-group"><label>Worker ID</label><input type="text" name="id" class="form-control" placeholder="e.g. w10" required></div>
            <div class="form-group"><label>Full Name</label><input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label>Hourly Rate (GHS)</label><input type="number" step="0.1" name="hourly_rate" class="form-control" value="40.0" required></div>
            <div class="form-group"><label>Max Weekly Hours</label><input type="number" name="max_hours" class="form-control" value="40" required></div>
            <div class="form-group"><label>Skills (comma separated)</label><input type="text" name="skills" class="form-control" placeholder="e.g. cashier, security, general"></div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem; flex-wrap:wrap;">
                <button type="button" class="btn btn-danger" style="background:#475569;box-shadow:none;" onclick="closeWorkerModal()">Cancel</button>
                <button type="button" class="btn" onclick="submitWorkerForm()"><i class="fas fa-check"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Scroll to Top Button -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Enhanced Animated Footer -->
<footer class="footer">
    <div class="footer-left">
        <span><i class="fas fa-cubes-pool"></i> ShiftPulse CP-SAT v2.0</span>
        <span style="opacity:0.3;">|</span>
        <span><i class="fas fa-code"></i> UENR Engine</span>
        <span style="opacity:0.3;">|</span>
        <span class="footer-status">
            <span class="status-dot"></span>
            <span id="systemStatus">Operational</span>
        </span>
    </div>
    <div class="footer-socials">
        <a href="#" aria-label="GitHub"><i class="fab fa-github"></i></a>
        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
        <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
        <a href="#" aria-label="Discord"><i class="fab fa-discord"></i></a>
    </div>
</footer>

<script>
    // Mobile Hamburger Toggle
    document.getElementById('hamburger').addEventListener('click', function() {
        document.getElementById('navLinks').classList.toggle('open');
    });
    // Close mobile nav on link click
    document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', () => {
            document.getElementById('navLinks').classList.remove('open');
        });
    });

    // Scroll to Top Button Logic
    const scrollBtn = document.getElementById('scrollTopBtn');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 200) {
            scrollBtn.classList.add('visible');
        } else {
            scrollBtn.classList.remove('visible');
        }
    });
    scrollBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // View Switcher
    function switchView(viewName) {
        document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));
        const viewEl = document.getElementById('view-' + viewName);
        if (viewEl) viewEl.classList.add('active');
        const tabEl = document.getElementById('tab-' + viewName);
        if (tabEl) tabEl.classList.add('active');
        document.getElementById('navLinks').classList.remove('open');
    }

    // Modal Controls
    function openWorkerModal() { document.getElementById('worker-modal').style.display = 'flex'; }
    function closeWorkerModal() { document.getElementById('worker-modal').style.display = 'none'; }

    // Trigger Solver Pipeline
    async function triggerSolverPipeline(sickWorkerId = '') {
        const errorAlert = document.getElementById('engine-error-alert');
        errorAlert.style.display = 'none';
        document.getElementById('dash-summary-rows').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Optimizing...</td></tr>';
        document.getElementById('full-roster-rows').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Generating roster...</td></tr>';
        
        let fd = new FormData();
        fd.append('action', 'get_optimized_roster');
        if(sickWorkerId) fd.append('sick_worker_id', sickWorkerId);

        try {
            let res = await fetch(window.location.href, { method: 'POST', body: fd });
            let data = await res.json();

            if (data.error) {
                errorAlert.innerText = data.error + (data.details ? ' Details: ' + data.details : '');
                errorAlert.style.display = 'block';
                document.getElementById('dash-summary-rows').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--danger);">Failed to load</td></tr>';
                document.getElementById('full-roster-rows').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--danger);">Failed to load</td></tr>';
                return;
            }

            // Update KPIs
            document.getElementById('kpi-coverage').innerText = data.metrics.coveragePct + '%';
            document.getElementById('kpi-cost').innerText = 'GHS ' + data.metrics.totalCost.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('kpi-load').innerText = data.metrics.loadFairnessStdDev;
            const violationsEl = document.getElementById('kpi-violations');
            violationsEl.innerText = data.metrics.hardViolations || 0;
            violationsEl.style.color = data.metrics.hardViolations > 0 ? 'var(--danger)' : 'var(--success)';

            // Render tables
            let dRows = '', fRows = '', workingStaffSet = new Set();
            data.roster.forEach(slot => {
                let badges = slot.workers.map(w => `<span class="badge badge-accent">${w}</span>`).join('') || '<span style="color:var(--danger);font-weight:700;">UNSTAFFED</span>';
                let rowHtml = `<tr><td>Day ${slot.day}</td><td><strong>${slot.shift}</strong></td><td>${badges}</td></tr>`;
                fRows += rowHtml;
                if(slot.day === 0) dRows += rowHtml;
                slot.workers.forEach(w => workingStaffSet.add(w));
            });

            document.getElementById('full-roster-rows').innerHTML = fRows || '<tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--text-muted);">No roster data</td></tr>';
            document.getElementById('dash-summary-rows').innerHTML = dRows || '<tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--text-muted);">No schedule for today</td></tr>';

            // Render sick list
            let sickContainer = document.getElementById('dash-sick-list');
            sickContainer.innerHTML = '';
            if (workingStaffSet.size === 0) {
                sickContainer.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:0.5rem;">No staff available</p>';
            } else {
                Array.from(workingStaffSet).sort().forEach(worker => {
                    sickContainer.innerHTML += `
                        <div class="sick-item">
                            <span class="worker-name"><i class="fas fa-user"></i><strong>${worker}</strong></span>
                            <button class="btn btn-danger" style="padding:0.2rem 0.8rem;font-size:0.75rem;" onclick="triggerSolverPipeline('${worker}')"><i class="fas fa-phone-alt"></i> Sick</button>
                        </div>`;
                });
            }
        } catch (err) {
            errorAlert.innerText = "Platform Runtime Exception: " + err.message;
            errorAlert.style.display = 'block';
        }
    }

    // Worker CRUD
    async function submitWorkerForm() {
        let fd = new FormData(document.getElementById('workerForm'));
        fd.append('action', 'save_worker');
        try {
            await fetch(window.location.href, { method: 'POST', body: fd });
            closeWorkerModal();
            window.location.reload();
        } catch(e) {
            alert('Error saving worker. Please try again.');
        }
    }

    async function deleteWorkerAccount(workerId) {
        if(confirm(`Permanently remove staff account: ${workerId}?`)) {
            let fd = new FormData();
            fd.append('action', 'delete_worker');
            fd.append('id', workerId);
            try {
                await fetch(window.location.href, { method: 'POST', body: fd });
                window.location.reload();
            } catch(e) {
                alert('Error deleting worker. Please try again.');
            }
        }
    }

    async function saveSystemSettings() {
        let fd = new FormData(document.getElementById('settingsForm'));
        fd.append('action', 'save_settings');
        try {
            await fetch(window.location.href, { method: 'POST', body: fd });
            alert('Settings saved successfully!');
            triggerSolverPipeline();
        } catch(e) {
            alert('Error saving settings. Please try again.');
        }
    }

    // Close modal on overlay click
    document.getElementById('worker-modal').addEventListener('click', function(e) {
        if (e.target === this) closeWorkerModal();
    });

    // Initial load
    window.addEventListener('DOMContentLoaded', () => triggerSolverPipeline());
</script>
</body>
</html>