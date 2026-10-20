<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use Closure;
use think\Response;
use wise\operationlog\middleware\AuditLog;
use wise\operationlog\tests\TestCase;

/**
 * AuditLog 中间件单元测试（基于 SQLite :memory:）
 */
class AuditLogTest extends TestCase
{
    private AuditLog $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new AuditLog();
        \think\facade\Db::name('audit_log')->where('id', '>', 0)->delete();
    }

    private function next(Response $response): Closure
    {
        return function () use ($response) {
            return $response;
        };
    }

    // ---------------- handle 主流程分支 ----------------

    public function testHandleDisabledReturnsResponse(): void
    {
        $this->mergeConfig(['audit' => ['enabled' => false]]);
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x']);
        $response = $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([], AuditLog::getBuffer());
    }

    public function testHandleMethodNotAllowed(): void
    {
        $request = $this->makeRequest('http://localhost/admin/x', 'GET', [], [], '', ['pathinfo' => 'admin/x']);
        $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertSame([], AuditLog::getBuffer());
    }

    public function testHandleExcludedPath(): void
    {
        $this->mergeConfig(['audit' => ['enabled' => true, 'exclude' => ['admin/login/*'], 'methods' => ['POST']]]);
        $request = $this->makeRequest('http://localhost/admin/login/index', 'POST', [], [], '', ['pathinfo' => 'admin/login/index']);
        $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertSame([], AuditLog::getBuffer());
    }

    public function testHandleBuffersAndFlush(): void
    {
        $this->mergeConfig(['audit' => ['enabled' => true, 'methods' => ['POST'], 'buffer_size' => 200, 'exclude' => []]]);
        $request = $this->makeRequest('http://localhost/admin/user/delete', 'POST', ['id' => 5], ['user-agent' => 'TestAgent'], '', [
            'pathinfo' => 'admin/user/delete',
            'controller' => 'sample',
        ]);
        $this->middleware->handle($request, $this->next($this->makeResponse()));

        // 单条未达 buffer_size，留在缓冲区
        $this->assertCount(1, AuditLog::getBuffer());

        AuditLog::flush();
        $this->assertSame(1, \think\facade\Db::name('audit_log')->count());
        $row = \think\facade\Db::name('audit_log')->find(1);
        $this->assertSame('200', (string) $row['status_code']);
        $this->assertSame('success', $row['result']);
        $this->assertSame('POST', $row['method']);
    }

    public function testAutoFlushWhenBufferFull(): void
    {
        $this->mergeConfig(['audit' => ['enabled' => true, 'methods' => ['POST'], 'buffer_size' => 2, 'exclude' => []]]);

        $mk = fn() => $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x']);
        $this->middleware->handle($mk(), $this->next($this->makeResponse())); // 1
        $this->middleware->handle($mk(), $this->next($this->makeResponse())); // 2 → 自动 flush
        $this->assertSame(2, \think\facade\Db::name('audit_log')->count());
        $this->assertSame([], AuditLog::getBuffer());

        AuditLog::flush();
    }

    public function testFlushEmptyEarlyReturn(): void
    {
        AuditLog::flush();
        $this->assertSame(0, \think\facade\Db::name('audit_log')->count());
    }

    // ---------------- 受保护方法（反射） ----------------

    public function testResolveCategory(): void
    {
        $m = $this->middleware;
        $this->assertSame('danger', $this->invokeInaccessible($m, 'resolveCategory', ['DELETE']));
        $this->assertSame('warning', $this->invokeInaccessible($m, 'resolveCategory', ['PUT']));
        $this->assertSame('warning', $this->invokeInaccessible($m, 'resolveCategory', ['PATCH']));
        $this->assertSame('normal', $this->invokeInaccessible($m, 'resolveCategory', ['POST']));
        $this->assertSame('read', $this->invokeInaccessible($m, 'resolveCategory', ['GET']));
    }

    public function testResolveResult(): void
    {
        $m = $this->middleware;
        $this->assertSame('success', $this->invokeInaccessible($m, 'resolveResult', [200]));
        $this->assertSame('success', $this->invokeInaccessible($m, 'resolveResult', [299]));
        $this->assertSame('failure', $this->invokeInaccessible($m, 'resolveResult', [404]));
        $this->assertSame('error', $this->invokeInaccessible($m, 'resolveResult', [500]));
        $this->assertSame('unknown', $this->invokeInaccessible($m, 'resolveResult', [100]));
    }

    public function testSafeTruncate(): void
    {
        $m = $this->middleware;
        $this->assertNull($this->invokeInaccessible($m, 'safeTruncate', [null, 10]));
        $this->assertSame('abc', $this->invokeInaccessible($m, 'safeTruncate', ['abc', 10]));
        $this->assertSame(8, mb_strlen($this->invokeInaccessible($m, 'safeTruncate', ['abcdefghijkl', 8]), 'UTF-8'));
    }

    public function testGetFieldLimits(): void
    {
        $limits = $this->invokeInaccessible($this->middleware, 'getFieldLimits', []);
        $this->assertArrayHasKey('action', $limits);
        // 必须与 audit_log 迁移表的列宽一致，否则严格模式下整批写入失败
        $this->assertSame(100, $limits['action']);
        $this->assertSame(500, $limits['path']);
        $this->assertSame(50, $limits['operator_name']);
        $this->assertSame(50, $limits['tenant_id']);
        $this->assertSame(50, $limits['target_type']);
    }

    public function testIsExcludedEmptyPatterns(): void
    {
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x']);
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, []]));
    }

    public function testIsExcludedWildcard(): void
    {
        $request = $this->makeRequest('http://localhost/admin/login/do', 'POST', [], [], '', ['pathinfo' => 'admin/login/do']);
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, ['admin/login/*']]));
    }

    public function testBuildRecordReturnsStructuredArray(): void
    {
        $request = $this->makeRequest('http://localhost/admin/user/delete', 'POST', ['id' => 5], ['user-agent' => 'Agent'], '', [
            'pathinfo' => 'admin/user/delete',
            'controller' => 'sample',
        ]);
        $record = $this->invokeInaccessible($this->middleware, 'buildRecord', [$request, $this->makeResponse(), ['params_hash' => true], 12]);
        $this->assertArrayHasKey('action', $record);
        $this->assertArrayHasKey('result', $record);
        $this->assertArrayHasKey('category', $record);
        $this->assertArrayHasKey('method', $record);
        $this->assertArrayHasKey('ip', $record);
        $this->assertArrayHasKey('operator_id', $record);
        $this->assertSame('POST', $record['method']);
        $this->assertSame('success', $record['result']);
        $this->assertSame(12, $record['duration_ms']);
        // params_hash 已脱敏密码字段
        $this->assertNotEmpty($record['params_hash']);
    }

    public function testBuildRecordMasksSensitiveParams(): void
    {
        $request = $this->makeRequest('http://localhost/admin/login', 'POST', ['password' => 'secret123', 'name' => 'bob'], [], '', [
            'pathinfo' => 'admin/login',
        ]);
        $record = $this->invokeInaccessible($this->middleware, 'buildRecord', [$request, $this->makeResponse(), ['params_hash' => true], 1]);
        // 密码字段被脱敏后再哈希，哈希值稳定但不等于原值哈希
        $this->assertNotSame(hash('sha256', json_encode(['password' => 'secret123', 'name' => 'bob'], JSON_UNESCAPED_UNICODE)), $record['params_hash']);
    }

    public function testBuildRecordToleratesInvalidUtf8Params(): void
    {
        // 非法 UTF-8（二进制上传/畸形表单）会让 json_encode 返回 false；
        // strict_types=1 下 hash(false) 会抛 TypeError，整条审计记录丢失。
        $request = $this->makeRequest('http://localhost/admin/upload', 'POST', ['file_name' => "\xC3\x28"], [], '', [
            'pathinfo' => 'admin/upload',
        ]);
        $record = $this->invokeInaccessible($this->middleware, 'buildRecord', [$request, $this->makeResponse(), ['params_hash' => true], 1]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $record['params_hash']);
    }

    public function testRegisterShutdownFlushIdempotent(): void
    {
        AuditLog::registerShutdownFlush();
        AuditLog::registerShutdownFlush(); // 第二次应直接返回（覆盖分支）
        $this->assertTrue(true);
    }

    public function testResolveAction(): void
    {
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x']);
        $this->assertIsString($this->invokeInaccessible($this->middleware, 'resolveAction', [$request]));
    }

    public function testResolveTargetType(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController']);
        $this->assertSame('sample', $this->invokeInaccessible($this->middleware, 'resolveTargetType', [$request]));
    }

    public function testResolveTargetTypeEmptyController(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => '']);
        $this->assertNull($this->invokeInaccessible($this->middleware, 'resolveTargetType', [$request]));
    }

    public function testResolveTargetTypePackageController(): void
    {
        // 包控制器：Callback 调度把命名空间前缀塞进 layer、子目录塞进 controller。
        // 修复前 parseClass() 会丢掉前缀，target_type 恒为 null（审计静默丢失）。
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'admin/Plugin', 'action' => 'index']);
        $request->setLayer('wise/market');

        $this->assertSame('plugin', $this->invokeInaccessible($this->middleware, 'resolveTargetType', [$request]));
    }

    public function testResolveTargetTypeMultiAppHost(): void
    {
        // think-multi-app 加载 admin 应用时 namespace = app\admin
        app()->setNamespace('app\admin');

        try {
            $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'Role', 'action' => 'save']);
            $this->assertSame('role', $this->invokeInaccessible($this->middleware, 'resolveTargetType', [$request]));
        } finally {
            app()->setNamespace('app');
        }
    }

    public function testResolveOperatorIdAndName(): void
    {
        // 测试环境未提供 auth_id()/auth_user() 辅助函数，应安全降级
        $this->assertSame(0, $this->invokeInaccessible($this->middleware, 'resolveOperatorId', []));
        $this->assertSame('system', $this->invokeInaccessible($this->middleware, 'resolveOperatorName', []));
    }

    public function testResolveTenantId(): void
    {
        $this->assertNull($this->invokeInaccessible($this->middleware, 'resolveTenantId', []));
    }

    public function testResolveTenantIdIgnoresClientHeaderByDefault(): void
    {
        // 默认不采信客户端 x-tenant-id（客户端可控，可伪造审计归属）
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], ['x-tenant-id' => 'evil-tenant']);
        $this->middleware->handle($request, $this->next($this->makeResponse()));

        $buffer = AuditLog::getBuffer();
        $this->assertNotEmpty($buffer);
        $this->assertNull($buffer[0]['tenant_id']);
    }

    public function testResolveTenantIdTrustsHeaderWhenEnabled(): void
    {
        $this->mergeConfig(['audit' => ['trust_request_tenant_header' => true]]);
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], ['x-tenant-id' => 'tenant-1']);
        $this->middleware->handle($request, $this->next($this->makeResponse()));

        $buffer = AuditLog::getBuffer();
        $this->assertNotEmpty($buffer);
        $this->assertSame('tenant-1', $buffer[0]['tenant_id']);
    }

    public function testExceptionRequestStillAudited(): void
    {
        // 未捕获异常 / 5xx 必须留痕，且异常继续向上抛出
        $this->mergeConfig(['audit' => ['enabled' => true, 'methods' => ['POST'], 'exclude' => []]]);

        $request = $this->makeRequest('http://localhost/admin/user/delete', 'POST', ['id' => 5], [], '', [
            'pathinfo'   => 'admin/user/delete',
            'controller' => 'sample',
        ]);

        try {
            $this->middleware->handle($request, function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('业务异常应继续向上抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $buffer = AuditLog::getBuffer();
        $this->assertNotEmpty($buffer);
        $this->assertSame('error', $buffer[0]['result']);
        $this->assertSame(500, $buffer[0]['status_code']);
    }

    public function testIsSwooleCoroutineFalse(): void
    {
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'isSwooleCoroutine', []));
    }

    public function testFlushSwooleBuffer(): void
    {
        $ref = new \ReflectionMethod(AuditLog::class, 'flushSwooleBuffer');
        $ref->setAccessible(true);
        $ref->invoke(null, [[
            'action'        => 'admin.user.delete',
            'target_type'   => 'user',
            'target_id'     => '5',
            'result'        => 'success',
            'category'      => 'danger',
            'method'        => 'POST',
            'path'          => 'admin/user/delete',
            'params_hash'   => sha1('x'),
            'operator_id'   => 0,
            'operator_name' => 'system',
            'tenant_id'     => null,
            'ip'            => '127.0.0.1',
            'user_agent'    => 'ua',
            'duration_ms'   => 1,
            'status_code'   => 200,
            'create_time'   => date('Y-m-d H:i:s'),
        ]]);

        $this->assertSame(1, \think\facade\Db::name('audit_log')->count());
    }
}
