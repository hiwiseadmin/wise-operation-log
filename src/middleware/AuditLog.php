<?php

declare(strict_types=1);

namespace wise\operationlog\middleware;

use think\facade\Db;
use think\Request;
use think\Response;
use wise\support\runtime\Runtime;

/**
 * 审计日志中间件
 *
 * 与 OperationLog 中间件完全解耦，独立维护 buffer / flush / 配置开关。
 * 无条件全量记录所有匹配的请求，不依赖开发者 Attribute 标注。
 *
 * 核心流程：
 * 1. 记录开始时间 → $next($request) 执行业务
 * 2. 检查 enabled / methods / exclude 过滤条件
 * 3. 自动推断结构化字段（action / target_type / category / result 等）
 * 4. 缓冲区写入，满 buffer_size 条自动 flush
 * 5. register_shutdown_function 兜底写入剩余缓冲区
 *
 * 设计约束：
 * - 不依赖 OperationLogListener / OperationLogEvent 等操作日志类
 * - 直接 Db::name('audit_log')->insertAll() 批量写入（自动带表前缀）
 * - 不使用静态属性缓存请求级数据（防 Swoole 泄漏）
 * - try/catch 包裹全部逻辑，任何异常只 trace 不中断请求
 */
class AuditLog
{
    /**
     * 日志缓冲区
     * @var array<int, array<string, mixed>>
     */
    protected static array $buffer = [];

    /**
     * shutdown 兜底是否已注册（防 Swoole 常驻进程重复注册）
     * @var bool
     */
    protected static bool $shutdownRegistered = false;

    /**
     * Swoole 协程上下文中的缓冲区键名
     */
    private const COROUTINE_BUFFER_KEY = '__audit_log_buffer';

    /**
     * Swoole 协程上下文中 defer 兜底写入的注册标记
     */
    private const COROUTINE_DEFER_KEY = '__audit_log_deferred';

    /**
     * 处理请求
     *
     * @param Request $request 请求对象
     * @param \Closure $next   下一个中间件
     * @return Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $startTime = microtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            try {
                $auditConfig = config('wise-operation-log.audit', []);
                if (($auditConfig['enabled'] ?? false)
                    && in_array(strtoupper($request->method()), $auditConfig['methods'] ?? ['POST', 'PUT', 'DELETE', 'PATCH'], true)
                    && !$this->isExcluded($request, $auditConfig['exclude'] ?? [])) {
                    $failed = $this->buildRecord(
                        $request,
                        response('', 500),
                        $auditConfig,
                        (int) round((microtime(true) - $startTime) * 1000)
                    );

                    $ctx = Runtime::context();
                    if ($ctx !== null) {
                        $buffer                          = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
                        $buffer[]                        = $failed;
                        $ctx[self::COROUTINE_BUFFER_KEY] = $buffer;

                        if (empty($ctx[self::COROUTINE_DEFER_KEY])) {
                            $ctx[self::COROUTINE_DEFER_KEY] = true;
                            Runtime::defer(function () {
                                try {
                                    self::flushCoroutineBuffer();
                                } catch (\Throwable $e) {
                                    trace('AuditLog coroutine defer flush failed: ' . $e->getMessage(), 'error');
                                }
                            });
                        }
                    } else {
                        self::$buffer[] = $failed;
                    }
                }
            } catch (\Throwable $ignore) {
                trace('AuditLog exception record failed: ' . $ignore->getMessage(), 'error');
            }

            throw $e;
        }

        try {
            $auditConfig = config('wise-operation-log.audit', []);

            if (!($auditConfig['enabled'] ?? false)) {
                return $response;
            }

            $allowedMethods = $auditConfig['methods'] ?? ['POST', 'PUT', 'DELETE', 'PATCH'];
            if (!in_array(strtoupper($request->method()), $allowedMethods, true)) {
                return $response;
            }

            if ($this->isExcluded($request, $auditConfig['exclude'] ?? [])) {
                return $response;
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $record = $this->buildRecord($request, $response, $auditConfig, $durationMs);

            $ctx = Runtime::context();
            if ($ctx !== null) {
                $buffer                          = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
                $buffer[]                        = $record;
                $ctx[self::COROUTINE_BUFFER_KEY] = $buffer;

                if (empty($ctx[self::COROUTINE_DEFER_KEY])) {
                    $ctx[self::COROUTINE_DEFER_KEY] = true;
                    Runtime::defer(function () {
                        try {
                            self::flushCoroutineBuffer();
                        } catch (\Throwable $e) {
                            trace('AuditLog coroutine defer flush failed: ' . $e->getMessage(), 'error');
                        }
                    });
                }

                $bufferSize = (int) ($auditConfig['buffer_size'] ?? 200);
                if (count($buffer) >= $bufferSize) {
                    self::flushSwooleBuffer($buffer);
                    $ctx[self::COROUTINE_BUFFER_KEY] = [];
                }
            } else {
                self::$buffer[] = $record;

                $bufferSize = (int) ($auditConfig['buffer_size'] ?? 200);
                if (count(self::$buffer) >= $bufferSize) {
                    self::flush();
                }
            }
        } catch (\Throwable $e) {
            trace('AuditLog middleware error: ' . $e->getMessage(), 'error');
        }

        return $response;
    }

    /**
     * 检查是否命中白名单排除规则
     *
     * 支持通配符 *，如 admin/login/*、admin/captcha/*
     *
     * @param Request $request
     * @param array<int, string> $excludePatterns
     * @return bool
     */
    protected function isExcluded(Request $request, array $excludePatterns): bool
    {
        if (empty($excludePatterns)) {
            return false;
        }

        $path = trim($request->pathinfo(), '/');

        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 字段长度限制映射（与数据库表结构保持一致）
     *
     * @return array<string, int>
     */
    protected function getFieldLimits(): array
    {
        return [
            'action'        => 100,
            'target_type'   => 50,
            'target_id'     => 100,
            'result'        => 10,
            'category'      => 20,
            'method'        => 10,
            'path'          => 500,
            'params_hash'   => 64,
            'operator_name' => 50,
            'tenant_id'     => 50,
            'ip'            => 45,
            'user_agent'    => 500,
        ];
    }

    /**
     * 安全截断字符串（按 UTF-8 字符边界截断）
     *
     * @param string|null $value
     * @param int         $maxLength
     * @return string|null
     */
    protected function safeTruncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_strlen($value, 'UTF-8') > $maxLength
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : $value;
    }

    /**
     * 构建审计日志记录
     *
     * @param Request  $request    请求对象
     * @param Response $response   响应对象
     * @param array    $auditConfig 审计配置
     * @param int      $durationMs 操作耗时（毫秒）
     * @return array<string, mixed>
     */
    protected function buildRecord(Request $request, Response $response, array $auditConfig, int $durationMs): array
    {
        $method     = strtoupper($request->method());
        $path       = $request->pathinfo();
        $statusCode = $response->getCode();

        $rawIp = $request->ip();
        $ip    = filter_var($rawIp, FILTER_VALIDATE_IP) ? $rawIp : '0.0.0.0';

        $operatorId   = $this->resolveOperatorId();
        $operatorName = $this->resolveOperatorName();

        $action     = $this->resolveAction($request);
        $targetType = $this->resolveTargetType($request);
        $targetId   = $request->param('id') ?? null;
        if ($targetId !== null && !is_string($targetId)) {
            $targetId = is_scalar($targetId) ? (string) $targetId : null;
        }
        $category   = $this->resolveCategory($method);
        $result     = $this->resolveResult($statusCode);

        $paramsHash = null;
        if ($auditConfig['params_hash'] ?? true) {
            $params = $request->param();
            $sensitiveKeys = ['password', 'passwd', 'pwd', 'token', 'secret', 'api_key', 'apikey'];
            foreach ($sensitiveKeys as $key) {
                if (isset($params[$key])) {
                    $params[$key] = '***MASKED***';
                }
            }
            $json = json_encode(
                $params,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            $paramsHash = hash('sha256', is_string($json) ? $json : '');
        }

        $tenantId = $this->resolveTenantId($request);

        $userAgent = htmlspecialchars(
            $request->header('user-agent', ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $limits = $this->getFieldLimits();

        return [
            'action'        => $this->safeTruncate($action, $limits['action']),
            'target_type'   => $this->safeTruncate($targetType, $limits['target_type']),
            'target_id'     => $this->safeTruncate($targetId, $limits['target_id']),
            'result'        => $this->safeTruncate($result, $limits['result']),
            'category'      => $this->safeTruncate($category, $limits['category']),
            'method'        => $this->safeTruncate($method, $limits['method']),
            'path'          => $this->safeTruncate($path, $limits['path']),
            'params_hash'   => $this->safeTruncate($paramsHash, $limits['params_hash']),
            'operator_id'   => $operatorId,
            'operator_name' => $this->safeTruncate($operatorName, $limits['operator_name']),
            'tenant_id'     => $this->safeTruncate($tenantId, $limits['tenant_id']),
            'ip'            => $this->safeTruncate($ip, $limits['ip']),
            'user_agent'    => $this->safeTruncate($userAgent, $limits['user_agent']),
            'duration_ms'   => $durationMs,
            'status_code'   => $statusCode,
            'create_time'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 推断 action: app名 + 路由路径 → 点号格式
     *
     * 例: admin/user/delete → admin.user.delete
     *
     * @param Request $request
     * @return string
     */
    protected function resolveAction(Request $request): string
    {
        try {
            $appName = app('http')->getName();
            $path    = trim($request->pathinfo(), '/');
            return $appName . '.' . str_replace('/', '.', $path);
        } catch (\Throwable $e) {
            return 'unknown.' . str_replace('/', '.', trim($request->pathinfo(), '/'));
        }
    }

    /**
     * 推断 target_type: 从控制器类名去除命名空间和 Controller 后缀
     *
     * 例: app\admin\controller\UserController → user
     *
     * @param Request $request
     * @return string|null
     */
    protected function resolveTargetType(Request $request): ?string
    {
        try {
            $class = $this->resolveControllerClass($request);
            if ($class === null) {
                return null;
            }

            return lcfirst(str_replace('Controller', '', class_basename($class)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 解析控制器完整类名（与 OperationLog::resolveControllerClass 口径一致）
     *
     * 兼容三类控制器：
     *   1. **包命名空间控制器**（如 `wise\market\controller\admin\Plugin@index`）——
     *      Callback 调度把命名空间前缀塞进 `Request::layer()`（`wise/market`），
     *      仅 `parseClass('controller', $request->controller())` 会丢掉前缀 → `class_exists()` 恒 false，
     *      target_type 推断为 null（审计丢失）；须按 `{layer}\controller\{controller}` 拼回；
     *   2. **多应用控制器**（`app\admin\controller\Role`）—— layer 为空，`parseClass()` 命中；
     *   3. **单应用控制器**（`app\controller\Sample`）—— 同上。
     */
    protected function resolveControllerClass(Request $request): ?string
    {
        $controller = $request->controller();
        if (empty($controller)) {
            return null;
        }

        $controllerNs = str_replace(['/', '.'], '\\', $controller);
        $layer        = trim((string) $request->layer(), '/\\');

        if ($layer !== '') {
            $class = str_replace('/', '\\', $layer) . '\\controller\\' . $controllerNs;

            if (class_exists($class)) {
                return $class;
            }
        }

        $class = app()->parseClass('controller', $controllerNs);

        return class_exists($class) ? $class : null;
    }

    /**
     * 推断 category: 根据 HTTP 方法分类
     *
     * DELETE → danger, PUT/PATCH → warning, POST → normal, 其他 → read
     *
     * @param string $method
     * @return string
     */
    protected function resolveCategory(string $method): string
    {
        return match ($method) {
            'DELETE'      => 'danger',
            'PUT', 'PATCH' => 'warning',
            'POST'        => 'normal',
            default       => 'read',
        };
    }

    /**
     * 推断 result: 根据响应状态码分类
     *
     * 2xx → success, 4xx → failure, 5xx → error, 其他 → unknown
     *
     * @param int $statusCode
     * @return string
     */
    protected function resolveResult(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 400 && $statusCode < 500 => 'failure',
            $statusCode >= 500                     => 'error',
            default                                => 'unknown',
        };
    }

    /**
     * 解析操作者 ID
     *
     * 优先 auth_id('admin')，其次 auth('api')->id()，最后 0
     *
     * @return int
     */
    protected function resolveOperatorId(): int
    {
        try {
            $id = auth_id('admin');
            if ($id && (is_numeric($id) || is_string($id))) {
                return (int) $id;
            }

            if (function_exists('auth')) {
                $guard = auth_user('api');
                if ($guard && $guard->id()) {
                    return (int) $guard->id();
                }
            }
        } catch (\Throwable $e) {
            trace('AuditLog resolveOperatorId failed: ' . $e->getMessage(), 'error');
        }

        return 0;
    }

    /**
     * 解析操作者名称
     *
     * 优先 admin guard 的 username，其次 api guard 的 name，最后 'system'
     *
     * @return string
     */
    protected function resolveOperatorName(): string
    {
        try {
            if ($authAdmin = auth_user('admin')) {
                $name = $authAdmin->username ?? $authAdmin->nickname ?? $authAdmin->name ?? '';
                if ($name) {
                    return (string) $name;
                }
            }

            if ($authApi = auth_user('api')) {
                $name = $authApi->name ?? $authApi->username ?? '';
                if ($name) {
                    return (string) $name;
                }
            }
        } catch (\Throwable $e) {
            trace('AuditLog resolveOperatorName failed: ' . $e->getMessage(), 'error');
        }

        return 'system';
    }

    /**
     * 解析租户 ID
     *
     * 尝试从请求上下文、session 或配置中获取租户标识
     *
     * @return string|null
     */
    protected function resolveTenantId(?Request $request = null): ?string
    {
        try {
            $tenantId = session('tenant_id');
            if ($tenantId) {
                return (string) $tenantId;
            }
        } catch (\Throwable $e) {
        }

        try {
            if ((bool) config('wise-operation-log.audit.trust_request_tenant_header', false)) {
                $headerTenant = ($request ?? request())->header('x-tenant-id', '');
                if ($headerTenant) {
                    return (string) $headerTenant;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }


    /**
     * 获取当前缓冲区（供 ServiceProvider shutdown 兜底使用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBuffer(): array
    {
        $ctx = Runtime::context();
        if ($ctx !== null) {
            return $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
        }

        return self::$buffer;
    }

    /**
     * 写入并清空当前协程缓冲区的审计记录
     *
     * 常驻进程（Swoole）中 register_shutdown_function 只在 worker 退出时触发，
     * 因此协程上下文中的记录必须通过 Coroutine::defer() 兜底写入。
     */
    protected static function flushCoroutineBuffer(): void
    {
        $ctx = Runtime::context();
        if ($ctx === null) {
            return;
        }

        $records = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
        unset($ctx[self::COROUTINE_BUFFER_KEY]);

        self::flushSwooleBuffer($records);
    }

    /**
     * 批量写入缓冲区数据到数据库
     *
     * 直接使用 Db::table()->insertAll() 批量写入，不经过 Listener/Event/Storage 抽象层。
     * 写入后清空缓冲区。
     */
    public static function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        $records = self::$buffer;
        self::$buffer = [];

        try {
            Db::name('audit_log')->insertAll($records);
        } catch (\Throwable $e) {
            trace('AuditLog flush failed: ' . $e->getMessage(), 'error');

            foreach ($records as $record) {
                try {
                    Db::name('audit_log')->insert($record);
                } catch (\Throwable $ignore) {
                    self::$buffer[] = $record;
                    trace('AuditLog single insert failed: ' . $ignore->getMessage(), 'error');
                }
            }
        }
    }

    /**
     * Swoole 协程环境下写入指定缓冲区（避免静态属性竞争）
     *
     * @param array<int, array<string, mixed>> $records
     */
    protected static function flushSwooleBuffer(array $records): void
    {
        if (empty($records)) {
            return;
        }

        try {
            foreach (array_chunk($records, 500) as $batch) {
                Db::name('audit_log')->insertAll($batch);
            }
        } catch (\Throwable $e) {
            trace('AuditLog swoole flush failed: ' . $e->getMessage(), 'error');

            foreach ($records as $record) {
                try {
                    Db::name('audit_log')->insert($record);
                } catch (\Throwable $ignore) {
                    trace('AuditLog swoole single insert failed: ' . $ignore->getMessage(), 'error');
                    try {
                        \think\facade\Log::record(
                            'AuditLog insert lost: ' . json_encode(
                                $record,
                                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                            ),
                            'error'
                        );
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    /**
     * 判断当前是否在 Swoole 协程环境中运行
     *
     * 运行时判断已收敛到 {@see Runtime::inCoroutine()}，本方法仅作为
     * 兼容入口保留（历史调用方与测试通过反射访问）。
     *
     * @return bool
     */
    protected function isSwooleCoroutine(): bool
    {
        return Runtime::inCoroutine();
    }

    /**
     * 注册 shutdown 兜底写入
     *
     * 确保即使请求没有正常经过中间件（如异常提前终止），
     * 缓冲区中的审计日志仍能被写入。
     */
    public static function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        register_shutdown_function(function () {
            try {
                if (!empty(self::$buffer)) {
                    self::flush();
                }
            } catch (\Throwable $e) {
                trace('AuditLog shutdown flush failed: ' . $e->getMessage(), 'error');
            }
        });
    }
}
