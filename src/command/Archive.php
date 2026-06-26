<?php

declare(strict_types=1);

namespace wise\operationlog\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\operationlog\archive\LogArchiver;

/**
 * 操作日志归档命令
 *
 * 用法：
 *   php think wise:operationlog:archive              # 执行归档
 *   php think wise:operationlog:archive --dry-run    # 试运行（仅统计）
 */
class Archive extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:operationlog:archive')
            ->addOption('dry-run', null, Option::VALUE_NONE, '试运行模式，仅统计待归档数量')
            ->setDescription('归档过期操作日志');
    }

    protected function execute(Input $input, Output $output): void
    {
        $config  = config('wise-operation-log.archive', []);
        $enabled = $config['enable'] ?? false;

        if (!$enabled) {
            $output->writeln('<comment>归档功能未启用，请在配置中设置 archive.enable = true</comment>');
            return;
        }

        $dryRun = $input->getOption('dry-run');
        $days   = $config['days'] ?? 90;

        $output->writeln(sprintf('<info>归档条件: 归档 %d 天前的日志</info>', $days));

        if ($dryRun) {
            $output->writeln('<comment>试运行模式：仅统计，不执行实际操作</comment>');
        }

        $archiver = new LogArchiver();
        $output->writeln('<info>正在归档操作日志...</info>');

        $result = $archiver->run($dryRun);

        $output->writeln(sprintf('<info>归档完成: 已归档 %d 条，已删除源表 %d 条</info>', $result['archived'], $result['deleted']));

        if (!empty($result['errors'])) {
            $output->writeln(sprintf('<error>部分批次失败（共 %d 个错误）:</error>', count($result['errors'])));
            foreach ($result['errors'] as $err) {
                $output->writeln("  <comment>  {$err}</comment>");
            }
        }
    }
}
