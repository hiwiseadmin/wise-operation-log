<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\context\LogContext;
use wise\operationlog\tests\TestCase;

/**
 * LogContext 单元测试
 *
 * 测试环境为 PHP-FPM（未加载 Swoole 扩展），走静态数组存储分支。
 */
class LogContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogContext::clear();
    }

    protected function tearDown(): void
    {
        LogContext::clear();
        parent::tearDown();
    }

    public function testSetAndGet(): void
    {
        LogContext::set('operator_id', 42);
        $this->assertSame(42, LogContext::get('operator_id'));
    }

    public function testGetMissingReturnsDefault(): void
    {
        $this->assertSame('fallback', LogContext::get('nope', 'fallback'));
        $this->assertNull(LogContext::get('nope'));
    }

    public function testAll(): void
    {
        LogContext::set('a', 1);
        LogContext::set('b', 2);
        $all = LogContext::all();
        $this->assertSame(1, $all['a']);
        $this->assertSame(2, $all['b']);
    }

    public function testOverwrite(): void
    {
        LogContext::set('k', 'v1');
        LogContext::set('k', 'v2');
        $this->assertSame('v2', LogContext::get('k'));
    }

    public function testClear(): void
    {
        LogContext::set('k', 'v');
        LogContext::clear();
        $this->assertNull(LogContext::get('k'));
        $this->assertSame([], LogContext::all());
    }
}
