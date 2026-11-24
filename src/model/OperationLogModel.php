<?php

declare(strict_types=1);

namespace wise\operationlog\model;

use think\Model;

/**
 * 操作日志模型
 *
 * 表名：ws_operation_log（框架自动添加 ws_ 前缀）
 * 仅创建时间戳，日志记录为追加写入，不涉及更新
 */
class OperationLogModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加 ws_）
     */
    protected $name = 'operation_log';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;

    /**
     * 创建时间字段
     */
    protected $createTime = 'create_time';

    /**
     * 不启用更新时间字段（日志表只追加不更新）
     */
    protected $updateTime = false;

    /**
     * 从包配置创建模型实例
     *
     * 自动读取 wise-operation-log.storage.connection，设置到模型的连接属性。
     * DatabaseStorage 和 LogManager 统一使用此方法，消除重复代码。
     */
    public static function createFromConfig(): self
    {
        $config     = config('wise-operation-log.storage', []);
        $connection = $config['connection'] ?? '';

        $model = new static();
        if ($connection) {
            $model->setConnection($connection);
        }

        return $model;
    }
}
