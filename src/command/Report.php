<?php

declare(strict_types=1);

namespace wise\operationlog\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\operationlog\manage\LogManager;

/**
 * 操作日志统计报告命令
 *
 * 用法：
 *   php think wise:operationlog:report                      # 基础统计
 *   php think wise:operationlog:report --stats              # 基础统计
 *   php think wise:operationlog:report --trend --start=2026-06-01 --end=2026-06-23  # 趋势
 *   php think wise:operationlog:report --hotspots           # 热点分析
 *   php think wise:operationlog:report --error-rate --start=2026-06-01 --end=2026-06-23  # 错误率
 *   php think wise:operationlog:report --all                # 全部报告
 */
class Report extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:operationlog:report')
            ->addOption('stats', 's', Option::VALUE_NONE, '基础统计')
            ->addOption('trend', 't', Option::VALUE_NONE, '趋势分析')
            ->addOption('hotspots', null, Option::VALUE_NONE, '热点分析')
            ->addOption('error-rate', null, Option::VALUE_NONE, '错误率统计')
            ->addOption('all', 'a', Option::VALUE_NONE, '输出全部报告')
            ->addOption('start', null, Option::VALUE_REQUIRED, '开始时间（Y-m-d）')
            ->addOption('end', null, Option::VALUE_REQUIRED, '结束时间（Y-m-d）')
            ->addOption('top', null, Option::VALUE_REQUIRED, 'Top N', '20')
            ->setDescription('生成操作日志统计报告');
    }

    protected function execute(Input $input, Output $output): void
    {
        $all     = $input->getOption('all');
        $stats   = $input->getOption('stats') || $all;
        $trend   = $input->getOption('trend') || $all;
        $hotspots = $input->getOption('hotspots') || $all;
        $errorRate = $input->getOption('error-rate') || $all;

        // 默认：无选项时输出基础统计
        if (!($stats || $trend || $hotspots || $errorRate)) {
            $stats = true;
        }

        $start = $input->getOption('start') ?: date('Y-m-01');
        $end   = $input->getOption('end') ?: date('Y-m-d 23:59:59');
        $topN  = max(1, (int) $input->getOption('top'));

        /** @var LogManager $manager */
        $manager = app(LogManager::class);

        // ===== 基础统计 =====
        if ($stats) {
            $this->printStats($output, $manager);
        }

        // ===== 趋势分析 =====
        if ($trend) {
            $this->printTrend($output, $manager, $start, $end);
        }

        // ===== 热点分析 =====
        if ($hotspots) {
            $this->printHotspots($output, $manager, $topN);
        }

        // ===== 错误率 =====
        if ($errorRate) {
            $this->printErrorRate($output, $manager, $start, $end);
        }
    }

    private function printStats(Output $output, LogManager $manager): void
    {
        $output->writeln('<info>========== 基础统计 ==========</info>');
        $data = $manager->stats();

        $output->writeln(sprintf('  总日志数: <comment>%d</comment>', $data['total']));
        $output->writeln(sprintf('  今日日志: <comment>%d</comment>', $data['today']));

        $output->writeln('');
        $output->writeln('  按频道统计:');
        foreach ($data['channels'] as $ch) {
            $output->writeln(sprintf('    %-20s <comment>%d</comment>', $ch['channel'], $ch['count']));
        }

        if (!empty($data['levels'] ?? [])) {
            $output->writeln('');
            $output->writeln('  按级别统计:');
            foreach ($data['levels'] as $lv) {
                $output->writeln(sprintf('    %-12s <comment>%d</comment>', $lv['level'], $lv['count']));
            }
        }

        $output->writeln('');
        $output->writeln('  操作者 Top 10:');
        foreach ($data['operators'] as $op) {
            $name = $op['operator_name'] ?: '(system)';
            $output->writeln(sprintf('    %-20s <comment>%d</comment>', $name, $op['count']));
        }
        $output->writeln('');
    }

    private function printTrend(Output $output, LogManager $manager, string $start, string $end): void
    {
        $output->writeln('<info>========== 趋势分析 ==========</info>');
        $output->writeln(sprintf('  时间范围: %s ~ %s', $start, $end));

        $data = $manager->trend($start, $end, 'daily');
        $output->writeln('');

        if (empty($data)) {
            $output->writeln('  <comment>该时间段内无数据</comment>');
        } else {
            $output->writeln(sprintf('  %-16s %-8s %s', '日期', '级别', '数量'));
            $output->writeln('  ' . str_repeat('-', 36));
            foreach ($data as $row) {
                $output->writeln(sprintf('  %-16s %-8s <comment>%d</comment>', $row['time_bucket'] ?? '', $row['level'] ?? '', $row['count'] ?? 0));
            }
        }
        $output->writeln('');
    }

    private function printHotspots(Output $output, LogManager $manager, int $topN): void
    {
        $output->writeln(sprintf('<info>========== 热点分析 (Top %d) ==========</info>', $topN));
        $data = $manager->hotspots($topN);

        if (empty($data)) {
            $output->writeln('  <comment>无数据</comment>');
        } else {
            $output->writeln(sprintf('  %-16s %-40s %s', '频道', '请求路径', '次数'));
            $output->writeln('  ' . str_repeat('-', 70));
            foreach ($data as $row) {
                $output->writeln(sprintf('  %-16s %-40s <comment>%d</comment>',
                    $row['channel'] ?? '',
                    $row['request_url'] ?? '',
                    $row['count'] ?? 0
                ));
            }
        }
        $output->writeln('');
    }

    private function printErrorRate(Output $output, LogManager $manager, string $start, string $end): void
    {
        $output->writeln('<info>========== 错误率统计 ==========</info>');
        $output->writeln(sprintf('  时间范围: %s ~ %s', $start, $end));

        $data = $manager->errorRate($start, $end);
        $output->writeln(sprintf('  总操作数: <comment>%d</comment>', $data['total'] ?? 0));
        $output->writeln(sprintf('  错误: <error>%d</error>  (<error>%s%%</error>)',
            $data['error_count'] ?? 0,
            $data['error_rate'] ?? '0'
        ));
        $output->writeln(sprintf('  警告: <comment>%d</comment>  (<comment>%s%%</comment>)',
            $data['warning_count'] ?? 0,
            $data['warning_rate'] ?? '0'
        ));
        $output->writeln('');
    }
}
