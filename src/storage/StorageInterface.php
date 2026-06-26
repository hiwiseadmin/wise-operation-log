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
}
