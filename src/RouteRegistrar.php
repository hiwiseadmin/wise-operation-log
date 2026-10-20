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
        // 依赖 WiseAdmin 宿主提供的 admin 控制器基类（复用后台视图壳与响应 helper）；
        // 脱离该宿主时跳过注册，避免路由命中后 500。
        if (!class_exists(\app\admin\controller\BaseController::class)) {
            return;
        }

        $this->group('admin_operation_log', function () {
            // 使用 Route::rule()（匹配全部方法）等价于迁移前后台默认调度的方法无关行为，
            // 避免前端既有 GET/POST 调用差异造成 405；鉴权由 admin 应用级 RBAC 统一负责。
            Route::rule('operation_log/index', '\wise\operationlog\controller\OperationLog@index');
            Route::rule('operation_log/getList', '\wise\operationlog\controller\OperationLog@getList');
            Route::rule('operation_log/preview', '\wise\operationlog\controller\OperationLog@preview');
            Route::rule('operation_log/dashboard', '\wise\operationlog\controller\OperationLog@dashboard');
            // 破坏性动作仅接受 POST：VerifyCsrfToken 只校验非只读方法，
            // 若允许 GET，则可被链接/顶层跳转诱导触发（CSRF），且绕过令牌校验。
            Route::post('operation_log/clearLog', '\wise\operationlog\controller\OperationLog@clearLog');
        });
    }
}
