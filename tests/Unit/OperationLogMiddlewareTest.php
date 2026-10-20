<?php
declare(strict_types=1);

namespace wise\operationlog\tests\Unit;

use Closure;
use think\Response;
use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\middleware\OperationLog;
use wise\operationlog\tests\TestCase;

/**
 * OperationLog 中间件单元测试（基于 SQLite :memory:）
 */
class OperationLogMiddlewareTest extends TestCase
{
    private OperationLog $middleware;
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new OperationLog();
        $this->captured = [];
        \think\facade\Event::listen(OperationLogEvent::class, function (OperationLogEvent $e): void {
            $this->captured[] = $e->logData;
        });
    }

    protected function tearDown(): void
    {
        \think\facade\Event::remove(OperationLogEvent::class);
        parent::tearDown();
    }

    private function next(Response $response): Closure
    {
        return function () use ($response) {
            return $response;
        };
    }

    // ---------------- handle 早退分支 ----------------

    public function testHandleDisabledReturnsResponse(): void
    {
        $this->mergeConfig(['middleware' => ['enable' => false]]);
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x', 'controller' => 'SampleController', 'action' => 'delete']);
        $response = $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([], $this->captured);
    }

    public function testHandleNoControllerReturnsResponse(): void
    {
        $this->mergeConfig(['middleware' => ['enable' => true]]);
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x', 'controller' => '', 'action' => '']);
        $response = $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([], $this->captured);
    }

    // ---------------- 完整流程（fixture 控制器） ----------------

    public function testHandleFullFlowDispatchesEvent(): void
    {
        $this->mergeConfig([
            'middleware' => ['enable' => true],
            'operator'   => [
                'id_resolver'   => fn() => 99,
                'name_resolver' => fn() => 'tester',
            ],
        ]);

        $request = $this->makeRequest(
            'http://localhost/admin/user/delete',
            'POST',
            ['id' => 7, 'name' => 'bob'],
            ['user-agent' => 'UA'],
            '',
            ['pathinfo' => 'admin/user/delete', 'controller' => 'SampleController', 'action' => 'delete']
        );
        $response = $this->makeResponse(['code' => 0]);

        $this->middleware->handle($request, $this->next($response));

        $this->assertNotEmpty($this->captured);
        $log = $this->captured[0];
        $this->assertStringContainsString('状态0', $log['content']);
        $this->assertSame(99, $log['operator_id']);
        $this->assertSame('tester', $log['operator_name']);
        $this->assertSame('default', $log['channel']);
        $this->assertSame('INFO', $log['level']);
    }

    public function testHandleMethodWithoutAttributeNoEvent(): void
    {
        $this->mergeConfig(['middleware' => ['enable' => true], 'operator' => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a']]);
        $request = $this->makeRequest('http://localhost/admin/x', 'POST', [], [], '', ['pathinfo' => 'admin/x', 'controller' => 'SampleController', 'action' => 'plain']);
        $this->middleware->handle($request, $this->next($this->makeResponse()));
        $this->assertSame([], $this->captured);
    }

    // ---------------- processOperationLog 分支 ----------------

    public function testProcessOperationLogRecordParams(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a']]);
        $request = $this->makeRequest('http://localhost/x', 'POST', ['foo' => 'bar'], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController', 'action' => 'store']);
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 0]), 0.0]);

        $this->assertNotEmpty($this->captured);
        $this->assertStringContainsString('foo', $this->captured[0]['params']);
    }

    public function testProcessOperationLogSlowTag(): void
    {
        $this->mergeConfig([
            'operator'    => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a'],
            'performance' => ['slow_threshold_ms' => 100],
        ]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController', 'action' => 'delete']);
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 0]), 5000.0]);

        $this->assertNotEmpty($this->captured);
        $this->assertStringContainsString('slow', $this->captured[0]['tags']);
    }

    public function testProcessOperationLogConditionMet(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a']]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController', 'action' => 'update']);
        // update 条件为 response.code == 0，响应数据 code=0 → 条件满足 → 记录
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 0]), 0.0]);

        $this->assertNotEmpty($this->captured);
        $this->assertSame('admin', $this->captured[0]['channel']);
    }

    public function testProcessOperationLogConditionNotMet(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a']]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController', 'action' => 'update']);
        // update 条件为 response.code == 0，响应数据 code=1 → 条件不满足 → 跳过
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 1]), 0.0]);

        $this->assertSame([], $this->captured);
    }

    public function testProcessOperationLogClassDefaults(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a']]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'DefaultsController', 'action' => 'delete']);
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 0]), 0.0]);

        $this->assertNotEmpty($this->captured);
        $this->assertSame('admin', $this->captured[0]['channel']);
        $this->assertSame('WARNING', $this->captured[0]['level']);
        $this->assertStringContainsString('cls', $this->captured[0]['tags']);
    }

    public function testProcessOperationLogWithExtraResolver(): void
    {
        $this->mergeConfig([
            'operator'       => ['id_resolver' => fn() => 1, 'name_resolver' => fn() => 'a'],
            'extra_resolver' => fn() => ['dept' => 'hr'],
        ]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'controller' => 'SampleController', 'action' => 'delete']);
        $this->invokeInaccessible($this->middleware, 'processOperationLog', [$request, $this->makeResponse(['code' => 0]), 0.0]);

        $this->assertNotEmpty($this->captured);
        $this->assertStringContainsString('hr', $this->captured[0]['extra']);
    }

    // ---------------- mergeWithDefaults ----------------

    public function testMergeWithDefaultsNoClassDefault(): void
    {
        $method = ['template' => 't', 'channel' => 'default', 'conditions' => [], 'recordParams' => false, 'tags' => [], 'level' => 'INFO'];
        $this->assertSame($method, $this->invokeInaccessible($this->middleware, 'mergeWithDefaults', [$method, null]));
    }

    public function testMergeWithDefaultsInheritsTemplateAndChannel(): void
    {
        $method = ['template' => '', 'channel' => 'default', 'conditions' => [], 'recordParams' => false, 'tags' => ['m'], 'level' => 'INFO'];
        $class = ['template' => 'cls', 'channel' => 'admin', 'conditions' => ['a' => 1], 'recordParams' => true, 'tags' => ['c'], 'level' => 'WARNING'];
        $merged = $this->invokeInaccessible($this->middleware, 'mergeWithDefaults', [$method, $class]);
        $this->assertSame('cls', $merged['template']);
        $this->assertSame('admin', $merged['channel']);
        $this->assertSame(['a' => 1], $merged['conditions']);
        $this->assertTrue($merged['recordParams']);
        $this->assertSame('WARNING', $merged['level']);
        // tags 合并去重
        $this->assertEqualsCanonicalizing(['m', 'c'], $merged['tags']);
    }

    public function testMergeWithDefaultsKeepsMethodValues(): void
    {
        $method = ['template' => 'm', 'channel' => 'api', 'conditions' => ['b' => 2], 'recordParams' => true, 'tags' => ['x'], 'level' => 'ERROR'];
        $class = ['template' => 'cls', 'channel' => 'admin', 'conditions' => ['a' => 1], 'recordParams' => false, 'tags' => ['c'], 'level' => 'WARNING'];
        $merged = $this->invokeInaccessible($this->middleware, 'mergeWithDefaults', [$method, $class]);
        $this->assertSame('m', $merged['template']);
        $this->assertSame('api', $merged['channel']);
        $this->assertSame(['b' => 2], $merged['conditions']);
        $this->assertTrue($merged['recordParams']);
        $this->assertSame('ERROR', $merged['level']);
    }

    // ---------------- checkConditions ----------------

    public function testCheckConditionsEmpty(): void
    {
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'checkConditions', [[], []]));
    }

    public function testCheckConditionsMatched(): void
    {
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'checkConditions', [['code' => 0], ['code' => 0]]));
    }

    public function testCheckConditionsNotMatched(): void
    {
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'checkConditions', [['code' => 0], ['code' => 1]]));
    }

    public function testCheckConditionsSupportsResponsePrefix(): void
    {
        // 条件路径带 response. 前缀时，应能直接作用于响应数据
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'checkConditions', [['response.code' => 0], ['code' => 0]]));
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'checkConditions', [['response.code' => 0], ['code' => 1]]));
    }

    public function testCheckConditionsResponsePrefixFallbackToNested(): void
    {
        // 向后兼容：响应数据本身包含 response 键时，完整路径优先命中
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'checkConditions', [['response.code' => 0], ['response' => ['code' => 0]]]));
    }

    // ---------------- getResponseData ----------------

    public function testGetResponseDataJson(): void
    {
        $resp = $this->makeResponse(['code' => 1]);
        $this->assertSame(['code' => 1], $this->invokeInaccessible($this->middleware, 'getResponseData', [$resp]));
    }

    public function testGetResponseDataEmpty(): void
    {
        $resp = $this->makeResponse();
        $this->assertSame([], $this->invokeInaccessible($this->middleware, 'getResponseData', [$resp]));
    }

    public function testGetResponseDataInvalidJson(): void
    {
        $resp = $this->makeHtmlResponse('not json <<<');
        $this->assertSame([], $this->invokeInaccessible($this->middleware, 'getResponseData', [$resp]));
    }

    // ---------------- isExcluded ----------------

    public function testIsExcludedByIp(): void
    {
        $this->mergeConfig(['exclude' => ['ips' => ['10.1.2.3']]]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x', 'realIP' => '10.1.2.3']);
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\X']));
    }

    public function testIsExcludedByControllerWildcard(): void
    {
        // 使用带命名空间反斜杠的 FQCN 通配符（验证 Windows 下不再被当作转义符）
        $this->mergeConfig(['exclude' => ['controllers' => ['app\controller\Debug*']]]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x']);
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\DebugController']));
    }

    public function testIsExcludedByControllerExact(): void
    {
        $this->mergeConfig(['exclude' => ['controllers' => ['app\controller\Foo']]]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x']);
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\Foo']));
    }

    public function testIsExcludedByRouteRegex(): void
    {
        $this->mergeConfig(['exclude' => ['routes' => ['.*']]]);
        $request = $this->makeRequest('http://localhost/api/health', 'POST', [], [], '', ['pathinfo' => 'api/health']);
        $this->assertTrue($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\X']));
    }

    public function testIsExcludedByRouteInvalidRegex(): void
    {
        $this->mergeConfig(['exclude' => ['routes' => ['(']]]);
        $request = $this->makeRequest('http://localhost/api/health', 'POST', [], [], '', ['pathinfo' => 'api/health']);
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\X']));
    }

    public function testIsExcludedNoMatch(): void
    {
        $this->mergeConfig(['exclude' => ['ips' => [], 'controllers' => [], 'routes' => []]]);
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['pathinfo' => 'x']);
        $this->assertFalse($this->invokeInaccessible($this->middleware, 'isExcluded', [$request, 'app\controller\X']));
    }

    // ---------------- safeJsonEncodeParams ----------------

    public function testSafeJsonEncodeParamsSmall(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', ['a' => 'b'], [], '', ['pathinfo' => 'x']);
        $out = $this->invokeInaccessible($this->middleware, 'safeJsonEncodeParams', [$request]);
        $this->assertSame(json_encode(['a' => 'b'], JSON_UNESCAPED_UNICODE), $out);
    }

    public function testSafeJsonEncodeParamsTruncated(): void
    {
        $this->mergeConfig(['storage' => ['max_params_size' => 50]]);
        $big = ['data' => str_repeat('x', 1000)];
        $request = $this->makeRequest('http://localhost/x', 'POST', $big, [], '', ['pathinfo' => 'x']);
        $out = $this->invokeInaccessible($this->middleware, 'safeJsonEncodeParams', [$request]);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['_truncated']);
        $this->assertArrayHasKey('_preview', $decoded);
    }

    // ---------------- getConfig ----------------

    public function testGetConfigWhole(): void
    {
        $cfg = $this->invokeInaccessible($this->middleware, 'getConfig', [null, null]);
        $this->assertArrayHasKey('storage', $cfg);
    }

    public function testGetConfigDotPath(): void
    {
        $this->assertSame('INFO', $this->invokeInaccessible($this->middleware, 'getConfig', ['level.default', null]));
    }

    public function testGetConfigDefault(): void
    {
        $this->assertSame('fallback', $this->invokeInaccessible($this->middleware, 'getConfig', ['not.exist', 'fallback']));
    }

    // ---------------- resolveExtra ----------------

    public function testResolveExtraNullResolver(): void
    {
        $this->mergeConfig(['extra_resolver' => null]);
        $this->assertSame('', $this->invokeInaccessible($this->middleware, 'resolveExtra', []));
    }

    public function testResolveExtraArray(): void
    {
        $this->mergeConfig(['extra_resolver' => fn() => ['dept' => 'hr']]);
        $out = $this->invokeInaccessible($this->middleware, 'resolveExtra', []);
        $this->assertSame(json_encode(['dept' => 'hr'], JSON_UNESCAPED_UNICODE), $out);
    }

    public function testResolveExtraReturnsNull(): void
    {
        $this->mergeConfig(['extra_resolver' => fn() => null]);
        $this->assertSame('', $this->invokeInaccessible($this->middleware, 'resolveExtra', []));
    }

    // ---------------- maskSensitiveData ----------------

    public function testMaskSensitiveDataContent(): void
    {
        $this->mergeConfig(['sensitive' => ['enable' => true, 'fields' => ['phone' => 'phone'], 'scope' => ['params' => true, 'content' => true, 'extra' => true]]]);
        $log = ['content' => '手机13812345678', 'params' => '', 'extra' => ''];
        $masked = $this->invokeInaccessible($this->middleware, 'maskSensitiveData', [$log]);
        $this->assertSame('手机138****5678', $masked['content']);
    }

    public function testMaskSensitiveDataParams(): void
    {
        $this->mergeConfig(['sensitive' => ['enable' => true, 'fields' => ['password' => 'replace'], 'scope' => ['params' => true, 'content' => true, 'extra' => true]]]);
        $log = ['content' => 'x', 'params' => json_encode(['password' => 'secret']), 'extra' => ''];
        $masked = $this->invokeInaccessible($this->middleware, 'maskSensitiveData', [$log]);
        $decoded = json_decode($masked['params'], true);
        $this->assertSame('***', $decoded['password']);
    }

    public function testMaskSensitiveDataDisabled(): void
    {
        $this->mergeConfig(['sensitive' => ['enable' => false]]);
        $log = ['content' => '手机13812345678', 'params' => '', 'extra' => ''];
        $masked = $this->invokeInaccessible($this->middleware, 'maskSensitiveData', [$log]);
        $this->assertSame('手机13812345678', $masked['content']);
    }

    // ---------------- getOperatorInfo ----------------

    public function testGetOperatorInfoNoResolver(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => null, 'name_resolver' => null]]);
        $info = $this->invokeInaccessible($this->middleware, 'getOperatorInfo', []);
        $this->assertSame(0, $info['id']);
        $this->assertSame('system', $info['name']);
    }

    public function testGetOperatorInfoWithResolver(): void
    {
        $this->mergeConfig(['operator' => ['id_resolver' => fn() => 7, 'name_resolver' => fn() => 'neo']]);
        $info = $this->invokeInaccessible($this->middleware, 'getOperatorInfo', []);
        $this->assertSame(7, $info['id']);
        $this->assertSame('neo', $info['name']);
    }

    // ---------------- buildVariables / getMethodParams ----------------

    public function testBuildVariables(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', ['id' => 1], [], '', ['pathinfo' => 'x']);
        $vars = $this->invokeInaccessible($this->middleware, 'buildVariables', [$request, ['code' => 0], ['id' => 1]]);
        $this->assertArrayHasKey('context', $vars);
        $this->assertArrayHasKey('method_params', $vars);
        $this->assertArrayHasKey('request_params', $vars);
        $this->assertArrayHasKey('response', $vars);
        $this->assertArrayHasKey('$sys', $vars);
    }

    public function testMaskTreeMasksSensitiveFields(): void
    {
        $this->mergeConfig([
            'sensitive' => [
                'enable' => true,
                'fields' => ['password' => 'replace', '*.token' => 'replace'],
            ],
        ]);

        $masked = $this->invokeInaccessible($this->middleware, 'maskTree', [[
            'password' => 'p@ssw0rd',
            'profile'  => ['token' => 'abc123'],
            'name'     => 'alice',
        ]]);

        $this->assertSame('***', $masked['password']);
        $this->assertSame('***', $masked['profile']['token']);
        $this->assertSame('alice', $masked['name']);
    }

    public function testMaskTreeDisabledReturnsOriginal(): void
    {
        $this->mergeConfig(['sensitive' => ['enable' => false, 'fields' => ['password' => 'replace']]]);
        $data = ['password' => 'p@ssw0rd'];

        $this->assertSame($data, $this->invokeInaccessible($this->middleware, 'maskTree', [$data]));
    }

    public function testBuildVariablesMasksRequestParamsBeforeRender(): void
    {
        // 回归：模板变量池必须在渲染前脱敏，避免 {request_params.password} 明文入库
        $this->mergeConfig(['sensitive' => ['enable' => true, 'fields' => ['password' => 'replace']]]);
        $request = $this->makeRequest('http://localhost/x', 'POST', ['password' => 'p@ssw0rd', 'keyword' => 'k'], [], '', ['pathinfo' => 'x']);

        $vars = $this->invokeInaccessible($this->middleware, 'buildVariables', [$request, [], []]);

        $this->assertSame('***', $vars['request_params']['password']);
        $this->assertSame('k', $vars['request_params']['keyword']);
    }

    public function testGetMethodParams(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', ['id' => 7, 'name' => 'bob'], [], '', ['pathinfo' => 'x']);
        $params = $this->invokeInaccessible($this->middleware, 'getMethodParams', ['app\controller\SampleController', 'delete', $request]);
        $this->assertSame(7, $params['id']);
        $this->assertSame('bob', $params['name']);
    }

    // ---------------- resolveControllerClass ----------------

    public function testResolveControllerClassSingleApp(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'SampleController', 'action' => 'delete']);
        $this->assertSame('app\controller\SampleController', $this->invokeInaccessible($this->middleware, 'resolveControllerClass', [$request]));
    }

    public function testResolveControllerClassPackageNamespace(): void
    {
        // 包控制器路由为 FQCN：框架 Callback 调度把命名空间前缀塞进 layer、子目录塞进 controller
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'admin/Plugin', 'action' => 'index']);
        $request->setLayer('wise/market');

        $this->assertSame('wise\market\controller\admin\Plugin', $this->invokeInaccessible($this->middleware, 'resolveControllerClass', [$request]));
    }

    public function testResolveControllerClassMultiAppHost(): void
    {
        // think-multi-app 加载 admin 应用时会把 namespace 设为 app\admin
        app()->setNamespace('app\admin');

        try {
            $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'Role', 'action' => 'save']);
            $this->assertSame('app\admin\controller\Role', $this->invokeInaccessible($this->middleware, 'resolveControllerClass', [$request]));
        } finally {
            app()->setNamespace('app');
        }
    }

    public function testResolveControllerClassUnknownReturnsNull(): void
    {
        $request = $this->makeRequest('http://localhost/x', 'POST', [], [], '', ['controller' => 'NoSuch', 'action' => 'index']);
        $this->assertNull($this->invokeInaccessible($this->middleware, 'resolveControllerClass', [$request]));
    }

    // ---------------- Attribute 引用（编译期校验） ----------------

    public function testOperationLogAttributeUsable(): void
    {
        $attr = new \wise\operationlog\attribute\OperationLog(template: 't');
        $this->assertSame('t', $attr->template);
    }
}
