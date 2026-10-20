<?php

declare(strict_types=1);

namespace wise\operationlog\event;

/**
 * 操作日志保存前事件
 *
 * 在日志数据实际写入存储之前派发，允许监听者修改日志数据（如追加 extra 扩展字段）。
 *
 * 使用示例（在项目 event.php 或 Service 中注册）：
 *   Event::listen(OperationLogSavingEvent::class, function(OperationLogSavingEvent $event) {
 *       $event->logData['extra'] = json_encode([
 *           'department' => session('department'),
 *           'request_id' => request()->header('x-request-id'),
 *       ]);
 *   });
 */
class OperationLogSavingEvent
{
    /**
     * @param array<string, mixed> $logData 日志数据（可修改，修改后直接影响写入内容）
     */
    public function __construct(
        public array $logData = []
    ) {}
}
