<?php
declare(strict_types=1);

namespace wise\operationlog\manage;

use wise\operationlog\model\OperationLogModel;

/**
 * 操作日志管理器
 * 提供日志查询、清理、保留、统计、异常检测等管理功能
 *
 * 使用方式：
 *   // 通过 Facade
 *   use wise\operationlog\facade\OperationLog;
 *   OperationLog::query(['channel' => 'admin']);
 *   OperationLog::cleanup(30);
 *
 *   // 通过 app() 容器
 *   app('wise\operationlog\manage\LogManager')->query([]);
 */
class LogManager
{
    /**
     * 允许排序的字段白名单（防止 SQL 注入）
     */
    private const ALLOWED_ORDER_COLUMNS = ['id', 'channel', 'level', 'create_time', 'operator_id', 'operator_name', 'ip', 'duration_ms'];

    /**
     * 允许排序方向白名单
     */
    private const ALLOWED_ORDER_DIRS = ['asc', 'desc'];

    /**
     * cleanup 天数上限（防止溢出导致全表删除）
     */
    private const MAX_CLEANUP_DAYS = 36500;

    /**
     * 转义 LIKE 查询中的通配符，防止 % 和 _ 被当作 SQL 通配符
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * #6 trend 允许的聚合粒度
     */
    private const ALLOWED_INTERVALS = ['hourly', 'daily', 'weekly', 'monthly'];

    /**
     * 查询日志
     *
     * #5 新增 level / levels 过滤、duration 过滤
     *
     * @param array<string, mixed> $where 查询条件
     * @param int $page   页码
     * @param int $limit  每页条数
     * @param string $orderBy 排序字段
     * @param string $orderDir 排序方向
     * @return array{ list: array, total: int, page: int, limit: int }
     */
    public function query(array $where = [], int $page = 1, int $limit = 20, string $orderBy = 'id', string $orderDir = 'desc'): array
    {
        // 参数安全检查
        $orderBy  = in_array($orderBy, self::ALLOWED_ORDER_COLUMNS, true) ? $orderBy : 'id';
        $orderDir = in_array(strtolower($orderDir), self::ALLOWED_ORDER_DIRS, true) ? strtolower($orderDir) : 'desc';

        $query = OperationLogModel::createFromConfig();

        // 构建查询条件
        if (!empty($where['channel'])) {
            $query = $query->where('channel', $where['channel']);
        }
        // #5 单级别过滤
        if (!empty($where['level'])) {
            $query = $query->where('level', $where['level']);
        }
        // #5 多级别过滤（IN 查询）
        if (!empty($where['levels']) && is_array($where['levels'])) {
            $query = $query->whereIn('level', $where['levels']);
        }
        if (!empty($where['operator_id'])) {
            $query = $query->where('operator_id', $where['operator_id']);
        }
        if (!empty($where['operator_name'])) {
            $query = $query->where('operator_name', 'like', '%' . $this->escapeLike($where['operator_name']) . '%');
        }
        if (!empty($where['content'])) {
            $query = $query->where('content', 'like', '%' . $this->escapeLike($where['content']) . '%');
        }
        if (!empty($where['ip'])) {
            $query = $query->where('ip', $where['ip']);
        }
        if (!empty($where['tags'])) {
            $query = $query->where('tags', 'like', '%' . $this->escapeLike($where['tags']) . '%');
        }
        if (!empty($where['start_time'])) {
            $query = $query->where('create_time', '>=', $where['start_time']);
        }
        if (!empty($where['end_time'])) {
            $query = $query->where('create_time', '<=', $where['end_time']);
        }
        // #11 慢操作过滤
        if (!empty($where['slow_than'])) {
            $query = $query->where('duration_ms', '>=', (int) $where['slow_than']);
        }

        // 显式 clone：避免依赖 TP 内部不清空 options 的隐性行为
        $total = (clone $query)->count();
        $list  = $query->order($orderBy, $orderDir)
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 按天数清理日志
     *
     * @param int $days 保留最近N天的日志
     * @return int 删除的记录数
     */
    public function cleanup(int $days = 30): int
    {
        // 参数校验：非正数不执行，上限防溢出
        if ($days <= 0 || $days > self::MAX_CLEANUP_DAYS) {
            return 0;
        }

        $timestamp = strtotime("-{$days} days");
        if ($timestamp === false) {
            return 0;
        }
        $beforeDate = date('Y-m-d H:i:s', $timestamp);

        try {
            return OperationLogModel::createFromConfig()
                ->where('create_time', '<', $beforeDate)
                ->delete();
        } catch (\Throwable $e) {
            trace('OperationLog cleanup failed: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * 保留策略：保留指定天数的日志 + 保留指定条数（取交集）
     *
     * @param int $days       保留最近N天的日志
     * @param int $maxRecords 最多保留N条日志（0 表示不限制条数）
     * @return int 删除的记录数
     */
    public function retain(int $days = 30, int $maxRecords = 0): int
    {
        $deleted = 0;

        // 1. 按天数清理
        if ($days > 0) {
            $deleted += $this->cleanup($days);
        }

        // 2. 按条数清理（保留最新的N条）
        if ($maxRecords > 0) {
            try {
                $totalCount = OperationLogModel::createFromConfig()->count();
                if ($totalCount > $maxRecords) {
                    // 找到第 maxRecords 条记录的ID（降序排列后的边界）
                    $borderId = OperationLogModel::createFromConfig()
                        ->order('id', 'desc')
                        ->limit($maxRecords, 1)
                        ->value('id');

                    if ($borderId) {
                        $extraDeleted = OperationLogModel::createFromConfig()
                            ->where('id', '<', $borderId)
                            ->delete();
                        $deleted += $extraDeleted;
                    }
                }
            } catch (\Throwable $e) {
                trace('OperationLog retain by maxRecords failed: ' . $e->getMessage(), 'error');
            }
        }

        return $deleted;
    }

    /**
     * 获取日志统计信息
     *
     * #5 新增按级别统计
     *
     * @return array{ total: int, today: int, channels: array, operators: array, levels: array }
     */
    public function stats(): array
    {
        try {
            $model = OperationLogModel::createFromConfig();
            $total = $model->count();
            $today = $model->whereTime('create_time', 'today')->count();

            // 按频道统计
            $channels = OperationLogModel::createFromConfig()
                ->field('channel, COUNT(*) as count')
                ->group('channel')
                ->select()
                ->toArray();

            // #5 按级别统计
            $levels = OperationLogModel::createFromConfig()
                ->field('level, COUNT(*) as count')
                ->group('level')
                ->select()
                ->toArray();

            // 按操作者统计 Top 10
            $operators = OperationLogModel::createFromConfig()
                ->field('operator_id, operator_name, COUNT(*) as count')
                ->group('operator_id, operator_name')
                ->order('count', 'desc')
                ->limit(10)
                ->select()
                ->toArray();

            return [
                'total'     => $total,
                'today'     => $today,
                'channels'  => $channels,
                'levels'    => $levels,
                'operators' => $operators,
            ];
        } catch (\Throwable $e) {
            trace('OperationLog stats failed: ' . $e->getMessage(), 'error');
            return ['total' => 0, 'today' => 0, 'channels' => [], 'levels' => [], 'operators' => []];
        }
    }

    // ==========================================
    //  #6 统计能力增强
    // ==========================================

    /**
     * 时间段趋势统计
     *
     * @param string $start    开始时间（Y-m-d 或 Y-m-d H:i:s）
     * @param string $end      结束时间
     * @param string $interval 聚合粒度：hourly/daily/weekly/monthly
     * @return array<int, array{ time_bucket: string, level: string, count: int }>
     */
    public function trend(string $start, string $end, string $interval = 'daily'): array
    {
        $interval = in_array($interval, self::ALLOWED_INTERVALS, true) ? $interval : 'daily';

        $format = match ($interval) {
            'hourly'  => '%Y-%m-%d %H:00:00',
            'daily'   => '%Y-%m-%d',
            'weekly'  => '%Y-%u',
            'monthly' => '%Y-%m',
        };

        try {
            return OperationLogModel::createFromConfig()
                ->field("DATE_FORMAT(create_time, '{$format}') as time_bucket, level, COUNT(*) as count")
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->group('time_bucket, level')
                ->order('time_bucket', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            trace('OperationLog trend failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 操作热点分析
     *
     * @param int $topN Top N 热点
     * @return array<int, array{ channel: string, request_url: string, count: int }>
     */
    public function hotspots(int $topN = 20): array
    {
        try {
            return OperationLogModel::createFromConfig()
                ->field('channel, request_url, COUNT(*) as count')
                ->group('channel, request_url')
                ->order('count', 'desc')
                ->limit(max(1, $topN))
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            trace('OperationLog hotspots failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * IP 行为分析
     *
     * @return array<int, array>
     */
    public function ipAnalysis(): array
    {
        try {
            return OperationLogModel::createFromConfig()
                ->field('ip, COUNT(*) as total, COUNT(DISTINCT operator_id) as operators, COUNT(DISTINCT channel) as channels')
                ->group('ip')
                ->order('total', 'desc')
                ->limit(50)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            trace('OperationLog ipAnalysis failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 错误率/警告率统计
     *
     * @param string $start 开始时间
     * @param string $end   结束时间
     * @return array{ total: int, error_count: int, warning_count: int, error_rate: string, warning_rate: string }
     */
    public function errorRate(string $start, string $end): array
    {
        try {
            $model = OperationLogModel::createFromConfig();

            $total = $model
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->count();

            $errorCount = OperationLogModel::createFromConfig()
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->whereIn('level', ['ERROR', 'CRITICAL'])
                ->count();

            $warningCount = OperationLogModel::createFromConfig()
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->where('level', 'WARNING')
                ->count();

            return [
                'total'         => $total,
                'error_count'   => $errorCount,
                'warning_count' => $warningCount,
                'error_rate'    => $total > 0 ? number_format($errorCount / $total * 100, 2) : '0.00',
                'warning_rate'  => $total > 0 ? number_format($warningCount / $total * 100, 2) : '0.00',
            ];
        } catch (\Throwable $e) {
            trace('OperationLog errorRate failed: ' . $e->getMessage(), 'error');
            return ['total' => 0, 'error_count' => 0, 'warning_count' => 0, 'error_rate' => '0.00', 'warning_rate' => '0.00'];
        }
    }

    // ==========================================
    //  #11 慢操作查询
    // ==========================================

    /**
     * 慢操作查询
     *
     * @param int $thresholdMs 阈值（毫秒），默认 3000
     * @param int $limit       返回条数
     * @return array
     */
    public function slowQueries(int $thresholdMs = 3000, int $limit = 50): array
    {
        try {
            return OperationLogModel::createFromConfig()
                ->where('duration_ms', '>=', $thresholdMs)
                ->order('duration_ms', 'desc')
                ->limit(max(1, $limit))
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            trace('OperationLog slowQueries failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }
}
