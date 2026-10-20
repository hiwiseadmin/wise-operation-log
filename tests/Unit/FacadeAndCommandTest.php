<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use think\console\Input;
use think\console\Output;
use wise\operationlog\command\Anomaly;
use wise\operationlog\command\Archive;
use wise\operationlog\command\Clean;
use wise\operationlog\command\Report;
use wise\operationlog\facade\OperationLog as OperationLogFacade;
use wise\operationlog\manage\LogManager;
use wise\operationlog\model\OperationLogModel;
use wise\operationlog\tests\TestCase;

/**
 * OperationLog 门面 + CLI 命令单元测试
 */
class FacadeAndCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
        \think\facade\Db::table('ws_operation_log_archive')->where('id', '>', 0)->delete();
    }

    // ---------------- Facade ----------------

    public function testFacadeExtendsThinkFacade(): void
    {
        $this->assertInstanceOf(\think\Facade::class, new OperationLogFacade());
    }

    public function testFacadeGetFacadeClass(): void
    {
        $ref = new \ReflectionMethod(OperationLogFacade::class, 'getFacadeClass');
        $ref->setAccessible(true);
        $this->assertSame(LogManager::class, $ref->invoke(null));
    }

    // ---------------- CaptureOutput ----------------

    private function makeOutput(): object
    {
        return new class extends Output {
            public array $lines = [];

            public function __construct()
            {
                parent::__construct('buffer');
            }

            public function writeln(string $messages, int $type = 0): void
            {
                $this->lines[] = $messages;
            }

            public function getCaptured(): string
            {
                return implode("\n", $this->lines);
            }
        };
    }

    // ---------------- Anomaly 命令 ----------------

    public function testAnomalyInvalidType(): void
    {
        $output = $this->makeOutput();
        $cmd = new Anomaly();
        $cmd->run(new Input(['--type=bad']), $output);
        $this->assertStringContainsString('无效的检测类型', $output->getCaptured());
    }

    public function testAnomalyNoAnomaly(): void
    {
        $output = $this->makeOutput();
        $cmd = new Anomaly();
        $cmd->run(new Input([]), $output);
        $this->assertStringContainsString('未发现异常', $output->getCaptured());
    }

    public function testAnomalyFrequencyFound(): void
    {
        // 写入近期日志，触发频率异常
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'channel' => 'admin', 'level' => 'INFO', 'operator_id' => 1, 'operator_name' => 'alice',
                'request_url' => '/x', 'ip' => '1.1.1.1', 'user_agent' => 'ua', 'tags' => '',
                'content' => 'hello', 'params' => '', 'extra' => '', 'duration_ms' => 10,
                'create_time' => date('Y-m-d H:i:s'),
            ];
        }
        OperationLogModel::createFromConfig()->db()->insertAll($rows);

        $output = $this->makeOutput();
        $cmd = new Anomaly();
        $cmd->run(new Input(['--type=frequency']), $output);
        $this->assertStringContainsString('频率异常', $output->getCaptured());
    }

    public function testAnomalyLevelFound(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = [
                'channel' => 'admin', 'level' => 'ERROR', 'operator_id' => 5, 'operator_name' => 'eve',
                'request_url' => '/x', 'ip' => '5.5.5.5', 'user_agent' => 'ua', 'tags' => '',
                'content' => 'err', 'params' => '', 'extra' => '', 'duration_ms' => 10,
                'create_time' => date('Y-m-d H:i:s'),
            ];
        }
        OperationLogModel::createFromConfig()->db()->insertAll($rows);

        $output = $this->makeOutput();
        $cmd = new Anomaly();
        $cmd->run(new Input(['--type=level']), $output);
        $this->assertStringContainsString('级别异常', $output->getCaptured());
    }

    public function testAnomalyOffHourFound(): void
    {
        OperationLogModel::createFromConfig()->db()->insertAll([[
            'channel' => 'admin', 'level' => 'WARNING', 'operator_id' => 9, 'operator_name' => 'night',
            'request_url' => '/x', 'ip' => '9.9.9.9', 'user_agent' => 'ua', 'tags' => '',
            'content' => 'night op', 'params' => '', 'extra' => '', 'duration_ms' => 10,
            'create_time' => date('Y-m-d 23:00:00'),
        ]]);

        $output = $this->makeOutput();
        $cmd = new Anomaly();
        $cmd->run(new Input(['--type=offhour']), $output);
        $this->assertStringContainsString('夜间操作', $output->getCaptured());
    }

    // ---------------- Clean 命令 ----------------

    public function testCleanNoArgsError(): void
    {
        $this->mergeConfig(['ttl' => ['days' => 0, 'max_records' => 0]]);
        $output = $this->makeOutput();
        $cmd = new Clean();
        $cmd->run(new Input([]), $output);
        $this->assertStringContainsString('请指定保留天数', $output->getCaptured());
    }

    public function testCleanDays(): void
    {
        $old = ['create_time' => '2000-01-01 00:00:00', 'channel' => 'admin', 'level' => 'INFO', 'operator_id' => 1, 'operator_name' => 'a', 'request_url' => '/x', 'ip' => '1.1.1.1', 'user_agent' => 'ua', 'tags' => '', 'content' => 'c', 'params' => '', 'extra' => '', 'duration_ms' => 1];
        OperationLogModel::createFromConfig()->db()->insertAll([$old, array_merge($old, ['create_time' => date('Y-m-d H:i:s')])]);

        $output = $this->makeOutput();
        $cmd = new Clean();
        $cmd->run(new Input(['30']), $output);
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
        $this->assertStringContainsString('已删除', $output->getCaptured());
    }

    public function testCleanWithMaxRecords(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'channel' => 'admin', 'level' => 'INFO', 'operator_id' => 1, 'operator_name' => 'a',
                'request_url' => '/x', 'ip' => '1.1.1.1', 'user_agent' => 'ua', 'tags' => '',
                'content' => 'c', 'params' => '', 'extra' => '', 'duration_ms' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ];
        }
        OperationLogModel::createFromConfig()->db()->insertAll($rows);

        $output = $this->makeOutput();
        $cmd = new Clean();
        // 保留 30 天 + 最多 1 条 → 清掉 2 条
        $cmd->run(new Input(['30', '--max=1']), $output);
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
        $this->assertStringContainsString('保留策略', $output->getCaptured());
    }

    // ---------------- Archive 命令 ----------------

    public function testArchiveNotEnabled(): void
    {
        $this->mergeConfig(['archive' => ['enable' => false]]);
        $output = $this->makeOutput();
        $cmd = new Archive();
        $cmd->run(new Input([]), $output);
        $this->assertStringContainsString('未启用', $output->getCaptured());
    }

    public function testArchiveEnabled(): void
    {
        $old = ['create_time' => '2000-01-01 00:00:00', 'channel' => 'admin', 'level' => 'INFO', 'operator_id' => 1, 'operator_name' => 'a', 'request_url' => '/x', 'ip' => '1.1.1.1', 'user_agent' => 'ua', 'tags' => '', 'content' => 'c', 'params' => '', 'extra' => '', 'duration_ms' => 1];
        OperationLogModel::createFromConfig()->db()->insertAll([$old, $old]);

        $this->mergeConfig(['archive' => ['enable' => true, 'days' => 90, 'target' => ['type' => 'table', 'table_name' => 'operation_log_archive'], 'after_archive' => ['delete_source' => true]]]);
        $output = $this->makeOutput();
        $cmd = new Archive();
        $cmd->run(new Input([]), $output);
        $this->assertSame(0, OperationLogModel::createFromConfig()->count());
        $this->assertSame(2, \think\facade\Db::table('ws_operation_log_archive')->count());
        $this->assertStringContainsString('归档完成', $output->getCaptured());
    }

    // ---------------- Report 命令 ----------------

    public function testReportDefault(): void
    {
        $output = $this->makeOutput();
        $cmd = new Report();
        $cmd->run(new Input([]), $output);
        $this->assertStringContainsString('基础统计', $output->getCaptured());
        $this->assertStringContainsString('总日志数', $output->getCaptured());
    }

    public function testReportErrorRate(): void
    {
        $output = $this->makeOutput();
        $cmd = new Report();
        $cmd->run(new Input(['--error-rate', '--start=2000-01-01', '--end=2099-12-31']), $output);
        $this->assertStringContainsString('错误率', $output->getCaptured());
    }

    public function testReportAll(): void
    {
        $output = $this->makeOutput();
        $cmd = new Report();
        $cmd->run(new Input(['--all', '--start=2000-01-01', '--end=2099-12-31', '--top=5']), $output);
        $captured = $output->getCaptured();
        $this->assertStringContainsString('基础统计', $captured);
        $this->assertStringContainsString('趋势分析', $captured);
        $this->assertStringContainsString('热点分析', $captured);
        $this->assertStringContainsString('错误率', $captured);
    }
}
