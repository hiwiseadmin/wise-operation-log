<?php

declare(strict_types=1);

namespace wise\operationlog\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\operationlog\detector\AnomalyDetector;

/**
 * 操作日志异常检测命令
 *
 * 用法：
 *   php think wise:operationlog:anomaly                        # 运行全部检测
 *   php think wise:operationlog:anomaly --type=frequency       # 仅频率异常
 *   php think wise:operationlog:anomaly --type=level           # 仅级别异常
 *   php think wise:operationlog:anomaly --type=offhour         # 仅夜间异常
 *   php think wise:operationlog:anomaly --window=10 --threshold=5  # 自定义参数
 */
class Anomaly extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:operationlog:anomaly')
            ->addOption('type', 't', Option::VALUE_REQUIRED, '检测类型: frequency/level/offhour/newip/all', 'all')
            ->addOption('window', 'w', Option::VALUE_REQUIRED, '滑动窗口（分钟）', '5')
            ->addOption('threshold', null, Option::VALUE_REQUIRED, '阈值倍数', '3')
            ->addOption('error-count', null, Option::VALUE_REQUIRED, '级别异常触发条数', '10')
            ->setDescription('操作日志异常检测');
    }

    protected function execute(Input $input, Output $output): void
    {
        $type      = $input->getOption('type');
        $allowedTypes = ['all', 'frequency', 'level', 'offhour', 'newip'];
        if (!in_array($type, $allowedTypes, true)) {
            $output->writeln("<error>无效的检测类型: {$type}，可选值: " . implode('/', $allowedTypes) . "</error>");
            return;
        }
        $window    = max(1, min(1440, (int) $input->getOption('window')));
        $threshold = max(1.0, min(100.0, (float) $input->getOption('threshold')));
        $errorCount = max(1, min(10000, (int) $input->getOption('error-count')));

        $detector  = new AnomalyDetector();

        $output->writeln('<info>正在执行异常检测...</info>');
        $output->writeln(sprintf('  参数: 窗口=%d分钟, 阈值倍数=%.1fx, 错误阈值=%d条', $window, $threshold, $errorCount));
        $output->writeln('');

        $foundAny = false;

        // 频率异常
        if ($type === 'frequency' || $type === 'all') {
            $anomalies = $detector->detectFrequencyAnomaly($window, $threshold);
            if (!empty($anomalies)) {
                $foundAny = true;
                $output->writeln(sprintf('<warning>⚠ 频率异常检测 — 发现 %d 项异常:</warning>', count($anomalies)));
                foreach ($anomalies as $a) {
                    $output->writeln(sprintf(
                        '  操作者: %s(%d) | IP: %s | 当前: %d次 | 历史均值: %.1f次 | 倍率: %.1fx',
                        $a['operator_name'], $a['operator_id'], $a['ip'],
                        $a['current_count'], $a['avg_count'], $a['multiplier']
                    ));
                }
                $output->writeln('');
            }
        }

        // 级别异常
        if ($type === 'level' || $type === 'all') {
            $anomalies = $detector->detectLevelAnomaly($window * 2, $errorCount);
            if (!empty($anomalies)) {
                $foundAny = true;
                $output->writeln(sprintf('<error>⚠ 级别异常检测 — 发现 %d 项异常:</error>', count($anomalies)));
                foreach ($anomalies as $a) {
                    $output->writeln(sprintf(
                        '  操作者: %s(%d) | IP: %s | 级别: %s | 窗口内出现: %d次',
                        $a['operator_name'], $a['operator_id'], $a['ip'],
                        $a['level'], $a['count']
                    ));
                }
                $output->writeln('');
            }
        }

        // 夜间异常
        if ($type === 'offhour' || $type === 'all') {
            $activities = $detector->detectOffHourActivity();
            if (!empty($activities)) {
                $foundAny = true;
                $output->writeln(sprintf('<warning>⚠ 夜间操作检测 — 发现 %d 条:</warning>', count($activities)));
                foreach ($activities as $a) {
                    $output->writeln(sprintf(
                        '  [%s] %s | 操作者: %s | 级别: %s | %s',
                        $a['create_time'] ?? '',
                        $a['channel'] ?? '',
                        $a['operator_name'] ?? '',
                        $a['level'] ?? '',
                        $a['content'] ?? ''
                    ));
                }
                $output->writeln('');
            }
        }

        // 新IP异常
        if ($type === 'newip' || $type === 'all') {
            $anomalies = $detector->detectNewIpBatch();
            if (!empty($anomalies)) {
                $foundAny = true;
                $output->writeln(sprintf('<warning>⚠ 新IP检测 — 发现 %d 项异常:</warning>', count($anomalies)));
                foreach ($anomalies as $a) {
                    $output->writeln(sprintf(
                        '  操作者: %s(%d) | IP: %s | 窗口内操作: %d次 | 回溯 %d 天内首次出现',
                        $a['operator_name'], $a['operator_id'], $a['ip'],
                        $a['count'], $a['lookback_days']
                    ));
                }
                $output->writeln('');
            }
        }

        if (!$foundAny) {
            $output->writeln('<info>✓ 未发现异常</info>');
        }
    }
}
