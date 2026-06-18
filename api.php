<?php
header('Content-Type: application/json');

$inputFile = 'sample_input.json';

// Handle incoming requests
$action = $_GET['action'] ?? 'get_roster';

if ($action === 'call_sick') {
    // Read the incoming payload containing the worker to remove
    // $postData = json_json = json_decode(file_get_contents('php://input'), true);
    // Old broken line:
    $postData = json_json = json_decode(file_get_contents('php://input'), true);
    $sickWorkerId = $postData['workerId'] ?? null;

    if (!$sickWorkerId) {
        echo json_encode(['error' => 'Missing worker ID']);
        exit;
    }

    // Load original data to modify dynamically (Live Re-optimization Bonus)
    $data = json_decode(file_get_contents($inputFile), true);
    
    // Filter out the sick worker from the workers pool
    $data['workers'] = array_values(array_filter($data['workers'], function($w) use ($sickWorkerId) {
        return $w['id'] !== $sickWorkerId;
    }));

    // Save out a temporary runtime input file for execution
    $runFile = 'runtime_input.json';
    file_put_contents($runFile, json_encode($data));
} else {
    // Standard execution using the base sample input
    $runFile = $inputFile;
}

// Execute the Python CP-SAT Engine built in Phase 2
$command = escapeshellcmd("python3 shift_pulse_engine.py " . escapeshellarg($runFile));
$output = shell_exec($command);

// If using a temporary file, clean it up
if (isset($runFile) && $runFile === 'runtime_input.json' && file_exists($runFile)) {
    unlink($runFile);
}

// Echo the pure JSON string directly back to the frontend dashboard
echo $output;
?>