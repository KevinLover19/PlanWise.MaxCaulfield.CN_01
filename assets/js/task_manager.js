class TaskManager {
    constructor(options = {}) {
        this.pollInterval = options.pollInterval || 2000;
        this.maxPollTime = options.maxPollTime || 10 * 60 * 1000;
        this.activeTasks = new Map();
        this.callbacks = {
            onProgress: options.onProgress || function () {},
            onComplete: options.onComplete || function () {},
            onError: options.onError || function () {},
            onTimeout: options.onTimeout || function () {},
        };
    }

    async createTask(taskType, data) {
        const taskId = this.generateTaskId();
        const csrf = this.getCsrfToken();

        const response = await fetch('/api/task/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
            },
            body: JSON.stringify({
                task_id: taskId,
                type: taskType,
                data,
            }),
        });

        if (!response.ok) {
            const text = await response.text();
            throw new Error(text || '任务创建失败');
        }

        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.message || '任务创建失败');
        }

        this.startPolling(taskId);
        return { taskId, reportId: payload.report_id || null };
    }

    startPolling(taskId) {
        const start = Date.now();
        const poll = async () => {
            try {
                if (Date.now() - start > this.maxPollTime) {
                    this.clearTask(taskId);
                    this.callbacks.onTimeout(taskId);
                    return;
                }

                const response = await fetch(`/api/task/status.php?task_id=${encodeURIComponent(taskId)}`);
                if (!response.ok) {
                    throw new Error('任务状态查询失败');
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || '任务状态查询失败');
                }

                this.callbacks.onProgress(taskId, data);

                if (data.status === 'completed') {
                    this.clearTask(taskId);
                    this.callbacks.onComplete(taskId, data);
                } else if (data.status === 'failed') {
                    this.clearTask(taskId);
                    this.callbacks.onError(taskId, data);
                } else {
                    const timer = setTimeout(poll, this.pollInterval);
                    this.activeTasks.set(taskId, timer);
                }
            } catch (error) {
                console.error('Task polling error:', error);
                const timer = setTimeout(poll, Math.min(this.pollInterval * 2, 10000));
                this.activeTasks.set(taskId, timer);
            }
        };

        poll();
    }

    clearTask(taskId) {
        const timer = this.activeTasks.get(taskId);
        if (timer) {
            clearTimeout(timer);
            this.activeTasks.delete(taskId);
        }
    }

    handleTimeout(taskId) {
        this.clearTask(taskId);
        this.callbacks.onTimeout(taskId);
    }

    getCsrfToken() {
        const element = document.querySelector('meta[name="csrf-token"]');
        return element ? element.getAttribute('content') : null;
    }

    generateTaskId() {
        return `task_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }
}

export default TaskManager;
