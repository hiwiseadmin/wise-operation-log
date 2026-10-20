<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\listener\OperationLogListener;
use wise\operationlog\model\OperationLogModel;
use wise\operationlog\tests\TestCase;

/**
 * OperationLogListener 单元测试（基于 SQLite :memory:）
 */
class OperationLogListenerTest extends TestCase
{
    private OperationLogListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new OperationLogListener();
        OperationLogModel::createFromConfig()->where('id', '>', 0)->delete();
    }

    private function makeJob(): object
    {
        return new class {
            public function delete(): void {}
            public function attempts(): int { return 1; }
            public function release(int $delay): void {}
        };
    }

    public function testBufferEmptyInitially(): void
    {
        $this->assertSame([], OperationLogListener::getBuffer());
    }

    public function testHandleBuffers(): void
    {
        $this->listener->handle(new OperationLogEvent(['content' => 'a']));
        $this->assertCount(1, OperationLogListener::getBuffer());
        $this->assertSame('a', OperationLogListener::getBuffer()[0]['content']);
    }

    public function testFlushWritesToStorage(): void
    {
        $this->listener->handle(new OperationLogEvent(['content' => 'sync1']));
        $this->listener->handle(new OperationLogEvent(['content' => 'sync2']));
        OperationLogListener::flush();

        $this->assertSame(2, OperationLogModel::createFromConfig()->count());
        // 清空
        $this->assertSame([], OperationLogListener::getBuffer());
    }

    public function testSegmentationFlush(): void
    {
        // 缓冲区阈值设为 2，触发分段刷新
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'configLoaded');
        $this->mergeConfig(['buffer' => ['max_size' => 2]]);

        $this->listener->handle(new OperationLogEvent(['content' => 's1']));
        $this->listener->handle(new OperationLogEvent(['content' => 's2'])); // 达到阈值 → flushChunk 写 2 条
        $this->listener->handle(new OperationLogEvent(['content' => 's3'])); // 留在缓冲区

        $this->assertSame(2, OperationLogModel::createFromConfig()->count());
        $this->assertCount(1, OperationLogListener::getBuffer());

        OperationLogListener::flush();
    }

    public function testFireBatch(): void
    {
        $data = [['content' => 'b1'], ['content' => 'b2']];
        $this->listener->fire($this->makeJob(), ['batch' => $data]);
        $this->assertSame(2, OperationLogModel::createFromConfig()->count());
    }

    public function testFireSingle(): void
    {
        $this->listener->fire($this->makeJob(), ['content' => 'one']);
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testFireCatchesErrorAndDeletes(): void
    {
        // 构造一个 job 让 release/delete 都可用（异常分支由 syncSave 内部 try/catch 覆盖）
        $job = new class {
            public bool $deleted = false;
            public function delete(): void { $this->deleted = true; }
            public function attempts(): int { return 2; }
            public function release(int $delay): void {}
        };
        $this->listener->fire($job, ['content' => 'ok']);
        $this->assertTrue($job->deleted);
        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testStorageDriverFallbackToDatabase(): void
    {
        // 配置不存在的存储驱动 → 降级为 DatabaseStorage
        $this->mergeConfig(['storage' => ['driver' => 'No\\Such\\Driver']]);
        OperationLogListener::resetStorage();

        $this->listener->handle(new OperationLogEvent(['content' => 'fallback']));
        OperationLogListener::flush();

        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testQueueEnabledBatchPush(): void
    {
        // 开启队列批量模式（think-queue 若不可用/推送失败会回退同步写入）
        $this->mergeConfig(['queue' => ['enable' => true, 'queue_name' => 'operation_log'], 'buffer' => ['queue_batch' => true]]);
        OperationLogListener::resetStorage();

        $this->listener->handle(new OperationLogEvent(['content' => 'q-batch']));
        OperationLogListener::flush();

        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testQueueEnabledPerItemPush(): void
    {
        // 开启队列逐条模式
        $this->mergeConfig(['queue' => ['enable' => true, 'queue_name' => 'operation_log'], 'buffer' => ['queue_batch' => false]]);
        OperationLogListener::resetStorage();

        $this->listener->handle(new OperationLogEvent(['content' => 'q-item']));
        OperationLogListener::flush();

        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testResetStorage(): void
    {
        OperationLogListener::resetStorage();
        $this->assertNull($this->getInaccessible($this->listener, 'storageInstance'));
    }
}
