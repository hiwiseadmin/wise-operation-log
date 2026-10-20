<?php

declare(strict_types=1);

namespace wise\operationlog\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use wise\operationlog\manage\LogManager;

/**
 * 操作日志清理命令
 *
 * 用法：
 *   php think wise:operationlog:clean              # 使用配置中的默认天数
 *   php think wise:operationlog:clean 60           # 清理60天前的日志
 *   php think wise:operationlog:clean 30 --max=100000  # 保留30天+最多10万条
 */
class Clean extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:operationlog:clean')
            ->addArgument('days', Argument::OPTIONAL, '保留最近N天的日志', '0')
            ->addOption('max', 'm', Option::VALUE_OPTIONAL, '最多保留N条日志', '0')
            ->addOption('audit', null, Option::VALUE_NONE, '同时清理审计日志（audit_log）')
            ->addOption('audit-days', null, Option::VALUE_OPTIONAL, '审计日志保留天数，缺省取 audit.retention_days', '0')
            ->setDescription('清理操作日志：按天数和/或条数保留');
    }

    protected function execute(Input $input, Output $output): void
    {
        $days = (int) $input->getArgument('days');
        $maxRecords = (int) $input->getOption('max');

        // 从配置获取默认值
        $ttlConfig = config('wise-operation-log.ttl', []);
        if ($days <= 0) {
            $days = $ttlConfig['days'] ?? 0;
        }

        if ($days <= 0 && $maxRecords <= 0) {
            $output->writeln('<error>请指定保留天数或最大条数</error>');
            $output->writeln('<info>用法: php think wise:operationlog:clean [days] [--max=N]</info>');
            return;
        }

        /** @var LogManager $manager */
        $manager = app(LogManager::class);

        $output->writeln('<info>正在清理操作日志...</info>');

        if ($maxRecords > 0) {
            $deleted = $manager->retain($days, $maxRecords);
            $output->writeln("<info>保留策略：{$days}天 + 最多{$maxRecords}条，已删除 {$deleted} 条日志</info>");
        } else {
            $deleted = $manager->cleanup($days);
            $output->writeln("<info>保留最近{$days}天，已删除 {$deleted} 条日志</info>");
        }

        // 审计日志清理（--audit，默认取 audit.retention_days）
        if ($input->getOption('audit')) {
            $this->cleanAuditLog($output, (int) $input->getOption('audit-days'));
        }
    }

    /**
     * 清理审计日志（audit_log）
     *
     * @param Output $output 输出对象
     * @param int    $days   保留天数，0 表示取 audit.retention_days
     */
    private function cleanAuditLog(Output $output, int $days): void
    {
        $auditConfig = config('wise-operation-log.audit', []);
        $days        = $days > 0 ? $days : (int) ($auditConfig['retention_days'] ?? 0);

        if ($days <= 0 || $days > 36500) {
            $output->writeln('<error>审计日志清理失败：请通过 --audit-days 指定天数或配置 audit.retention_days</error>');
            return;
        }

        $beforeDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        try {
            $deleted = Db::name('audit_log')->where('create_time', '<', $beforeDate)->delete();
            $output->writeln("<info>审计日志：保留最近{$days}天，已删除 {$deleted} 条</info>");
        } catch (\Throwable $e) {
            trace('AuditLog cleanup failed: ' . $e->getMessage(), 'error');
            $output->writeln('<error>审计日志清理失败：' . $e->getMessage() . '</error>');
        }
    }
}
