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
        $this->assertSame([], OperationLogListener::getBuffer());
    }

    public function testSegmentationFlush(): void
    {
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'configLoaded');
        $this->mergeConfig(['buffer' => ['max_size' => 2]]);

        $this->listener->handle(new OperationLogEvent(['content' => 's1']));
        $this->listener->handle(new OperationLogEvent(['content' => 's2']));
        $this->listener->handle(new OperationLogEvent(['content' => 's3']));

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
        $this->mergeConfig(['storage' => ['driver' => 'No\\Such\\Driver']]);
        OperationLogListener::resetStorage();

        $this->listener->handle(new OperationLogEvent(['content' => 'fallback']));
        OperationLogListener::flush();

        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testQueueEnabledBatchPush(): void
    {
        $this->mergeConfig(['queue' => ['enable' => true, 'queue_name' => 'operation_log'], 'buffer' => ['queue_batch' => true]]);
        OperationLogListener::resetStorage();

        $this->listener->handle(new OperationLogEvent(['content' => 'q-batch']));
        OperationLogListener::flush();

        $this->assertSame(1, OperationLogModel::createFromConfig()->count());
    }

    public function testQueueEnabledPerItemPush(): void
    {
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
