<?php
declare(strict_types=1);

namespace wise\operationlog\menu;

use wise\support\contracts\MenuItemProvider;

/**
 * wise-operation-log 后台菜单声明（ExtPoints::MENU_ITEM）
 *
 * 「操作日志」挂靠在框架已持有的「日志管理」一级目录下（anchor = 目录名）：
 * 该目录是**跨包聚合目录**（操作日志 / 接口日志分属不同包），所以目录本身
 * 不归任何业务包所有 —— 本包只声明自己的叶子节点，卸载时目录保留，
 * 仅本包节点被 prune / uninstall 清理。
 *
 * 菜单不硬编码 INSERT，而是在此声明；由宿主侧 app\admin\service\MenuItemSync
 * 扫描扩展点幂等落地（含菜单派生权限联动）。升级时改这里再执行
 * `php think menu:sync` 即可。
 *
 * 派生的权限标识（供 RBAC 中间件与角色授权使用）：
 *   admin.operation_log.index / getList / preview / dashboard / clearLog
 *
 * 注意：admin 应用 RBAC 为白名单模式，未声明权限的路由对非超管一律拒绝，
 * 因此 getList / preview 这类前端真正调用的动作必须有对应权限节点。
 */
class OperationLogMenuProvider implements MenuItemProvider
{
    public function ident(): string
    {
        return 'wise-operation-log';
    }

    public function containers(): array
    {
        return [
            [
                'key'    => 'log',
                // 挂靠框架既有目录「日志管理」：不创建、不拥有、不改其排序
                'anchor' => '日志管理',
                'name'   => '日志管理',
            ],
        ];
    }

    public function items(): array
    {
        return [
            [
                'name'   => '操作日志',
                'icon'   => 'bi bi-activity',
                'path'   => '/admin/operation_log/index',
                'sort'   => 0,
                'type'   => 'menu',
                'status' => 1,
                'child'  => [
                    ['name' => '查看日志', 'path' => '/admin/operation_log/getList', 'type' => 'button'],
                    ['name' => '日志详情', 'path' => '/admin/operation_log/preview', 'type' => 'button'],
                    ['name' => '清理日志', 'path' => '/admin/operation_log/clearLog', 'type' => 'button'],
                ],
            ],
        ];
    }
}
