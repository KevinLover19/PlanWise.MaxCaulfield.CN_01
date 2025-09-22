<?php
// /www/wwwroot/planwise.maxcaulfield.cn/view_report.php
// 同步主站blog.php的样式风格，并扩展以支持异步生成的V2报告

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

$legacyId = $_GET['id'] ?? null;
$reportKey = $_GET['report_id'] ?? null;
$user_id = $_SESSION['user_id'];

$isV2Report = false;
$reportV2 = null;
$legacyReport = null;
$reportTask = null;

if (!$legacyId && !$reportKey) {
    header('Location: /dashboard.php');
    exit();
}

if ($reportKey) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM planwise_reports_v2 WHERE report_id = ? AND (user_id IS NULL OR user_id = ?)");
        $stmt->execute([$reportKey, $user_id]);
        $reportV2 = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reportV2) {
            $isV2Report = true;
            $taskStmt = $pdo->prepare("SELECT status, result, error_message FROM planwise_task_queue WHERE report_id = ? ORDER BY created_at DESC LIMIT 1");
            $taskStmt->execute([$reportKey]);
            $reportTask = $taskStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (PDOException $e) {
        error_log('View report v2 error: ' . $e->getMessage());
    }
}

if (!$isV2Report) {
    if (!$legacyId) {
        header('Location: /dashboard.php');
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$legacyId, $user_id]);
        $legacyReport = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$legacyReport) {
            header('Location: /dashboard.php?error=report_not_found');
            exit();
        }
    } catch (PDOException $e) {
        error_log("View report error: " . $e->getMessage());
        header('Location: /dashboard.php?error=database_error');
        exit();
    }
}

// SEO设置
if ($isV2Report) {
    $page_actual_title = htmlspecialchars($reportV2['title']) . ' - 商业策略分析报告 - PlanWise AI';
    $meta_description = '查看 ' . htmlspecialchars($reportV2['title']) . ' 的多阶段AI商业策略分析。';
    $meta_keywords = '商业策略报告,市场分析,竞争分析,' . htmlspecialchars($reportV2['industry'] ?? '') . ',PlanWise';
} else {
    $page_actual_title = htmlspecialchars($legacyReport['business_name']) . ' - 商业策略分析报告 - PlanWise AI';
    $meta_description = '查看 ' . htmlspecialchars($legacyReport['business_name']) . ' 的详细商业策略分析报告，包含市场分析、竞争对手研究、用户画像、商业模式等8个维度的专业分析。';
    $meta_keywords = '商业策略报告,市场分析,竞争分析,' . htmlspecialchars($legacyReport['industry']) . ',PlanWise';
}

$displayTitle = $isV2Report ? ($reportV2['title'] ?? 'AI商业策略分析') : $legacyReport['business_name'];
$displayIndustry = $isV2Report ? ($reportV2['industry'] ?? '未指定行业') : $legacyReport['industry'];
$displayCreatedAt = $isV2Report ? ($reportV2['created_at'] ?? date('Y-m-d H:i:s')) : $legacyReport['created_at'];
$displayStatus = $isV2Report ? ($reportV2['status'] ?? 'draft') : $legacyReport['status'];

$statusMap = [
    'draft' => ['label' => '草稿', 'class' => 'bg-gray-500'],
    'processing' => ['label' => '分析中', 'class' => 'bg-yellow-500'],
    'analyzing' => ['label' => '分析中', 'class' => 'bg-yellow-500'],
    'completed' => ['label' => '已完成', 'class' => 'bg-green-500'],
    'failed' => ['label' => '失败', 'class' => 'bg-red-500'],
];
$statusInfo = $statusMap[$displayStatus] ?? $statusMap['draft'];

$v2Steps = [];
if ($isV2Report) {
    try {
        $stepsStmt = $pdo->prepare("SELECT step_number, step_title, formatted_content, status FROM planwise_report_steps WHERE report_id = ? ORDER BY step_number ASC");
        $stepsStmt->execute([$reportV2['report_id']]);
        $v2Steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('View report steps error: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="container mx-auto px-4 sm:px-6 lg:px-8 mt-8 mb-8">
    <!-- 返回按钮 -->
    <div class="mb-8 fade-in">
        <a href="/dashboard.php" class="inline-flex items-center text-[var(--text-secondary)] hover:text-[var(--text-accent)] transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            返回控制台
        </a>
    </div>

    <!-- 报告标题 -->
    <div class="text-center mb-12 stagger-fade">
        <h1 class="text-4xl md:text-5xl font-bold mb-6 fade-in">
            <span class="text-gradient"><?php echo htmlspecialchars($displayTitle); ?></span>
        </h1>
        <div class="flex items-center justify-center space-x-6 text-sm text-[var(--text-secondary)] fade-in">
            <span><i class="fas fa-industry mr-2"></i><?php echo htmlspecialchars($displayIndustry); ?></span>
            <span><i class="fas fa-calendar mr-2"></i><?php echo date('Y年m月d日', strtotime($displayCreatedAt)); ?></span>
            <span class="px-3 py-1 text-white rounded-full text-xs <?php echo $statusInfo['class']; ?>">
                <i class="fas fa-circle mr-1"></i><?php echo htmlspecialchars($statusInfo['label']); ?>
            </span>
        </div>
    </div>

    <!-- 报告内容 -->
    <div class="max-w-4xl mx-auto">
        <?php if ($isV2Report): ?>
            <?php if ($displayStatus === 'completed'): ?>
                <?php if (!empty($v2Steps)): ?>
                    <div class="space-y-8">
                        <?php foreach ($v2Steps as $step): ?>
                            <div class="glass-effect p-8 fade-in">
                                <div class="flex items-center mb-6">
                                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mr-4">
                                        <i class="fas fa-chart-line text-white text-xl"></i>
                                    </div>
                                    <h2 class="text-2xl md:text-3xl font-semibold text-[var(--text-primary)]">
                                        第<?php echo (int) $step['step_number']; ?>步 · <?php echo htmlspecialchars($step['step_title']); ?>
                                    </h2>
                                </div>
                                <div class="prose prose-lg max-w-none text-[var(--text-primary)] leading-relaxed">
                                    <?php echo nl2br(htmlspecialchars($step['formatted_content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="glass-effect p-8 fade-in text-center">
                        <h3 class="text-xl font-semibold text-[var(--text-primary)] mb-4">报告内容已生成</h3>
                        <p class="text-[var(--text-secondary)]">当前暂无分步详情，请稍后刷新或重新生成报告。</p>
                    </div>
                <?php endif; ?>
            <?php elseif (in_array($displayStatus, ['analyzing', 'processing', 'draft'], true)): ?>
                <div class="glass-effect p-12 text-center fade-in">
                    <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-6 animate-spin">
                        <i class="fas fa-cog text-2xl text-white"></i>
                    </div>
                    <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告生成中...</h3>
                    <p class="text-[var(--text-secondary)] mb-8">AI正在持续输出分析结果，您可以稍后刷新查看最新内容。</p>
                    <div class="flex justify-center space-x-4">
                        <button onclick="location.reload()" class="btn-primary px-6 py-3 rounded-lg">
                            <i class="fas fa-sync-alt mr-2"></i>刷新页面
                        </button>
                        <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                            返回控制台
                        </a>
                    </div>
                </div>
            <?php elseif ($displayStatus === 'failed'): ?>
                <div class="glass-effect p-12 text-center fade-in">
                    <div class="w-16 h-16 bg-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-exclamation-triangle text-2xl text-white"></i>
                    </div>
                    <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告生成失败</h3>
                    <p class="text-[var(--text-secondary)] mb-6">
                        <?php echo htmlspecialchars($reportTask['error_message'] ?? '生成过程中出现未知错误，请稍后重试。'); ?>
                    </p>
                    <div class="flex justify-center space-x-4">
                        <a href="/create_report.php" class="btn-primary px-6 py-3 rounded-lg">
                            <i class="fas fa-redo mr-2"></i>重新生成
                        </a>
                        <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                            返回控制台
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="glass-effect p-12 text-center fade-in">
                    <div class="w-16 h-16 bg-gray-500 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-question text-2xl text-white"></i>
                    </div>
                    <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告状态未知</h3>
                    <p class="text-[var(--text-secondary)] mb-8">状态暂不可用，请联系技术支持或稍后重试。</p>
                    <div class="flex justify-center space-x-4">
                        <a href="/create_report.php" class="btn-primary px-6 py-3 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>创建新报告
                        </a>
                        <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                            返回控制台
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($legacyReport['status'] === 'completed' && !empty($legacyReport['analysis_result'])): ?>
                <div class="space-y-8">
                    <?php
                    $analysis_data = json_decode($legacyReport['analysis_result'], true);
                    if ($analysis_data && is_array($analysis_data)):
                    ?>
                        <?php foreach ($analysis_data as $section_key => $section_data): ?>
                            <div class="glass-effect p-8 fade-in">
                                <div class="flex items-center mb-6">
                                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mr-4">
                                        <i class="fas fa-chart-line text-white text-xl"></i>
                                    </div>
                                    <h2 class="text-2xl md:text-3xl font-semibold text-[var(--text-primary)]">
                                        <?php echo htmlspecialchars($section_data['title'] ?? $section_key); ?>
                                    </h2>
                                </div>
                                <div class="prose prose-lg max-w-none text-[var(--text-primary)]">
                                    <?php if (isset($section_data['content'])): ?>
                                        <?php echo nl2br(htmlspecialchars($section_data['content'])); ?>
                                    <?php else: ?>
                                        <?php echo nl2br(htmlspecialchars($section_data)); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="glass-effect p-8 fade-in">
                            <div class="prose prose-lg max-w-none text-[var(--text-primary)]">
                                <pre class="whitespace-pre-wrap"><?php echo htmlspecialchars($legacyReport['analysis_result']); ?></pre>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="glass-effect p-12 text-center fade-in">
                    <?php if ($legacyReport['status'] === 'processing'): ?>
                        <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-6 animate-pulse">
                            <i class="fas fa-cog fa-spin text-2xl text-white"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告生成中...</h3>
                        <p class="text-[var(--text-secondary)] mb-8">AI正在为您生成详细的商业策略分析报告，请稍候。</p>
                        <div class="flex justify-center space-x-4">
                            <button onclick="location.reload()" class="btn-primary px-6 py-3 rounded-lg">
                                <i class="fas fa-sync-alt mr-2"></i>刷新页面
                            </button>
                            <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                                返回控制台
                            </a>
                        </div>
                    <?php elseif ($legacyReport['status'] === 'failed'): ?>
                        <div class="w-16 h-16 bg-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-exclamation-triangle text-2xl text-white"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告生成失败</h3>
                        <p class="text-[var(--text-secondary)] mb-8">很抱歉，报告生成过程中出现了问题。您可以尝试重新生成。</p>
                        <div class="flex justify-center space-x-4">
                        <a href="/create_report.php" class="btn-primary px-6 py-3 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>重新生成
                            </a>
                            <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                                返回控制台
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="w-16 h-16 bg-gray-500 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-question text-2xl text-white"></i>
                        </div>
                        <h3 class="text-2xl font-semibold text-[var(--text-primary)] mb-4">报告状态未知</h3>
                        <p class="text-[var(--text-secondary)] mb-8">报告状态异常，请联系技术支持或重新生成报告。</p>
                        <div class="flex justify-center space-x-4">
                        <a href="/create_report.php" class="btn-primary px-6 py-3 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>重新生成
                            </a>
                            <a href="/dashboard.php" class="px-6 py-3 border border-[var(--border-color)] text-[var(--text-accent)] rounded-lg hover:bg-[var(--bg-glass)] transition-all">
                                返回控制台
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($displayStatus === 'completed'): ?>
            <div class="mt-12 flex justify-center space-x-4 fade-in">
                <button onclick="printReport()" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fas fa-print mr-2"></i>打印报告
                </button>
                <button onclick="downloadReport()" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fas fa-download mr-2"></i>下载PDF
                </button>
                <button onclick="shareReport()" class="px-6 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors">
                    <i class="fas fa-share mr-2"></i>分享报告
                </button>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
function printReport() {
    window.print();
}

function downloadReport() {
    alert('PDF下载功能正在开发中...');
}

function shareReport() {
    const shareData = {
        title: '<?php echo htmlspecialchars($displayTitle); ?> - 商业策略分析报告',
        text: '查看这份由PlanWise AI生成的商业策略分析报告',
        url: window.location.href
    };

    if (navigator.share) {
        navigator.share(shareData);
    } else {
        navigator.clipboard.writeText(shareData.url).then(() => {
            alert('报告链接已复制到剪贴板');
        });
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
