<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\attribute\OperationLog;
use wise\operationlog\tests\TestCase;

/**
 * OperationLog Attribute 单元测试
 */
class OperationLogAttributeTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attr = new OperationLog();
        $this->assertSame('', $attr->template);
        $this->assertSame('default', $attr->channel);
        $this->assertSame([], $attr->conditions);
        $this->assertFalse($attr->recordParams);
        $this->assertSame([], $attr->tags);
        $this->assertSame('INFO', $attr->level);
    }

    public function testCustomValues(): void
    {
        $attr = new OperationLog(
            template: '删除了{user.name}',
            channel: 'admin',
            conditions: ['response.code' => 0],
            recordParams: true,
            tags: ['a', 'b'],
            level: 'WARNING'
        );
        $this->assertSame('删除了{user.name}', $attr->template);
        $this->assertSame('admin', $attr->channel);
        $this->assertSame(['response.code' => 0], $attr->conditions);
        $this->assertTrue($attr->recordParams);
        $this->assertSame(['a', 'b'], $attr->tags);
        $this->assertSame('WARNING', $attr->level);
    }

    public function testRepeatableTarget(): void
    {
        $ref = new \ReflectionClass(OperationLog::class);
        $attrs = $ref->getAttributes();
        $this->assertNotEmpty($attrs);
    }
}
