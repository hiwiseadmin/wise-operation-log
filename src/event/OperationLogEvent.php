<?php

declare(strict_types=1);

namespace wise\operationlog\event;

/**
 * 操作日志事件
 * 当操作日志被触发时派发此事件
 */
class OperationLogEvent
{
    /**
     * @param array<string, mixed> $logData 日志数据
     */
    public function __construct(
        public array $logData = []
    ) {}
}
