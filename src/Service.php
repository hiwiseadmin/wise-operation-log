<?php
declare(strict_types=1);
namespace wise\operationlog;

use wise\operationlog\command\Anomaly;
use wise\operationlog\command\Archive;
use wise\operationlog\command\Clean;
use wise\operationlog\command\Publish;
use wise\operationlog\command\Report;
use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\facade\OperationLog as OperationLogFacade;
use wise\operationlog\listener\OperationLogListener;
use wise\operationlog\manage\LogManager;
use wise\operationlog\trait\ServiceTrait;

/**
 * 操作日志服务注册
 *
 * - register(): 合并配置 + 事件监听 + Facade 绑定 + shutdown 兜底
 * - boot(): 注册命令 + TTL自动清理
 */
class Service extends \think\Service
{
    use ServiceTrait;

    /** @var bool TTL清理检测标记 */
    private static bool $ttlChecked = false;

    /** @var bool shutdown 兜底是否已注册 */
    private static bool $shutdownRegistered = false;

    /**
     * 注册服务
     */
    public function register(): void
    {
        // 合并配置文件
        $this->mergeConfig('wise-operation-log');

        // 注册事件监听
        $this->app->event->listen(
            OperationLogEvent::class,
            OperationLogListener::class
        );

        // 绑定 LogManager 到容器（Facade 使用）
        $this->app->bind(LogManager::class, function () {
            return new LogManager();
        });

        // 绑定 Facade 别名
        $this->app->bind(OperationLogFacade::class, LogManager::class);

        // #10 注册 shutdown 兜底写入
        $this->registerShutdownFlush();
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        // 仅在控制台环境下注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
                Clean::class,
                Archive::class,
                Report::class,
                Anomaly::class,
            ]);
        }

        $this->autoCleanup();
    }

    /**
     * #10 注册 shutdown 兜底写入
     *
     * 确保进程异常退出时缓冲区日志也能写入。
     * 受限于 PHP 机制，SIGKILL/E_ERROR 无法捕获，但可覆盖绝大多数场景。
     */
    private function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        $shutdownSave = config('wise-operation-log.buffer.shutdown_save', true);
        if (!$shutdownSave) {
            return;
        }

        register_shutdown_function(function () {
            try {
                $buffer = OperationLogListener::getBuffer();
                if (!empty($buffer)) {
                    OperationLogListener::flush();
                }
            } catch (\Throwable $e) {
                trace('OperationLog shutdown save failed: ' . $e->getMessage(), 'error');
            }
        });
    }

    /**
     * #6 TTL 自动清理
     * 概率触发（1%），避免每个请求都执行
     */
    private function autoCleanup(): void
    {
        if (self::$ttlChecked) {
            return;
        }
        self::$ttlChecked = true;

        $ttlConfig = config('wise-operation-log.ttl', []);
        $autoCleanup = $ttlConfig['auto_cleanup'] ?? false;
        $days = $ttlConfig['days'] ?? 0;

        if (!$autoCleanup || $days <= 0) {
            return;
        }

        // 概率触发（1%），减少数据库压力
        if (rand(1, 100) !== 1) {
            return;
        }

        try {
            /** @var LogManager $manager */
            $manager = app(LogManager::class);
            $maxRecords = $ttlConfig['max_records'] ?? 0;
            $deleted = $manager->retain($days, $maxRecords);

            if ($deleted > 0) {
                trace(sprintf('OperationLog auto-cleanup: deleted %d records (retain %d days)', $deleted, $days), 'info');
            }
        } catch (\Throwable $e) {
            trace('OperationLog auto-cleanup failed: ' . $e->getMessage(), 'error');
        }
    }

}
