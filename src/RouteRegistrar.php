<?php
declare(strict_types=1);

namespace wise\operationlog;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * WiseOperationLog 路由注册器（配置驱动）
 *
 * 由 OperationLogServiceProvider::boot() 调用 RouteRegistrar::register() 注册，
 * 前缀 / 中间件通过 config('wise-operation-log.web') 配置。
 *
 * 仅注册后台「操作日志」页面路由（/admin/operation_log/*）：分组前缀 'admin'
 * （见 config 的 web.groups.admin_operation_log），由基类投递到 ROUTE_ADMIN，
 * 在 admin 应用内以相对前缀 '' 落地，与菜单声明
 * （wise\operationlog\menu\OperationLogMenuProvider）一一对应；卸载本包后
 * 路由与菜单一并消失，宿主不再硬编码。
 *
 * @package wise\operationlog
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-operation-log';

    protected function define(array $config): void
    {
        if (!class_exists(\app\admin\controller\BaseController::class)) {
            return;
        }

        $this->group('admin_operation_log', function () {
            Route::rule('operation_log/index', '\wise\operationlog\controller\OperationLog@index');
            Route::rule('operation_log/getList', '\wise\operationlog\controller\OperationLog@getList');
            Route::rule('operation_log/preview', '\wise\operationlog\controller\OperationLog@preview');
            Route::rule('operation_log/dashboard', '\wise\operationlog\controller\OperationLog@dashboard');
            Route::post('operation_log/clearLog', '\wise\operationlog\controller\OperationLog@clearLog');
        });
    }
}
