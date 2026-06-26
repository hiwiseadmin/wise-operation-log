<?php

declare(strict_types=1);

namespace wise\operationlog\trait;

use think\console\Command;
use think\console\input\Option;
use think\console\Input;
use think\console\Output;

/**
 * WiseAdmin 包 Publish 命令共用 Trait
 *
 * 提取了所有 wise-* 包在 Publish（初始化发布）命令中反复出现的公共逻辑：
 *
 * 1. printBanner()           —— 打印命令行标准 Banner（带分隔线的初始化标题）
 * 2. printSuccessFooter()    —— 打印标准成功结束语 + 迁移执行提示
 * 3. publishConfig()         —— 发布配置文件到应用 config 目录
 * 4. publishMigrations()     —— 复制迁移文件到应用 database/migrations 目录
 * 5. addForceOption()        —— 添加 --force 选项的标准方法
 *
 * 用法示例（在任意 wise-* 包的 Publish 命令中）：
 *
 *   use wise\packagesupport\PackagePublishTrait;
 *
 *   protected function configure(): void
 *   {
 *       $this->setName('wise:operationlog:publish')
 *           ->setDescription('Publish config and migration files')
 *           ->addOption('force', 'f', Option::VALUE_NONE, 'Force overwrite');
 *   }
 *
 *   protected function execute(Input $input, Output $output): int
 *   {
 *       $force = $input->getOption('force');
 *
 *       $this->printBanner($output, 'wise-api-logger');
 *
 *       // 1. 发布配置文件
 *       $this->publishConfig($output, $force);
 *
 *       // 2. 复制迁移文件
 *       $migrationsPublished = $this->publishMigrations($output);
 *
 *       // 3. 打印完成提示
 *       $this->printSuccessFooter($output, 'API Logger', $migrationsPublished);
 *
 *       // 4. 可选：执行额外的包特有初始化逻辑
 *       // ...
 *
 *       return 0;
 *   }
 *
 * 注意：
 *   - 使用本 Trait 的类必须继承 think\console\Command（依赖 $this->app 获取路径）
 *   - 子类必须实现 getPackageConfigName() 和 getPackageRootDir()
 *   - 或者覆盖这两个方法来提供包特定的配置名和路径
 */
trait CommandTrait
{
    /* ------------------------------------------------
     *  抽象 / 可覆盖的配置方法
     * ---------------------------------------------- */

    /**
     * 获取包的配置文件名（不含 .php 后缀）
     *
     * 例如 'wiseapilogger' 对应 config/wiseapilogger.php
     *
     * @return string
     */
    abstract protected function getPackageConfigName(): string;

    /**
     * 获取包根目录的绝对路径（末尾不带分隔符）
     *
     * 默认实现：从当前文件向上两级（src/command/Publish.php → 包根目录）。
     * 子类可覆盖此方法自定义路径。
     *
     * @return string
     */
    protected function getPackageRootDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    /* ------------------------------------------------
     *  命令行 UI 输出方法
     * ---------------------------------------------- */

    /**
     * 打印标准初始化 Banner
     *
     * 输出格式：
     *   ========================================
     *   wiseadmin/{packageName} - Initialization
     *   ========================================
     *
     * @param Output $output
     * @param string $packageName 包名（用于显示），如 'wise-api-logger'
     */
    protected function printBanner(Output $output, string $packageName): void
    {
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln("<info>wiseadmin/{$packageName} - Initialization</info>");
        $output->writeln('<info>========================================</info>');
        $output->writeln('');
    }

    /**
     * 打印初始化成功的结束语
     *
     * 包含：
     *   1. 成功消息（绿色 info）
     *   2. 如果有迁移文件被复制，提示执行 `php think migrate:run`
     *
     * @param Output $output
     * @param string $label          初始化模块名称，如 'API Logger'
     * @param bool   $migrationsPublished 是否有迁移文件被复制
     */
    protected function printSuccessFooter(Output $output, string $label, bool $migrationsPublished = false): void
    {
        $output->writeln('');
        if ($migrationsPublished) {
            $output->writeln('<info>Please run the following command to execute migrations:</info>');
            $output->writeln('<comment>  php think migrate:run</comment>');
        }
        $output->writeln('');
        $output->writeln("<info>{$label} initialized successfully!</info>");
    }

    /* ------------------------------------------------
     *  配置文件发布
     * ---------------------------------------------- */

    /**
     * 发布配置文件到应用 config 目录
     *
     * 行为：
     *   - 源文件：{包根目录}/config/{configName}.php
     *   - 目标文件：{应用config目录}/{configName}.php
     *   - 如果目标已存在且未传 --force，输出警告并跳过
     *   - 自动创建目标目录（如不存在）
     *
     * @param Output $output
     * @param bool   $force 是否强制覆盖已存在的配置文件
     */
    protected function publishConfig(Output $output, bool $force): void
    {
        $configName = $this->getPackageConfigName();
        $source     = $this->getPackageRootDir() . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
        $target     = $this->app->getConfigPath() . $configName . '.php';

        // 源文件不存在
        if (!file_exists($source)) {
            $output->writeln("<error>Package config file not found: {$source}</error>");
            return;
        }

        // 目标已存在且非强制
        if (file_exists($target) && !$force) {
            $output->writeln("<comment>Config file already exists at {$target}. Use --force to overwrite.</comment>");
            return;
        }

        // 确保目标目录存在
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // 复制
        if (copy($source, $target)) {
            $output->writeln("<info>Config published to: {$target}</info>");
        } else {
            $output->writeln("<error>Failed to publish config to: {$target}</error>");
        }
    }

    /* ------------------------------------------------
     *  迁移文件发布
     * ---------------------------------------------- */

    /**
     * 复制包内迁移文件到应用 database/migrations 目录
     *
     * 行为：
     *   - 源目录：{包根目录}/database/migrations/
     *   - 目标目录：{应用根目录}/database/migrations/
     *   - 跳过已存在的文件（输出 [SKIP] 提示）
     *   - 自动创建目标目录（如不存在）
     *   - 输出每个文件的复制结果
     *
     * @param Output $output
     * @return bool 是否成功复制了至少一个文件
     */
    protected function publishMigrations(Output $output): bool
    {
        $sourceDir = $this->getPackageRootDir() . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $targetDir = $this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        // 源目录不存在
        if (!is_dir($sourceDir)) {
            return false;
        }

        // 确保目标目录存在
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $output->writeln("<error>Failed to create target directory: {$targetDir}</error>");
                return false;
            }
        }

        $files   = glob($sourceDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $copied  = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            $target   = $targetDir . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($target)) {
                $output->writeln("  <comment>[SKIP]</comment> {$filename} (already exists)");
                $skipped++;
                continue;
            }

            if (copy($file, $target)) {
                $output->writeln("  <info>[OK]</info>   {$filename}");
                $copied++;
            } else {
                $output->writeln("  <error>[FAIL]</error> {$filename}");
            }
        }

        if ($copied > 0 || $skipped > 0) {
            $output->writeln('');
            $output->writeln("<info>Migration files published: {$copied} copied, {$skipped} skipped</info>");
            $output->writeln("<info>Target directory: {$targetDir}</info>");
        }

        return $copied > 0;
    }
}
