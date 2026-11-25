<?php

declare(strict_types=1);

namespace wise\operationlog\facade;

use think\Facade;

/**
 * 操作日志门面
 * 提供静态方法访问 LogManager 的管理功能
 *
 * 使用示例：
 *   use wise\operationlog\facade\OperationLog;
 *
 *   // 查询日志
 *   $result = OperationLog::query(['channel' => 'admin'], 1, 20);
 *
 *   // 清理30天前的日志
 *   $deleted = OperationLog::cleanup(30);
 *
 *   // 保留30天+最多10万条
 *   $deleted = OperationLog::retain(30, 100000);
 *
 *   // 统计信息
 *   $stats = OperationLog::stats();
 */
class OperationLog extends Facade
{
    /**
     * 获取门面对应的服务类名
     */
    protected static function getFacadeClass(): string
    {
        return \wise\operationlog\manage\LogManager::class;
    }
}
