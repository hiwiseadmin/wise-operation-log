<?php

declare(strict_types=1);

namespace wise\operationlog\storage;

use wise\operationlog\model\OperationLogModel;

/**
 * 数据库存储实现
 *
 * 通过 OperationLogModel 操作日志表，支持单条 save 和批量 insertAll
 * 写入前自动截断超长字段
 */
class DatabaseStorage implements StorageInterface
{
    /**
     * 截断标记
     */
    private const TRUNCATED_MARKER = '…';

    /**
     * varchar 字段长度约束（与建表 DDL 一致）
     */
    private const FIELD_LIMITS = [
        'channel'       => 50,
        'level'         => 20,
        'operator_name' => 100,
        'request_url'   => 500,
        'ip'            => 50,
        'user_agent'    => 500,
        'tags'          => 255,
    ];

    /**
     * 保存日志数据到数据库
     *
     * @param array<string, mixed> $log 日志数据
     */
    public function save(array $log): void
    {
        try {
            $log   = $this->truncateLongFields($log);
            $model = OperationLogModel::createFromConfig();
            $model->save($log);
        } catch (\Throwable $e) {
            trace('OperationLog DatabaseStorage save failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 批量保存日志
     *
     * 使用 insertAll() 一次性写入多条记录，减少数据库交互次数。
     * 批量失败时降级为逐条 save()。
     *
     * @param array<int, array<string, mixed>> $logs 日志数据列表
     */
    public function batchSave(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        try {
            // 对每条日志截断超长字段
            $logs = array_map(fn($log) => $this->truncateLongFields($log), $logs);

            $model = OperationLogModel::createFromConfig();
            $model->db()->insertAll($logs);
        } catch (\Throwable $e) {
            trace('OperationLog DatabaseStorage batchSave failed, fallback to single: ' . $e->getMessage(), 'error');
            // 批量写入失败，降级为逐条写入
            foreach ($logs as $log) {
                $this->save($log);
            }
        }
    }

    /**
     * 自动截断超长字段
     *
     * 对 varchar 字段按建表 DDL 约束截断，末尾追加截断标记；
     * text 字段（content / params / extra）不限制。
     *
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    private function truncateLongFields(array $log): array
    {
        foreach (self::FIELD_LIMITS as $field => $limit) {
            if (!isset($log[$field]) || !is_string($log[$field])) {
                continue;
            }

            $value = $log[$field];

            if (mb_strlen($value, 'UTF-8') > $limit) {
                $truncated = mb_substr($value, 0, $limit - 1, 'UTF-8') . self::TRUNCATED_MARKER;
                $log[$field] = $truncated;

                trace(
                    sprintf('OperationLog field "%s" truncated: %d → %d chars', $field, mb_strlen($value, 'UTF-8'), mb_strlen($truncated, 'UTF-8')),
                    'info'
                );
            }
        }

        return $log;
    }
}
