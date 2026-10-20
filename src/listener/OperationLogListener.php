<?php

declare(strict_types=1);

namespace wise\operationlog\listener;

use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\event\OperationLogSavingEvent;
use wise\operationlog\storage\DatabaseStorage;
use wise\operationlog\storage\StorageInterface;

/**
 * 操作日志事件监听器
 *
 * 优化设计：
 * - 缓冲模式：先缓冲所有日志，在请求结束时由中间件 flush() 批量写入
 * - 存储驱动缓存：避免每次写入都实例化驱动
 * - 批量写入：DatabaseStorage 支持 insertAll() 提升性能
 * - 保存前事件：写入前派发 OperationLogSavingEvent 允许修改
 * - #10 分段刷新：缓冲区达到上限时分段刷新，防止 OOM
 * - #10 队列批量：整批日志作为一个队列任务推送
 */
class OperationLogListener
{
    /**
     * Swoole 协程上下文中的缓冲区键名
     */
    private const COROUTINE_KEY = '__oplog_buffer';

    /**
     * 日志缓冲区（单次请求内的所有日志）
     * @var array<int, array<string, mixed>>
     */
    protected static array $buffer = [];

    /**
     * 缓存的存储驱动实例
     * @var StorageInterface|null
     */
    protected static ?StorageInterface $storageInstance = null;

    /**
     * #10 单次请求缓冲区最大条数（触发分段刷新）
     */
    protected static int $maxBufferSize = 500;

    /**
     * #10 分段刷新阈值是否已加载
     */
    protected static bool $configLoaded = false;

    /**
     * 处理操作日志事件（缓冲模式）
     * 不再直接写入，而是缓冲到 $buffer，由中间件 finally 块中调用 flush() 批量写入
     *
     * @param OperationLogEvent $event 操作日志事件
     */
    public function handle(OperationLogEvent $event): void
    {
        // Swoole 协程环境下用协程上下文隔离，避免跨协程串号（与 LogContext 保持一致）
        if (self::inCoroutine()) {
            $ctx      = \Swoole\Coroutine::getContext();
            $buffer   = $ctx[self::COROUTINE_KEY] ?? [];
            $buffer[] = $event->logData;
            $ctx[self::COROUTINE_KEY] = $buffer;

            // #10 缓冲区达到阈值时分段刷新
            if (count($buffer) >= self::getMaxBufferSize()) {
                self::flushChunk();
            }
            return;
        }

        self::$buffer[] = $event->logData;

        // #10 缓冲区达到阈值时分段刷新
        if (count(self::$buffer) >= self::getMaxBufferSize()) {
            self::flushChunk();
        }
    }

    /**
     * 刷新缓冲区，批量写入所有日志
     * 由 OperationLog::handle() 的 finally 块调用
     */
    public static function flush(): void
    {
        $logs = self::takeBuffer();
        if (empty($logs)) {
            return;
        }

        $queueConfig = config('wise-operation-log.queue', []);
        $queueEnable = $queueConfig['enable'] ?? false;
        $queueBatch = config('wise-operation-log.buffer.queue_batch', true);

        if ($queueEnable && class_exists(\think\queue\Queue::class)) {
            // #10 队列模式优化：整批作为一个队列任务
            $queueName = $queueConfig['queue_name'] ?? 'operation_log';

            if ($queueBatch) {
                try {
                    \think\facade\Queue::push(self::class, ['batch' => $logs], $queueName);
                } catch (\Throwable $e) {
                    trace('OperationLog queue batch push failed, fallback to sync: ' . $e->getMessage(), 'error');
                    self::syncSave($logs);
                }
            } else {
                // 兼容旧行为：逐条推送
                foreach ($logs as $log) {
                    try {
                        \think\facade\Queue::push(self::class, $log, $queueName);
                    } catch (\Throwable $e) {
                        trace('OperationLog queue push failed, fallback to sync: ' . $e->getMessage(), 'error');
                        self::syncSave([$log]);
                    }
                }
            }
        } else {
            // 同步批量写入
            self::syncSave($logs);
        }
    }

    /**
     * 队列消费入口
     * 当使用 think-queue 时，此方法作为队列任务的执行入口
     *
     * #10 兼容单条和批量两种格式
     *
     * @param mixed $job    队列任务（think\queue\Job 实例）
     * @param array $data   日志数据（单条格式 or ['batch' => [...]] 批量格式）
     */
    public function fire(mixed $job, array $data): void
    {
        try {
            // #10：兼容批量格式
            if (isset($data['batch']) && is_array($data['batch'])) {
                self::syncSave($data['batch']);
            } else {
                self::syncSave([$data]);
            }
            $job->delete();
        } catch (\Throwable $e) {
            trace('OperationLog queue job failed: ' . $e->getMessage(), 'error');
            if ((int) $job->attempts() < 3) {
                $job->release(60);
            } else {
                $job->delete();
            }
        }
    }

    /**
     * #10 获取当前缓冲区（供外部 shutdown 兜底使用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBuffer(): array
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return $ctx[self::COROUTINE_KEY] ?? [];
        }

        return self::$buffer;
    }

    // ==========================================
    //  内部方法
    // ==========================================

    /**
     * 取出并清空当前缓冲区
     *
     * Swoole 协程环境下读写协程上下文，避免多个协程共用静态缓冲区导致
     * 日志被其它协程 flush 走（操作者串号 / 日志丢失）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function takeBuffer(): array
    {
        if (self::inCoroutine()) {
            $ctx  = \Swoole\Coroutine::getContext();
            $logs = $ctx[self::COROUTINE_KEY] ?? [];
            unset($ctx[self::COROUTINE_KEY]);

            return $logs;
        }

        $logs = self::$buffer;
        self::$buffer = [];

        return $logs;
    }

    /**
     * 判断是否运行在 Swoole 协程中
     */
    protected static function inCoroutine(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * #10 分段刷新：清空当前缓冲区并写入，不清空 storage instance
     */
    protected static function flushChunk(): void
    {
        $logs = self::takeBuffer();
        if (empty($logs)) {
            return;
        }

        self::syncSave($logs);
    }

    /**
     * 获取缓冲区最大条数（从配置加载，仅一次）
     */
    protected static function getMaxBufferSize(): int
    {
        if (!self::$configLoaded) {
            $bufferConfig = config('wise-operation-log.buffer', []);
            self::$maxBufferSize = (int) ($bufferConfig['max_size'] ?? 500);
            self::$configLoaded = true;
        }
        return self::$maxBufferSize;
    }

    /**
     * 同步保存日志数据
     *
     * 写入前派发 OperationLogSavingEvent，允许用户修改日志数据（如追加 extra 字段）。
     *
     * @param array<int, array<string, mixed>> $logs 日志数据列表
     */
    protected static function syncSave(array $logs): void
    {
        // 为每条日志派发保存前事件；监听器异常不得影响日志写入，更不能击穿业务请求
        foreach ($logs as &$log) {
            try {
                $savingEvent = new OperationLogSavingEvent($log);
                event($savingEvent);
                $log = $savingEvent->logData;
            } catch (\Throwable $e) {
                trace('OperationLog saving event listener failed: ' . $e->getMessage(), 'error');
            }
        }
        unset($log);

        $storage = self::getStorage();

        // #7 批量写入优化：DatabaseStorage 支持 insertAll
        if ($storage instanceof DatabaseStorage && count($logs) > 1) {
            $storage->batchSave($logs);
        } else {
            foreach ($logs as $log) {
                $storage->save($log);
            }
        }
    }

    /**
     * #5 获取缓存的存储驱动实例
     */
    protected static function getStorage(): StorageInterface
    {
        if (self::$storageInstance === null) {
            $storageDriver = config('wise-operation-log.storage.driver', DatabaseStorage::class);
            if (class_exists($storageDriver)) {
                self::$storageInstance = app($storageDriver);
            } else {
                trace('OperationLog storage driver not found: ' . $storageDriver, 'error');
                // 降级为 DatabaseStorage
                self::$storageInstance = app(DatabaseStorage::class);
            }
        }

        return self::$storageInstance;
    }

    /**
     * 重置存储驱动实例（用于测试或配置热更新场景）
     */
    public static function resetStorage(): void
    {
        self::$storageInstance = null;
    }
}
