<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\event\OperationLogSavingEvent;
use wise\operationlog\tests\TestCase;

/**
 * 操作日志事件单元测试（构造 + 数据可变）
 */
class EventTest extends TestCase
{
    public function testOperationLogEventConstructionAndMutate(): void
    {
        $event = new OperationLogEvent(['a' => 1]);
        $this->assertSame(['a' => 1], $event->logData);

        $event->logData = ['b' => 2];
        $this->assertSame(['b' => 2], $event->logData);
    }

    public function testOperationLogEventEmptyDefault(): void
    {
        $event = new OperationLogEvent();
        $this->assertSame([], $event->logData);
    }

    public function testSavingEventConstructionAndMutate(): void
    {
        $event = new OperationLogSavingEvent(['x' => 'y']);
        $this->assertSame(['x' => 'y'], $event->logData);

        $event->logData['extra'] = 'z';
        $this->assertSame('z', $event->logData['extra']);
    }

    public function testSavingEventEmptyDefault(): void
    {
        $event = new OperationLogSavingEvent();
        $this->assertSame([], $event->logData);
    }
}
