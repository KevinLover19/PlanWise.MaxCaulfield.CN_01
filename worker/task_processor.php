<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../services/AI_Service_Enhanced.php';

class TaskProcessor
{
    private PDO $db;
    private AI_Service_Enhanced $aiService;
    private bool $running = true;

    public function __construct()
    {
        $this->db = getPDO();
        $this->aiService = new AI_Service_Enhanced();
    }

    public function run(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $task = $this->getNextTask();
            if ($task) {
                $this->processTask($task);
            } else {
                usleep(500000);
            }
        }
    }

    public function handleSignal(int $signal): void
    {
        $this->running = false;
        echo "Received signal {$signal}, shutting down..." . PHP_EOL;
    }

    private function getNextTask(): ?array
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT * FROM planwise_task_queue WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1 FOR UPDATE");
            $stmt->execute();
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($task) {
                $update = $this->db->prepare("UPDATE planwise_task_queue SET status = 'processing', started_at = NOW() WHERE id = ?");
                $update->execute([$task['id']]);
                $this->db->commit();
                return $task;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('[PlanWise Worker] Failed to fetch task: ' . $e->getMessage());
        }

        return null;
    }

    private function processTask(array $task): void
    {
        try {
            $payload = json_decode($task['payload'] ?? '{}', true) ?: [];
            $start = microtime(true);
            $results = [];

            switch ($task['task_type']) {
                case 'analyze_business_idea':
                    $results = $this->analyzeBusinessIdea($task, $payload);
                    break;
                default:
                    throw new RuntimeException('Unknown task type: ' . $task['task_type']);
            }

            $duration = (int) ((microtime(true) - $start) * 1000);
            $this->completeTask($task, $results, $duration);
        } catch (Throwable $e) {
            error_log('[PlanWise Worker] Task failed: ' . $e->getMessage());
            $this->failTask($task, $e->getMessage());
        }
    }

    private function analyzeBusinessIdea(array $task, array $payload): array
    {
        $input = $payload['input'] ?? [];
        $reportId = $task['report_id'];

        $steps = [
            ['name' => 'market_analysis', 'title' => '市场环境分析', 'prompt' => '请分析目标市场的规模、增长趋势、用户需求和机会点。'],
            ['name' => 'competitor_research', 'title' => '竞争对手研究', 'prompt' => '识别主要竞争对手，比较其产品特性、价格策略和优势劣势。'],
            ['name' => 'user_persona', 'title' => '目标用户画像', 'prompt' => '为该商业想法构建主要目标用户画像，包括动机、痛点与行为特征。'],
            ['name' => 'business_model', 'title' => '商业模式设计', 'prompt' => '设计可持续的盈利模式，说明价值主张、收入来源和成本结构。'],
            ['name' => 'risk_assessment', 'title' => '风险评估', 'prompt' => '分析潜在的市场、运营、技术、法律风险并提出缓解策略。'],
            ['name' => 'financial_forecast', 'title' => '财务预测', 'prompt' => '提供三年期的关键财务指标预估，包括收入、成本与现金流建议。'],
            ['name' => 'marketing_strategy', 'title' => '营销策略', 'prompt' => '制定获客与品牌推广策略，覆盖线上线下渠道组合。'],
            ['name' => 'implementation_plan', 'title' => '实施计划', 'prompt' => '规划阶段性实施路线，包括里程碑、资源配置与评估指标。'],
        ];

        $this->resetReportSteps($reportId, $steps, $task['task_id']);

        $results = [];
        $totalSteps = count($steps);

        foreach ($steps as $index => $step) {
            $this->markStepProcessing($reportId, $step['name']);
            $this->updateTaskProgress($task['id'], $index + 1, $totalSteps, '正在执行：' . $step['title']);

            $prompt = $this->buildStepPrompt($input, $step, $results);
            $stepStart = microtime(true);
            $response = $this->aiService->callWithRetry($prompt['content'], [
                'system' => $prompt['system'],
                'temperature' => 0.65,
            ]);
            $duration = (int) ((microtime(true) - $stepStart) * 1000);

            $content = trim($response['content'] ?? '');
            $wordCount = str_word_count(strip_tags($content));

            $results[$step['name']] = [
                'title' => $step['title'],
                'content' => $content,
            ];

            $this->storeStepResult($reportId, $step, $response, $content, $wordCount, $duration, $task['task_id']);
            usleep(300000); // 300ms pacing
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $step
     * @param array<string,array<string,string>> $previous
     * @return array{system:string,content:string}
     */
    private function buildStepPrompt(array $input, array $step, array $previous): array
    {
        $businessIdea = $input['business_idea'] ?? ($input['business_description'] ?? '');
        $industry = $input['industry'] ?? '未知行业';
        $analysisDepth = $input['analysis_depth'] ?? 'standard';
        $focusAreas = $input['focus_areas'] ?? [];

        $system = "你是一位具备20年经验的商业策略顾问，需要为给定商业构想生成严谨的分析。";
        if ($analysisDepth === 'deep') {
            $system .= ' 输出要包含定量指标、框架工具和实施建议。';
        }

        if (!empty($focusAreas) && is_array($focusAreas)) {
            $system .= ' 用户特别希望深入以下领域：' . implode('、', array_map('strval', $focusAreas)) . '。请在相关部分给予重点讨论。';
        }

        $context = "商业想法：{$businessIdea}\n行业：{$industry}";
        if (!empty($previous)) {
            $context .= "\n\n已完成分析阶段：\n";
            foreach ($previous as $name => $result) {
                $context .= "- {$result['title']}：" . mb_substr(strip_tags($result['content']), 0, 120) . "...\n";
            }
        }

        $content = $context . "\n\n任务：" . $step['prompt'] . "\n请输出条理清晰、可执行的洞察。";

        return [
            'system' => $system,
            'content' => $content,
        ];
    }

    private function resetReportSteps(string $reportId, array $steps, string $taskId): void
    {
        $this->db->prepare('DELETE FROM planwise_report_steps WHERE report_id = ?')->execute([$reportId]);

        $insert = $this->db->prepare('INSERT INTO planwise_report_steps (step_id, report_id, step_number, step_name, step_title, task_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "pending", NOW())');
        foreach ($steps as $index => $step) {
            $insert->execute([
                'step_' . $step['name'] . '_' . bin2hex(random_bytes(4)),
                $reportId,
                $index + 1,
                $step['name'],
                $step['title'],
                $taskId,
            ]);
        }
    }

    private function markStepProcessing(string $reportId, string $stepName): void
    {
        $stmt = $this->db->prepare('UPDATE planwise_report_steps SET status = "processing", started_at = NOW() WHERE report_id = ? AND step_name = ?');
        $stmt->execute([$reportId, $stepName]);
    }

    private function storeStepResult(string $reportId, array $step, array $response, string $content, int $wordCount, int $duration, string $taskId): void
    {
        $stmt = $this->db->prepare('UPDATE planwise_report_steps SET status = "completed", ai_model = ?, ai_response = ?, formatted_content = ?, word_count = ?, processing_time = ?, completed_at = NOW() WHERE report_id = ? AND step_name = ?');
        $stmt->execute([
            $response['provider'] ?? 'unknown',
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $content,
            $wordCount,
            $duration,
            $reportId,
            $step['name'],
        ]);
    }

    private function updateTaskProgress(int $taskId, int $currentStep, int $totalSteps, string $message): void
    {
        $stmt = $this->db->prepare("UPDATE planwise_task_queue SET payload = JSON_SET(COALESCE(payload, JSON_OBJECT()), '$.current_step', ?, '$.total_steps', ?, '$.current_message', ?) WHERE id = ?");
        $stmt->execute([$currentStep, $totalSteps, $message, $taskId]);
    }

    private function completeTask(array $task, array $results, int $duration): void
    {
        $resultPayload = json_encode([
            'steps' => $results,
            'duration_ms' => $duration,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $update = $this->db->prepare('UPDATE planwise_task_queue SET status = "completed", result = ?, completed_at = NOW() WHERE id = ?');
        $update->execute([$resultPayload, $task['id']]);

        $this->db->prepare('UPDATE planwise_reports_v2 SET status = "completed", updated_at = NOW(), completed_at = NOW() WHERE report_id = ?')->execute([$task['report_id']]);
    }

    private function failTask(array $task, string $error): void
    {
        $update = $this->db->prepare('UPDATE planwise_task_queue SET status = "failed", error_message = ?, completed_at = NOW(), retry_count = retry_count + 1 WHERE id = ?');
        $update->execute([mb_substr($error, 0, 1000), $task['id']]);

        $this->db->prepare('UPDATE planwise_reports_v2 SET status = "failed", updated_at = NOW() WHERE report_id = ?')->execute([$task['report_id']]);
    }
}

$processor = new TaskProcessor();
$processor->run();
