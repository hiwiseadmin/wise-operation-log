<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use wise\operationlog\template\VariableParser;
use wise\operationlog\tests\TestCase;

/**
 * VariableParser 单元测试
 *
 * 覆盖：render（占位符解析 / 过滤器 default|date / 自定义过滤器 / $sys. / response. / context / method_params / request_params 优先级）、
 * registerFilter / resetConfigLoaded / 单例。
 */
class VariableParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VariableParser::resetConfigLoaded();
    }

    public function testRenderSimpleVariable(): void
    {
        $out = VariableParser::render('用户{name}创建了{target}', ['request_params' => ['name' => 'alice', 'target' => '订单']]);
        $this->assertSame('用户alice创建了订单', $out);
    }

    public function testRenderNestedPath(): void
    {
        $out = VariableParser::render('用户{user.name}', ['request_params' => ['user' => ['name' => 'bob']]]);
        $this->assertSame('用户bob', $out);
    }

    public function testRenderMissingReturnsEmpty(): void
    {
        $out = VariableParser::render('x={missing}', []);
        $this->assertSame('x=', $out);
    }

    public function testRenderDefaultFilter(): void
    {
        $out = VariableParser::render('v={name|default:"匿名"}', ['request_params' => []]); // name 缺失
        $this->assertSame('v=匿名', $out);
        $out2 = VariableParser::render('v={name|default:"匿名"}', ['request_params' => ['name' => 'tom']]); // name 存在
        $this->assertSame('v=tom', $out2);
    }

    public function testRenderDefaultFilterEmptyString(): void
    {
        $out = VariableParser::render('v={name|default:"匿名"}', ['request_params' => ['name' => '']]);
        $this->assertSame('v=匿名', $out);
    }

    public function testRenderDateFilterTimestamp(): void
    {
        $out = VariableParser::render('t={ts|date:"Y-m-d"}', ['request_params' => ['ts' => 0]]);
        $this->assertSame('t=1970-01-01', $out);
    }

    public function testRenderDateFilterString(): void
    {
        $out = VariableParser::render('t={d|date:"Y"}', ['request_params' => ['d' => '2026-06-01 12:00:00']]);
        $this->assertSame('t=2026', $out);
    }

    public function testRenderDateFilterInvalid(): void
    {
        $out = VariableParser::render('t={d|date:"Y"}', ['request_params' => ['d' => 'not-a-date']]);
        $this->assertSame('t=not-a-date', $out);
    }

    public function testRenderChainedFilters(): void
    {
        $out = VariableParser::render('v={name|default:"匿名"|default:"x"}', ['request_params' => []]);
        $this->assertSame('v=匿名', $out);
    }

    public function testRenderSysVar(): void
    {
        $out = VariableParser::render('时间{$sys.datetime}', ['$sys' => ['datetime' => '2026-01-01 00:00:00']]);
        $this->assertSame('时间2026-01-01 00:00:00', $out);
    }

    public function testRenderResponseVar(): void
    {
        $out = VariableParser::render('code={response.code}', ['response' => ['code' => 200]]);
        $this->assertSame('code=200', $out);
    }

    public function testRenderVariablePriorityContextOverMethodParams(): void
    {
        $out = VariableParser::render('{id}', [
            'context'      => ['id' => 'ctx'],
            'method_params' => ['id' => 'mp'],
            'request_params' => ['id' => 'rp'],
        ]);
        $this->assertSame('ctx', $out);
    }

    public function testRenderVariablePriorityMethodParamsOverRequest(): void
    {
        $out = VariableParser::render('{id}', [
            'method_params'  => ['id' => 'mp'],
            'request_params' => ['id' => 'rp'],
        ]);
        $this->assertSame('mp', $out);
    }

    public function testRenderVariablePriorityRequest(): void
    {
        $out = VariableParser::render('{id}', ['request_params' => ['id' => 'rp']]);
        $this->assertSame('rp', $out);
    }

    public function testRenderCustomFilter(): void
    {
        VariableParser::registerFilter('upper', fn($v) => strtoupper((string) $v));
        $out = VariableParser::render('v={name|upper}', ['request_params' => ['name' => 'abc']]);
        $this->assertSame('v=ABC', $out);
    }

    public function testRenderCustomFilterWithArg(): void
    {
        VariableParser::registerFilter('repeat', fn($v, $arg) => str_repeat((string) $v, (int) ($arg ?? 1)));
        $out = VariableParser::render('v={x|repeat:3}', ['request_params' => ['x' => 'ab']]);
        $this->assertSame('v=ababab', $out);
    }

    public function testRegisterFilterOverridesBuiltin(): void
    {
        VariableParser::registerFilter('default', fn($v) => 'OVERRIDDEN');
        $out = VariableParser::render('v={name|default:"x"}', []);
        $this->assertSame('v=OVERRIDDEN', $out);
    }

    public function testResetConfigLoadedClearsInstance(): void
    {
        $out1 = VariableParser::render('{a}', ['request_params' => ['a' => 1]]);
        $this->assertSame('1', $out1);
        VariableParser::resetConfigLoaded();
        $out2 = VariableParser::render('{b}', ['request_params' => ['b' => 2]]);
        $this->assertSame('2', $out2);
    }
}
