<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (function_exists('generate_csrf_token')) {
    $expected = $_SESSION['csrf_token'] ?? generate_csrf_token();
    if ($expected && $csrfHeader && !hash_equals($expected, $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token mismatch']);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$taskId = $input['task_id'] ?? '';
$taskType = $input['type'] ?? '';
$data = $input['data'] ?? [];

if (!$taskId || !$taskType) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing task metadata']);
    exit;
}

$pdo = getPDO();

$userId = $_SESSION['user_id'] ?? null;
$businessIdea = trim($data['business_idea'] ?? ($data['business_description'] ?? ''));
if (strlen($businessIdea) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '商业想法描述太短']);
    exit;
}

$analysisDepth = $data['analysis_depth'] ?? 'standard';
$industry = $data['industry'] ?? '';
$title = $data['title'] ?? ($data['business_name'] ?? '未命名商业策略');
$reportId = $data['report_id'] ?? ('rpt_' . bin2hex(random_bytes(6)));

try {
    $pdo->beginTransaction();

    $reportStmt = $pdo->prepare("INSERT INTO planwise_reports_v2 (report_id, user_id, task_id, title, business_idea, industry, analysis_depth, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'analyzing')");
    $reportStmt->execute([
        $reportId,
        $userId,
        $taskId,
        $title,
        $businessIdea,
        $industry,
        $analysisDepth,
    ]);

    $payload = [
        'input' => $data,
        'current_step' => 0,
        'total_steps' => 8,
        'current_message' => '等待执行',
    ];

    $queueStmt = $pdo->prepare("INSERT INTO planwise_task_queue (task_id, user_id, report_id, task_type, status, priority, payload, created_at) VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())");
    $queueStmt->execute([
        $taskId,
        $userId,
        $reportId,
        $taskType,
        $data['priority'] ?? 5,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'report_id' => $reportId,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[PlanWise] Task creation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '任务创建失败']);
}
