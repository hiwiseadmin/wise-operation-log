<?php
declare(strict_types=1);
namespace wise\operationlog\command;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\operationlog\trait\CommandTrait;

class Publish extends Command
{
    use CommandTrait;

    protected function configure(): void
    {
        $this->setName('wise:operationlog:publish')
            ->setDescription('发布操作日志配置文件及数据库迁移文件')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的配置文件');
    }

    protected function getPackageConfigName(): string
    {
        return 'wise-operation-log';
    }

    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');
        $this->printBanner($output, 'wise-operation-log');

        // 发布配置和迁移文�?        $this->publishConfig($output, $force);
        $migrationsPublished = $this->publishMigrations($output);

        $this->printSuccessFooter($output, '操作日志', $migrationsPublished);
        return 0;
    }
}
