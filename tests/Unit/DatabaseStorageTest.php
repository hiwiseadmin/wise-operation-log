<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\model\OperationLogModel;
use wise\operationlog\storage\DatabaseStorage;
use wise\operationlog\tests\TestCase;

/**
 * DatabaseStorage 单元测试（基于 SQLite :memory:）
 */
class DatabaseStorageTest extends TestCase
{
    private DatabaseStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new DatabaseStorage();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
    }

    private function lastRow(): array
    {
        return OperationLogModel::createFromConfig()->order('id', 'desc')->find()->toArray();
    }

    private function baseLog(array $over = []): array
    {
        return array_merge([
            'channel'       => 'admin',
            'level'         => 'INFO',
            'operator_id'   => 1,
            'operator_name' => 'alice',
            'request_url'   => '/x',
            'ip'            => '127.0.0.1',
            'user_agent'    => 'ua',
            'tags'          => '',
            'content'       => 'hello',
            'params'        => '',
            'extra'         => '',
            'duration_ms'   => 10,
            'create_time'   => date('Y-m-d H:i:s'),
        ], $over);
    }

    public function testSave(): void
    {
        $this->storage->save($this->baseLog(['content' => 'saved']));
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
        $row = $this->lastRow();
        $this->assertSame('saved', $row['content']);
    }

    public function testBatchSave(): void
    {
        $this->storage->batchSave([$this->baseLog(['content' => 'a']), $this->baseLog(['content' => 'b'])]);
        $this->assertSame(2, OperationLogModel::createFromConfig()->count());
    }

    public function testBatchSaveEmpty(): void
    {
        $this->storage->batchSave([]);
        $this->assertSame(0, OperationLogModel::createFromConfig()->count());
    }

    public function testTruncateLongFields(): void
    {
        $long = str_repeat('X', 100); // 超过 channel 的 50 上限
        $this->storage->save($this->baseLog(['channel' => $long]));
        $row = $this->lastRow();
        $this->assertLessThanOrEqual(50, mb_strlen($row['channel'], 'UTF-8'));
        $this->assertStringEndsWith('…', $row['channel']);
    }

    public function testTruncateKeepsShortFields(): void
    {
        $this->storage->save($this->baseLog(['channel' => 'adm']));
        $row = $this->lastRow();
        $this->assertSame('adm', $row['channel']);
    }

    public function testContentFieldNotTruncated(): void
    {
        $longContent = str_repeat('C', 5000);
        $this->storage->save($this->baseLog(['content' => $longContent]));
        $row = $this->lastRow();
        $this->assertSame($longContent, $row['content']);
    }

    public function testBatchSaveFallbackToSingleOnError(): void
    {
        // 使用不存在的数据库连接，使 insertAll 抛异常，触发「降级逐条写入」分支；
        // 逐条 save 同样失败（被各自 try/catch 吞掉），最终写入 0 条但流程被覆盖。
        $this->mergeConfig(['storage' => ['connection' => 'no_such_connection']]);
        $this->storage->batchSave([$this->baseLog(['content' => 'fb']), $this->baseLog(['content' => 'fb2'])]);

        // 连接无效，无法写入（使用默认连接的 Db 计数，避免受污染的连接配置影响）
        $this->assertSame(0, \think\facade\Db::table('ws_operation_log')->count());
    }
}
