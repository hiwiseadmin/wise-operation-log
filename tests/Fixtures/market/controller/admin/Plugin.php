<?php
declare(strict_types=1);

namespace wise\market\controller\admin;

/**
 * 测试用「包命名空间控制器」Fixture：模拟 wise-market 这类包内控制器
 * `wise\market\controller\admin\Plugin`。
 *
 * 包控制器的路由为 FQCN `wise\market\controller\admin\Plugin@index`，框架 Callback 调度会把
 * 命名空间前缀 `wise/market` 塞进 Request::layer()、把 `admin/Plugin` 塞进 controller()。
 * 用于验证 resolveControllerClass 能据 layer 拼回完整 FQCN（否则日志静默丢失）。
 */
class Plugin
{
}
