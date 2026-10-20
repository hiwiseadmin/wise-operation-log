<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Fixtures;

use think\Response;

/**
 * 测试用 Response 替身
 *
 * think\Response 为抽象类且无构造函数，直接通过 Response::create() 会走容器解析。
 * 此替身直接填充受保护属性，避免容器递归并保证 getContent()/getCode() 行为可控。
 */
class TestResponse extends Response
{
    public function __construct($data = [], int $code = 200, ?string $rawContent = null)
    {
        $this->data = $data;
        $this->code = $code;
        $this->content = $rawContent;
    }
}
