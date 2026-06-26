<?php

declare(strict_types=1);

namespace wise\operationlog\archive;

use think\facade\Db;
use wise\operationlog\model\OperationLogModel;

/**
 * 操作日志归档器
 *
 * 将过期日志归档到归档表或文件（CSV/JSONL），支持分块处理避免内存溢出。
 *
 * 使用方式：
 *   $archiver = new LogArchiver();
 *   $result  = $archiver->run();
 *   // => ['archived' => 5000, 'deleted' => 5000, 'errors' => []]
 */
class LogArchiver
{
    /**
     * 每批处理的日志条数
     */
    private const CHUNK_SIZE = 1000;

    /**
     * 执行归档
     *
     * @param bool $dryRun 是否为试运行（仅统计不执行）
     * @return array{ archived: int, deleted: int, errors: array }
     */
    public function run(bool $dryRun = false): array
    {
        $config      = config('wise-operation-log.archive', []);
        $days        = max(1, (int) ($config['days'] ?? 90));
        $targetType  = $config['target']['type'] ?? 'table';
        $deleteSource = $config['after_archive']['delete_source'] ?? true;

        $beforeDate  = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $totalArchived = 0;
        $totalDeleted  = 0;
        $errors        = [];

        // 1. 预估总数
        $estimateCount = OperationLogModel::createFromConfig()
            ->where('create_time', '<', $beforeDate)
            ->count();

        if ($estimateCount === 0) {
            return ['archived' => 0, 'deleted' => 0, 'errors' => []];
        }

        if ($dryRun) {
            return ['archived' => $estimateCount, 'deleted' => $deleteSource ? $estimateCount : 0, 'errors' => []];
        }

        // 2. 分块处理
        $lastId = 0;
        while (true) {
            $logs = OperationLogModel::createFromConfig()
                ->where('create_time', '<', $beforeDate)
                ->where('id', '>', $lastId)
                ->order('id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->select()
                ->toArray();

            if (empty($logs)) {
                break;
            }

            try {
                // 写入归档目标
                $this->writeToTarget($logs, $targetType);

                // 删除源数据
                if ($deleteSource) {
                    $ids = array_column($logs, 'id');
                    OperationLogModel::createFromConfig()
                        ->whereIn('id', $ids)
                        ->delete();
                    $totalDeleted += count($ids);
                }

                $totalArchived += count($logs);
                $lastId = end($logs)['id'] ?? 0;
            } catch (\Throwable $e) {
                $errors[] = "Chunk failed (lastId={$lastId}): " . $e->getMessage();
                trace('OperationLog archive chunk failed: ' . $e->getMessage(), 'error');
                // 继续下一批，不中断整个归档流程
                $lastId = end($logs)['id'] ?? 0;
            }
        }

        return [
            'archived' => $totalArchived,
            'deleted'  => $totalDeleted,
            'errors'   => $errors,
        ];
    }

    /**
     * 写入归档目标
     *
     * @param array  $logs       日志数据数组
     * @param string $targetType 归档类型：table / csv / json
     */
    private function writeToTarget(array $logs, string $targetType): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($logs as &$log) {
            $log['archived_at'] = $now;
        }
        unset($log);

        switch ($targetType) {
            case 'table':
                $this->archiveToTable($logs);
                break;
            case 'csv':
                $this->archiveToCsv($logs);
                break;
            case 'json':
                $this->archiveToJson($logs);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported archive target type: {$targetType}");
        }
    }

    /**
     * 归档到数据库表
     */
    private function archiveToTable(array $logs): void
    {
        $tableName = config('wise-operation-log.archive.target.table_name', 'operation_log_archive');
        Db::name($tableName)->insertAll($logs);
    }

    /**
     * 归档到 CSV 文件
     */
    private function archiveToCsv(array $logs): void
    {
        $filePath = $this->getFilePath();
        $fileName = $filePath . '/operation_log_' . date('Ymd_His') . '.csv';

        // 确保目录存在
        if (!is_dir($filePath)) {
            mkdir($filePath, 0755, true);
        }

        $isNew = !file_exists($fileName);
        $fp = fopen($fileName, 'a');
        if (!$fp) {
            throw new \RuntimeException("Failed to open archive file: {$fileName}");
        }

        // 首次写入 CSV 头
        if ($isNew && !empty($logs)) {
            fputcsv($fp, array_keys(reset($logs)));
        }

        foreach ($logs as $log) {
            fputcsv($fp, $log);
        }
        fclose($fp);
    }

    /**
     * 归档到 JSONL 文件
     */
    private function archiveToJson(array $logs): void
    {
        $filePath = $this->getFilePath();
        $fileName = $filePath . '/operation_log_' . date('Ymd_His') . '.jsonl';

        if (!is_dir($filePath)) {
            mkdir($filePath, 0755, true);
        }

        $fp = fopen($fileName, 'a');
        if (!$fp) {
            throw new \RuntimeException("Failed to open archive file: {$fileName}");
        }

        foreach ($logs as $log) {
            fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fp);
    }

    /**
     * 获取归档文件路径
     */
    private function getFilePath(): string
    {
        $configured = config('wise-operation-log.archive.target.file_path', '');
        if ($configured) {
            return rtrim($configured, '/\\');
        }
        // 默认路径：runtime/archive/operation_log/
        return app()->getRuntimePath() . 'archive' . DIRECTORY_SEPARATOR . 'operation_log';
    }
}
