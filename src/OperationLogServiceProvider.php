<?php
declare(strict_types=1);
namespace wise\operationlog;


use wise\support\traits\RegistersMigrations;

use wise\operationlog\command\Anomaly;
use wise\operationlog\command\Archive;
use wise\operationlog\command\Clean;
use wise\operationlog\command\Report;
use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\facade\OperationLog as OperationLogFacade;
use wise\operationlog\listener\OperationLogListener;
use wise\operationlog\manage\LogManager;
use wise\operationlog\middleware\AuditLog;
use wise\support\traits\ServiceTrait;

/**
 * 操作日志服务注册
 *
 * - doRegister(): 合并配置 + Facade 绑定（延迟到首次 make 时）
 * - boot(): 事件监听 + shutdown 兜底 + 命令 + TTL 自动清理（每个请求执行）
 */
class OperationLogServiceProvider extends \think\Service
{

    use \wise\support\traits\RegistersMigrations;
    use ServiceTrait;

    /** @var bool TTL清理检测标记 */
    private static bool $ttlChecked = false;

    /** @var bool shutdown 兜底是否已注册 */
    private static bool $shutdownRegistered = false;
    
    public function register(): void
    {
        $this->mergeConfig('wise-operation-log');

        $this->app->bind(LogManager::class, function () {
            return new LogManager();
        });
        $this->app->bind(OperationLogFacade::class, LogManager::class);

        // 注册 CLI 命令（仅在真正执行 php think ... 时触发）
        $this->commands([
            Clean::class,
            Archive::class,
            Report::class,
            Anomaly::class,
        ]);
    }

    public function boot(): void
    {
        $this->registerMigrations();

        // 后台「操作日志」页面路由（/admin/operation_log/*，配置驱动）
        RouteRegistrar::register();

        // 声明后台菜单（挂靠框架「日志管理」目录）
        $this->registerAdminMenu();

        // 事件监听——必须在每个请求注册
        $this->app->event->listen(
            OperationLogEvent::class,
            OperationLogListener::class
        );

        // shutdown 兜底写入——必须在每个请求注册
        $this->registerShutdownFlush();

        $this->autoCleanup();
    }

    /**
     * 注册 shutdown 兜底写入（ServiceProvider 级别）
     *
     * 当请求没有经过 OperationLog 中间件时（如异常提前终止），
     * 此兜底函数确保缓冲区中的日志仍能被写入。
     * 与中间件中的 registerShutdownFlush() 互不冲突：
     * flush() 内部已有空 buffer 检查，重复调用安全。
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
            // 操作日志兜底写入
            try {
                $buffer = OperationLogListener::getBuffer();
                if (!empty($buffer)) {
                    OperationLogListener::flush();
                }
            } catch (\Throwable $e) {
                trace('OperationLog shutdown save failed: ' . $e->getMessage(), 'error');
            }

            // 审计日志兜底写入（独立 try/catch，与操作日志互不干扰）
            try {
                $auditBuffer = AuditLog::getBuffer();
                if (!empty($auditBuffer)) {
                    AuditLog::flush();
                }
            } catch (\Throwable $e) {
                trace('AuditLog shutdown save failed: ' . $e->getMessage(), 'error');
            }
        });
    }

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

        if (rand(1, 100) !== 1) {
            return;
        }

        try {
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

    /**
     * 声明后台「操作日志」菜单（ExtPoints::MENU_ITEM）
     *
     * 菜单在包内 OperationLogMenuProvider 声明，由宿主
     * app\admin\service\MenuItemSync 幂等落库并自动派生权限。
     * 落库执行：`php think menu:sync`（声明变化时后台也会懒同步）。
     */
    private function registerAdminMenu(): void
    {
        if (!interface_exists(\wise\support\contracts\MenuItemProvider::class)
            || !class_exists(\wise\support\ExtPoint::class)
            || !class_exists(\wise\support\ExtPoints::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\support\ExtPoints::MENU_ITEM,
                'wise-operation-log',
                \wise\operationlog\menu\OperationLogMenuProvider::class
            );
        } catch (\Throwable $e) {
            trace('OperationLog menu register failed: ' . $e->getMessage(), 'error');
        }
    }
}
