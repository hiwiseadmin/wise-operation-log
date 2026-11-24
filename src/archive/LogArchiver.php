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
     * 每批处理的日志条数（配置缺失时的回退值）
     */
    private const CHUNK_SIZE = 1000;

    /**
     * 分块大小上下限（防止配置异常值导致内存溢出或频繁查询）
     */
    private const MIN_CHUNK_SIZE = 100;
    private const MAX_CHUNK_SIZE = 20000;

    /** @var string|null 当前批次归档 CSV 文件名缓存 */
    private ?string $csvFileName = null;

    /** @var string|null 当前批次归档 JSONL 文件名缓存 */
    private ?string $jsonFileName = null;

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

        $estimateCount = OperationLogModel::createFromConfig()
            ->where('create_time', '<', $beforeDate)
            ->count();

        if ($estimateCount === 0) {
            return ['archived' => 0, 'deleted' => 0, 'errors' => []];
        }

        if ($dryRun) {
            return ['archived' => $estimateCount, 'deleted' => $deleteSource ? $estimateCount : 0, 'errors' => []];
        }

        $lastId = 0;
        while (true) {
            $logs = OperationLogModel::createFromConfig()
                ->where('create_time', '<', $beforeDate)
                ->where('id', '>', $lastId)
                ->order('id', 'asc')
                ->limit($this->getChunkSize())
                ->select()
                ->toArray();

            if (empty($logs)) {
                break;
            }

            try {
                $this->writeToTarget($logs, $targetType);

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
        $logs = array_map(function (array $log) {
            unset($log['id']);
            return $log;
        }, $logs);
        $connection = config('wise-operation-log.storage.connection', '');
        $query      = $connection ? Db::connect($connection)->name($tableName) : Db::name($tableName);

        foreach (array_chunk($logs, 4000) as $batch) {
            $query->insertAll($batch);
        }
    }

    /**
     * 归档到 CSV 文件
     */
    private function archiveToCsv(array $logs): void
    {
        if ($this->csvFileName === null) {
            $ext        = $this->isCompressEnabled() ? '.csv.gz' : '.csv';
            $filePath   = $this->getFilePath();
            if (!is_dir($filePath)) {
                mkdir($filePath, 0755, true);
            }
            $this->csvFileName = $filePath . '/operation_log_' . date('Ymd_His') . $ext;
        }

        $isNew = !file_exists($this->csvFileName);
        $fp    = $this->openAppend($this->csvFileName);

        if ($isNew && !empty($logs)) {
            $this->writeCsvLine($fp, array_keys(reset($logs)));
        }

        foreach ($logs as $log) {
            $this->writeCsvLine($fp, $this->escapeCsvCells($log));
        }
        $this->closeFile($fp);
    }

    /**
     * 归档到 JSONL 文件
     */
    private function archiveToJson(array $logs): void
    {
        if ($this->jsonFileName === null) {
            $ext      = $this->isCompressEnabled() ? '.jsonl.gz' : '.jsonl';
            $filePath = $this->getFilePath();
            if (!is_dir($filePath)) {
                mkdir($filePath, 0755, true);
            }
            $this->jsonFileName = $filePath . '/operation_log_' . date('Ymd_His') . $ext;
        }

        $fp = $this->openAppend($this->jsonFileName);

        foreach ($logs as $log) {
            $this->writeLine($fp, json_encode($log, JSON_UNESCAPED_UNICODE) . "\n");
        }
        $this->closeFile($fp);
    }


    /**
     * 获取分块大小（archive.target.chunk_size）
     */
    private function getChunkSize(): int
    {
        $size = (int) config('wise-operation-log.archive.target.chunk_size', self::CHUNK_SIZE);

        return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $size > 0 ? $size : self::CHUNK_SIZE));
    }

    /**
     * 是否启用 gzip 压缩（archive.compress，仅文件模式生效）
     */
    private function isCompressEnabled(): bool
    {
        return (bool) config('wise-operation-log.archive.compress', false) && function_exists('gzopen');
    }

    /**
     * 以追加方式打开归档文件（启用压缩时使用 gzip 流）
     *
     * @return resource
     */
    private function openAppend(string $fileName)
    {
        $fp = $this->isCompressEnabled() ? @gzopen($fileName, 'a9') : @fopen($fileName, 'a');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open archive file: {$fileName}");
        }

        return $fp;
    }

    /**
     * 写入原始文本行
     *
     * @param resource $fp
     */
    private function writeLine($fp, string $line): void
    {
        $this->isCompressEnabled() ? gzwrite($fp, $line) : fwrite($fp, $line);
    }

    /**
     * 写入一行 CSV
     *
     * 显式传入 $escape（PHP 8.4 起省略该参数会触发弃用告警，且默认值将变更）。
     * gzip 模式下 fputcsv 无法直接作用于 gz 流，先写临时流再转存。
     *
     * @param resource $fp
     */
    private function writeCsvLine($fp, array $row): void
    {
        if (!$this->isCompressEnabled()) {
            fputcsv($fp, $row, ',', '"', '\\');
            return;
        }

        $tmp = fopen('php://temp', 'r+');
        fputcsv($tmp, $row, ',', '"', '\\');
        rewind($tmp);
        gzwrite($fp, (string) stream_get_contents($tmp));
        fclose($tmp);
    }

    /**
     * 关闭归档文件句柄
     *
     * @param resource $fp
     */
    private function closeFile($fp): void
    {
        $this->isCompressEnabled() ? gzclose($fp) : fclose($fp);
    }

    /**
     * CSV 公式注入防护
     */
    private function escapeCsvCells(array $row): array
    {
        return array_map(static function ($value) {
            return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
        }, $row);
    }

    /**
     * 获取归档文件路径
     *
     * 安全约束：用户配置的路径必须在 runtime 目录下，防止路径遍历风险。
     */
    private function getFilePath(): string
    {
        $configured = config('wise-operation-log.archive.target.file_path', '');
        if ($configured) {
            $runtimePath = rtrim(str_replace('\\', '/', app()->getRuntimePath()), '/');

            $resolved = realpath($configured);
            if ($resolved === false) {
                $normalized = str_replace('\\', '/', $configured);
                $parent     = realpath(dirname($normalized));
                $resolved   = $parent === false ? $normalized : rtrim($parent, '/') . '/' . basename($normalized);
            }
            $resolved = rtrim(str_replace('\\', '/', $resolved), '/');

            if ($resolved !== $runtimePath && !str_starts_with($resolved, $runtimePath . '/')) {
                throw new \RuntimeException('Archive file_path must be under runtime directory');
            }
            return rtrim($configured, '/\\');
        }
        return app()->getRuntimePath() . 'archive' . DIRECTORY_SEPARATOR . 'operation_log';
    }
}
