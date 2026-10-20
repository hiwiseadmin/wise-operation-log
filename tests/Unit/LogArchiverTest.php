<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\archive\LogArchiver;
use wise\operationlog\model\OperationLogModel;
use wise\operationlog\tests\TestCase;

/**
 * LogArchiver 单元测试（基于 SQLite :memory:）
 */
class LogArchiverTest extends TestCase
{
    private LogArchiver $archiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiver = new LogArchiver();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
        \think\facade\Db::table('ws_operation_log_archive')->where('id', '>', 0)->delete();
    }

    private function seedOld(int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'channel'       => 'admin',
                'level'         => 'INFO',
                'operator_id'   => 1,
                'operator_name' => 'alice',
                'request_url'   => '/x',
                'ip'            => '127.0.0.1',
                'user_agent'    => 'ua',
                'tags'          => '',
                'content'       => 'old',
                'params'        => '',
                'extra'         => '',
                'duration_ms'   => 10,
                'create_time'   => '2000-01-01 00:00:00',
            ];
        }
        OperationLogModel::createFromConfig()->db()->insertAll($rows);
    }

    private function archiveConfig(string $type, bool $deleteSource = true): array
    {
        return [
            'enable'        => true,
            'days'          => 90,
            'target'        => ['type' => $type, 'table_name' => 'operation_log_archive'],
            'after_archive' => ['delete_source' => $deleteSource],
        ];
    }

    public function testRunEmpty(): void
    {
        $this->mergeConfig(['archive' => $this->archiveConfig('table')]);
        $res = $this->archiver->run();
        $this->assertSame(['archived' => 0, 'deleted' => 0, 'errors' => []], $res);
    }

    public function testRunDryRun(): void
    {
        $this->seedOld(3);
        $this->mergeConfig(['archive' => $this->archiveConfig('table')]);
        $res = $this->archiver->run(true);
        $this->assertSame(3, $res['archived']);
        $this->assertSame(3, $res['deleted']);
        // dryRun 不删除源
        $this->assertSame(3, OperationLogModel::createFromConfig()->count());
    }

    public function testRunTableTarget(): void
    {
        $this->seedOld(3);
        $this->mergeConfig(['archive' => $this->archiveConfig('table', true)]);
        $res = $this->archiver->run();
        $this->assertSame(3, $res['archived']);
        $this->assertSame(3, $res['deleted']);
        $this->assertSame(0, OperationLogModel::createFromConfig()->count());
        $this->assertSame(3, \think\facade\Db::table('ws_operation_log_archive')->count());
    }

    public function testRunTableTargetKeepSource(): void
    {
        $this->seedOld(2);
        $this->mergeConfig(['archive' => $this->archiveConfig('table', false)]);
        $res = $this->archiver->run();
        $this->assertSame(2, $res['archived']);
        $this->assertSame(0, $res['deleted']);
        $this->assertSame(2, OperationLogModel::createFromConfig()->count());
    }

    public function testRunCsvTarget(): void
    {
        $this->seedOld(2);
        $this->mergeConfig(['archive' => $this->archiveConfig('csv', true)]);
        $res = $this->archiver->run();
        $this->assertSame(2, $res['archived']);
        $this->assertSame(2, $res['deleted']);
        $this->assertSame(0, OperationLogModel::createFromConfig()->count());

        // 文件应已生成
        $runtime = rtrim(app()->getRuntimePath(), '/\\');
        $dir = $runtime . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'operation_log';
        $files = glob($dir . DIRECTORY_SEPARATOR . 'operation_log_*.csv');
        $this->assertNotEmpty($files);
    }

    public function testRunJsonTarget(): void
    {
        $this->seedOld(2);
        $this->mergeConfig(['archive' => $this->archiveConfig('json', true)]);
        $res = $this->archiver->run();
        $this->assertSame(2, $res['archived']);
        $this->assertSame(0, OperationLogModel::createFromConfig()->count());

        $runtime = rtrim(app()->getRuntimePath(), '/\\');
        $dir = $runtime . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'operation_log';
        $files = glob($dir . DIRECTORY_SEPARATOR . 'operation_log_*.jsonl');
        $this->assertNotEmpty($files);
    }

    public function testWriteToTargetInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invokeInaccessible($this->archiver, 'writeToTarget', [[['id' => 1]], 'ftp']);
    }

    public function testGetFilePathOutsideRuntimeThrows(): void
    {
        $this->mergeConfig(['archive' => ['target' => ['file_path' => 'C:\\not\\under\\runtime']]]);
        $this->expectException(\RuntimeException::class);
        $this->invokeInaccessible($this->archiver, 'getFilePath', []);
    }

    public function testGetFilePathSiblingPrefixRejected(): void
    {
        // 回归：仅前缀相同的兄弟目录（runtime_evil）不得通过校验
        $runtime = rtrim(app()->getRuntimePath(), '/\\');
        $sibling = $runtime . '_evil' . DIRECTORY_SEPARATOR . 'archive';
        $this->mergeConfig(['archive' => ['target' => ['file_path' => $sibling]]]);

        $this->expectException(\RuntimeException::class);
        $this->invokeInaccessible($this->archiver, 'getFilePath', []);
    }

    public function testCsvFormulaInjectionEscaped(): void
    {
        // 回归：以 = 开头的日志内容在 CSV 归档时必须加前导单引号，防止公式注入
        OperationLogModel::createFromConfig()->db()->insertAll([[
            'channel'       => 'admin',
            'level'         => 'INFO',
            'operator_id'   => 1,
            'operator_name' => 'alice',
            'request_url'   => '/x',
            'ip'            => '127.0.0.1',
            'user_agent'    => 'ua',
            'tags'          => '',
            'content'       => "=cmd|' /C calc'!A0",
            'params'        => '',
            'extra'         => '',
            'duration_ms'   => 1,
            'create_time'   => '2000-01-01 00:00:00',
        ]]);

        $this->mergeConfig(['archive' => $this->archiveConfig('csv', true)]);

        $runtime = rtrim(app()->getRuntimePath(), '/\\');
        $dir     = $runtime . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . 'operation_log';
        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $file) {
                @unlink($file);
            }
        }

        $res = $this->archiver->run();
        $this->assertSame(1, $res['archived']);

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.csv') ?: [];
        $this->assertNotEmpty($files);

        $csv = (string) file_get_contents($files[0]);
        $this->assertStringContainsString("'=cmd", $csv);
    }

    public function testRunChunkErrorCaptured(): void
    {
        // 触发写目标异常：使用非法 target type，run 捕获后计入 errors
        $this->seedOld(1);
        $this->mergeConfig(['archive' => $this->archiveConfig('ftp', true)]);
        $res = $this->archiver->run();
        $this->assertNotEmpty($res['errors']);
    }
}
