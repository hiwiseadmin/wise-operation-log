<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\tests\TestCase;
use wise\operationlog\traits\DotPathTrait;

/**
 * DotPathTrait 单元测试
 *
 * 通过宿主类 DotPathHolder 使用 trait，验证数组 / 对象 / ArrayAccess / 嵌套 / 缺失路径等。
 */
class DotPathTraitTest extends TestCase
{
    public function testGetFromArray(): void
    {
        $holder = new DotPathHolder();
        $data = ['user' => ['name' => 'alice', 'addr' => ['city' => 'BJ']]];
        $this->assertSame('alice', $holder->get($data, 'user.name'));
        $this->assertSame('BJ', $holder->get($data, 'user.addr.city'));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $holder = new DotPathHolder();
        $this->assertNull($holder->get(['a' => 1], 'a.b'));
        $this->assertNull($holder->get(['a' => ['b' => 1]], 'x'));
    }

    public function testGetNullDataReturnsNull(): void
    {
        $holder = new DotPathHolder();
        $this->assertNull($holder->get(null, 'a'));
    }

    public function testGetFromObject(): void
    {
        $holder = new DotPathHolder();
        $obj = new \stdClass();
        $obj->name = 'bob';
        $this->assertSame('bob', $holder->get($obj, 'name'));
    }

    public function testGetFromObjectNested(): void
    {
        $holder = new DotPathHolder();
        $inner = new \stdClass();
        $inner->city = 'SH';
        $obj = new \stdClass();
        $obj->addr = $inner;
        $this->assertSame('SH', $holder->get($obj, 'addr.city'));
    }

    public function testGetFromArrayAccess(): void
    {
        $holder = new DotPathHolder();
        $map = new \ArrayObject(['k' => ['v' => 42]]);
        $this->assertSame(42, $holder->get($map, 'k.v'));
    }

    public function testGetScalarMidPathReturnsNull(): void
    {
        $holder = new DotPathHolder();
        $this->assertNull($holder->get(['a' => 1], 'a.b'));
    }

    public function testGetObjectMissingPropertyReturnsNull(): void
    {
        $holder = new DotPathHolder();
        $obj = new \stdClass();
        $this->assertNull($holder->get($obj, 'nope'));
    }
}

/**
 * 使用 DotPathTrait 的宿主类（仅用于测试）
 */
class DotPathHolder
{
    use DotPathTrait;

    /**
     * 对外暴露受保护的 trait 方法，便于测试。
     */
    public function get(mixed $data, string $path): mixed
    {
        return $this->getByDotPath($data, $path);
    }
}
