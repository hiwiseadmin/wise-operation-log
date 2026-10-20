<?php

declare(strict_types=1);

namespace wise\operationlog\storage;

/**
 * 操作日志存储接口
 */
interface StorageInterface
{
    /**
     * 保存日志数据
     *
     * @param array<string, mixed> $log 日志数据
     */
    public function save(array $log): void;

    /**
     * 批量保存日志（可选优化）
     *
     * 默认降级为逐条 save()，子类可覆写实现批量写入。
     *
     * @param array<int, array<string, mixed>> $logs 日志数据列表
     */
    public function batchSave(array $logs): void;
}
