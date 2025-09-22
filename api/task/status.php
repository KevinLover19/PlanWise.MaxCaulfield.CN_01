<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$taskId = $_GET['task_id'] ?? '';
if (!$taskId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing task_id']);
    exit;
}

$pdo = getPDO();

$stmt = $pdo->prepare('SELECT * FROM planwise_task_queue WHERE task_id = ? LIMIT 1');
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
if ($task['user_id'] && $userId && (int) $task['user_id'] !== (int) $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$payload = json_decode($task['payload'] ?? '{}', true) ?: [];
$result = json_decode($task['result'] ?? '{}', true) ?: [];

$stepsStmt = $pdo->prepare('SELECT step_number, step_name, step_title, status, formatted_content FROM planwise_report_steps WHERE report_id = ? ORDER BY step_number ASC');
$stepsStmt->execute([$task['report_id']]);
$steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

$partial = [];
foreach ($steps as $step) {
    $partial[] = [
        'step_number' => (int) $step['step_number'],
        'step_name' => $step['step_name'],
        'title' => $step['step_title'],
        'status' => $step['status'],
        'content' => $step['formatted_content'],
    ];
}

$response = [
    'success' => true,
    'status' => $task['status'],
    'task_id' => $taskId,
    'report_id' => $task['report_id'],
    'current_step' => $payload['current_step'] ?? 0,
    'total_steps' => $payload['total_steps'] ?? max(8, count($partial)),
    'current_message' => $payload['current_message'] ?? '',
    'partial_result' => $partial,
    'updated_at' => $task['completed_at'] ?? $task['started_at'] ?? $task['created_at'],
];

if ($task['status'] === 'completed') {
    $response['result'] = $result;
}

if ($task['status'] === 'failed') {
    $response['error'] = $task['error_message'];
}

echo json_encode($response);
