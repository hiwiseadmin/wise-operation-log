<?php

declare(strict_types=1);

namespace wise\operationlog\detector;

use wise\operationlog\model\OperationLogModel;

/**
 * 操作日志异常检测器
 *
 * 提供多种异常检测维度：
 * - 频率异常：某操作者在时间窗口内操作频率远超历史均值
 * - 级别异常：短时间内出现大量 WARNING/ERROR/CRITICAL 日志
 * - 新IP异常：操作者首次从未知 IP 执行操作
 * - 夜间异常：非工作时间执行高风险操作
 */
class AnomalyDetector
{
    /**
     * 频率异常检测
     *
     * 基于滑动窗口 + 阈值倍数判断。当某 IP/操作者 在指定时间窗口内
     * 操作频率超过历史同期平均水平的 thresholdMultiplier 倍时告警。
     *
     * @param int    $windowMinutes      滑动窗口时长（分钟），默认 5
     * @param float  $thresholdMultiplier 阈值倍数，默认 3.0
     * @param string $groupBy            分组维度：operator / ip / both
     * @return array<int, array>
     */
    public function detectFrequencyAnomaly(
        int $windowMinutes = 5,
        float $thresholdMultiplier = 3.0,
        string $groupBy = 'both'
    ): array {
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));
        $anomalies   = [];

        try {
            // 构建分组字段
            $groupFields = match ($groupBy) {
                'operator' => ['operator_id'],
                'ip'       => ['ip'],
                default    => ['operator_id', 'ip'],
            };

            // 最近窗口内的操作频率
            $recentQuery = OperationLogModel::createFromConfig()
                ->field(array_merge($groupFields, ['operator_name', 'COUNT(*) as count']));

            foreach ($groupFields as $field) {
                $recentQuery = $recentQuery->group($field);
            }

            $recentActivity = $recentQuery
                ->where('create_time', '>=', $windowStart)
                ->group(implode(',', $groupFields))
                ->select()
                ->toArray();

            // 历史平均频率（过去7天同时段平均）
            $historyAvg = $this->getHistoryAvg($windowMinutes, $groupBy);

            foreach ($recentActivity as $activity) {
                $key = $this->buildKey($activity, $groupFields);
                $avg = $historyAvg[$key]['avg'] ?? 1.0;

                if ($activity['count'] > $avg * $thresholdMultiplier) {
                    $anomalies[] = [
                        'type'                => 'frequency',
                        'operator_id'         => $activity['operator_id'] ?? 0,
                        'operator_name'       => $activity['operator_name'] ?? '',
                        'ip'                  => $activity['ip'] ?? '',
                        'current_count'       => (int) $activity['count'],
                        'avg_count'           => round($avg, 2),
                        'multiplier'          => round($activity['count'] / max($avg, 0.01), 2),
                        'window_minutes'      => $windowMinutes,
                    ];
                }
            }
        } catch (\Throwable $e) {
            trace('AnomalyDetector frequency check failed: ' . $e->getMessage(), 'error');
        }

        return $anomalies;
    }

    /**
     * 级别异常检测
     *
     * 当短时间内出现大量 WARNING/ERROR/CRITICAL 级别日志时告警。
     *
     * @param int $windowMinutes  时间窗口（分钟），默认 10
     * @param int $errorThreshold 触发阈值，默认 10
     * @return array<int, array>
     */
    public function detectLevelAnomaly(int $windowMinutes = 10, int $errorThreshold = 10): array
    {
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));
        $anomalies   = [];

        try {
            $levels = ['WARNING', 'ERROR', 'CRITICAL'];

            $results = OperationLogModel::createFromConfig()
                ->field('operator_id, operator_name, ip, level, COUNT(*) as count')
                ->where('create_time', '>=', $windowStart)
                ->whereIn('level', $levels)
                ->group('operator_id, ip, level')
                ->having('count >= ' . (int) $errorThreshold)
                ->select()
                ->toArray();

            foreach ($results as $row) {
                $anomalies[] = [
                    'type'          => 'level_spike',
                    'operator_id'   => $row['operator_id'] ?? 0,
                    'operator_name' => $row['operator_name'] ?? '',
                    'ip'            => $row['ip'] ?? '',
                    'level'         => $row['level'],
                    'count'         => (int) $row['count'],
                    'window_minutes' => $windowMinutes,
                ];
            }
        } catch (\Throwable $e) {
            trace('AnomalyDetector level check failed: ' . $e->getMessage(), 'error');
        }

        return $anomalies;
    }

    /**
     * 新IP异常检测
     *
     * 检查某操作者是否从新的 IP 地址执行操作。
     *
     * @param int    $operatorId   操作者ID
     * @param string $ip          当前IP
     * @param int    $lookbackDays 回溯天数（在该天数内是否出现过该IP），默认 30
     * @return bool true 表示是新IP
     */
    public function detectNewIpActivity(int $operatorId, string $ip, int $lookbackDays = 30): bool
    {
        if ($operatorId <= 0) {
            return false;
        }

        try {
            $since = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));

            $exists = OperationLogModel::createFromConfig()
                ->where('operator_id', $operatorId)
                ->where('ip', $ip)
                ->where('create_time', '>=', $since)
                ->find();

            // 过去 N 天内没有出现过，判定为新IP
            return $exists === null;
        } catch (\Throwable $e) {
            trace('AnomalyDetector new IP check failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 夜间异常操作检测
     *
     * 检测非工作时间执行高风险操作的日志。
     *
     * @param string $startHour 夜间开始时间，默认 22:00
     * @param string $endHour   夜间结束时间，默认 06:00
     * @param int    $limit     返回条数上限
     * @return array<int, array>
     */
    public function detectOffHourActivity(
        string $startHour = '22:00:00',
        string $endHour = '06:00:00',
        int $limit = 100
    ): array {
        $today       = date('Y-m-d');
        $nightStart  = $today . ' ' . $startHour;
        $nightEnd    = date('Y-m-d', strtotime($today . ' +1 day')) . ' ' . $endHour;

        try {
            return OperationLogModel::createFromConfig()
                ->whereIn('level', ['WARNING', 'ERROR', 'CRITICAL'])
                ->where(function ($query) use ($nightStart, $nightEnd) {
                    // 跨天夜间时段：当天22:00之后 OR 次日06:00之前
                    $query->where('create_time', '>=', $nightStart)
                        ->whereOr('create_time', '<=', $nightEnd);
                })
                ->order('id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            trace('AnomalyDetector off-hour check failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 运行所有检测并汇总结果
     *
     * @return array
     */
    public function runAll(int $windowMinutes = 5, float $thresholdMultiplier = 3.0, int $errorThreshold = 10): array
    {
        return [
            'frequency_anomalies' => $this->detectFrequencyAnomaly($windowMinutes, $thresholdMultiplier),
            'level_anomalies'     => $this->detectLevelAnomaly($windowMinutes * 2, $errorThreshold),
            'off_hour_activities' => $this->detectOffHourActivity(),
        ];
    }

    // ==========================================
    //  辅助方法
    // ==========================================

    /**
     * 获取历史平均频率
     *
     * 计算过去 7 天同时段的平均操作频率。
     *
     * @param int    $windowMinutes 窗口时长
     * @param string $groupBy       分组维度
     * @return array<string, array{ avg: float }>
     */
    private function getHistoryAvg(int $windowMinutes, string $groupBy): array
    {
        try {
            $timeOfDay      = date('H:i');
            $intervalEnd    = strtotime($timeOfDay);
            $intervalStart  = $intervalEnd - ($windowMinutes * 60);

            $startTime = date('H:i:s', $intervalStart);
            $endTime   = date('H:i:s', $intervalEnd);

            $groupFields = match ($groupBy) {
                'operator' => ['operator_id'],
                'ip'       => ['ip'],
                default    => ['operator_id', 'ip'],
            };

            $selectFields = $groupFields;
            $selectFields[] = 'operator_name';
            $selectFields[] = 'COUNT(*) / 7 as avg';

            $rows = OperationLogModel::createFromConfig()
                ->field($selectFields)
                ->where('create_time', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->whereTime('create_time', '>=', $startTime)
                        ->whereTime('create_time', '<=', $endTime);
                })
                ->group(implode(',', $groupFields))
                ->select()
                ->toArray();

            $result = [];
            foreach ($rows as $row) {
                $key = $this->buildKey($row, $groupFields);
                $result[$key] = ['avg' => (float) $row['avg']];
            }
            return $result;
        } catch (\Throwable $e) {
            trace('AnomalyDetector history avg failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 构建分组唯一键
     */
    private function buildKey(array $row, array $groupFields): string
    {
        $parts = [];
        foreach ($groupFields as $field) {
            $parts[] = $row[$field] ?? '0';
        }
        return implode('_', $parts);
    }
}
