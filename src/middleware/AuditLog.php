<?php

declare(strict_types=1);

namespace wise\operationlog\middleware;

use think\facade\Db;
use think\Request;
use think\Response;

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
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            // 异常请求同样要留痕：未捕获异常 / 5xx 是最值得审计的安全事件
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

                    if ($this->isSwooleCoroutine()) {
                        $ctx                       = $this->getCoroutineContext();
                        $buffer                    = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
                        $buffer[]                  = $failed;
                        $ctx[self::COROUTINE_BUFFER_KEY] = $buffer;
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

            // 检查是否启用
            if (!($auditConfig['enabled'] ?? false)) {
                return $response;
            }

            // 检查请求方法是否在记录范围内
            $allowedMethods = $auditConfig['methods'] ?? ['POST', 'PUT', 'DELETE', 'PATCH'];
            if (!in_array(strtoupper($request->method()), $allowedMethods, true)) {
                return $response;
            }

            // 白名单排除
            if ($this->isExcluded($request, $auditConfig['exclude'] ?? [])) {
                return $response;
            }

            // 计算耗时
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            // 构建审计日志记录
            $record = $this->buildRecord($request, $response, $auditConfig, $durationMs);

            // 写入缓冲区（Swoole 协程环境使用协程上下文隔离，避免跨协程数据竞争）
            if ($this->isSwooleCoroutine()) {
                $ctx      = $this->getCoroutineContext();
                $buffer   = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
                $buffer[] = $record;
                $ctx[self::COROUTINE_BUFFER_KEY] = $buffer;

                // 常驻进程下 register_shutdown_function 不会触发，改用协程 defer 兜底写入
                if (empty($ctx[self::COROUTINE_DEFER_KEY])) {
                    $ctx[self::COROUTINE_DEFER_KEY] = true;
                    /** @disregard P1009 IDE may not have Swoole stubs installed */
                    \Swoole\Coroutine::defer(function () {
                        try {
                            self::flushCoroutineBuffer();
                        } catch (\Throwable $e) {
                            trace('AuditLog coroutine defer flush failed: ' . $e->getMessage(), 'error');
                        }
                    });
                }

                // 缓冲区满时自动 flush
                $bufferSize = (int) ($auditConfig['buffer_size'] ?? 200);
                if (count($buffer) >= $bufferSize) {
                    self::flushSwooleBuffer($buffer);
                    $ctx[self::COROUTINE_BUFFER_KEY] = [];
                }
            } else {
                self::$buffer[] = $record;

                // 缓冲区满时自动 flush
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
            // 支持通配符 * 匹配
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
        // 与 database/migrations/20251023000003_create_audit_log_table.php 列宽严格一致，
        // 否则 MySQL 严格模式下 insertAll 报 Data too long，导致整批审计记录丢失
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

        // IP 地址校验，防止伪造注入
        $rawIp = $request->ip();
        $ip    = filter_var($rawIp, FILTER_VALIDATE_IP) ? $rawIp : '0.0.0.0';

        // 操作者信息
        $operatorId   = $this->resolveOperatorId();
        $operatorName = $this->resolveOperatorName();

        // 结构化字段自动推断
        $action     = $this->resolveAction($request);
        $targetType = $this->resolveTargetType($request);
        // 请求参数可能为 int 等非字符串类型，统一归一化为字符串，避免 safeTruncate() 类型错误
        $targetId   = $request->param('id') ?? null;
        if ($targetId !== null && !is_string($targetId)) {
            $targetId = is_scalar($targetId) ? (string) $targetId : null;
        }
        $category   = $this->resolveCategory($method);
        $result     = $this->resolveResult($statusCode);

        // 参数哈希（对敏感字段脱敏后再哈希）
        $paramsHash = null;
        if ($auditConfig['params_hash'] ?? true) {
            $params = $request->param();
            $sensitiveKeys = ['password', 'passwd', 'pwd', 'token', 'secret', 'api_key', 'apikey'];
            foreach ($sensitiveKeys as $key) {
                if (isset($params[$key])) {
                    $params[$key] = '***MASKED***';
                }
            }
            // json_encode 在含非法 UTF-8（二进制上传、畸形表单值）时返回 false，
            // 而本文件是 strict_types=1，hash() 会直接抛 TypeError，导致整条审计记录丢失。
            // 用 INVALID_UTF8_SUBSTITUTE + PARTIAL_OUTPUT 保证总能得到字符串。
            $json = json_encode(
                $params,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            $paramsHash = hash('sha256', is_string($json) ? $json : '');
        }

        // 租户 ID
        $tenantId = $this->resolveTenantId($request);

        // User-Agent（XSS 防护：HTML 实体编码）
        $userAgent = htmlspecialchars(
            $request->header('user-agent', ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 字段长度限制，防止超长数据导致插入异常
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

        // 包命名空间控制器：layer 承载命名空间前缀（非空即尝试）
        if ($layer !== '') {
            $class = str_replace('/', '\\', $layer) . '\\controller\\' . $controllerNs;

            if (class_exists($class)) {
                return $class;
            }
        }

        // 回退：ThinkPHP 标准 parseClass（单应用 / 多应用：namespace 已含 app 名）
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
        // 1. 认证态 / 会话（可信来源）
        try {
            $tenantId = session('tenant_id');
            if ($tenantId) {
                return (string) $tenantId;
            }
        } catch (\Throwable $e) {
            // session 不可用（CLI / 无会话上下文），继续尝试其它来源
        }

        // 2. 请求头（客户端完全可控，默认不采信，防止审计归属被伪造）
        //    多租户场景建议改从认证态获取，例如 auth_user('admin')->tenant_id
        try {
            if ((bool) config('wise-operation-log.audit.trust_request_tenant_header', false)) {
                $headerTenant = ($request ?? request())->header('x-tenant-id', '');
                if ($headerTenant) {
                    return (string) $headerTenant;
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        return null;
    }

    // ==========================================
    //  Buffer / Flush 逻辑
    // ==========================================

    /**
     * 获取当前缓冲区（供 ServiceProvider shutdown 兜底使用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBuffer(): array
    {
        if (self::coroutineActive()) {
            /** @disregard P1009 IDE may not have Swoole stubs installed */
            $ctx = \Swoole\Coroutine::getContext();
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
        if (!self::coroutineActive()) {
            return;
        }

        /** @disregard P1009 IDE may not have Swoole stubs installed */
        $ctx     = \Swoole\Coroutine::getContext();
        $records = $ctx[self::COROUTINE_BUFFER_KEY] ?? [];
        unset($ctx[self::COROUTINE_BUFFER_KEY]);

        self::flushSwooleBuffer($records);
    }

    /**
     * 判断是否运行在 Swoole 协程中（静态版）
     */
    protected static function coroutineActive(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
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

            // 降级为逐条写入：隔离坏数据，避免"一条脏数据毁掉整批审计记录"
            foreach ($records as $record) {
                try {
                    Db::name('audit_log')->insert($record);
                } catch (\Throwable $ignore) {
                    // 单条仍失败：回写缓冲区交 shutdown / 下一轮重试，不静默丢弃
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
            // 切片写入：避免放大 buffer_size 后撞上 MySQL 65535 占位符上限
            foreach (array_chunk($records, 500) as $batch) {
                Db::name('audit_log')->insertAll($batch);
            }
        } catch (\Throwable $e) {
            trace('AuditLog swoole flush failed: ' . $e->getMessage(), 'error');

            // 与非协程 flush() 保持一致：降级逐条写入，隔离坏数据，
            // 避免"一条脏数据毁掉整批审计记录"
            foreach ($records as $record) {
                try {
                    Db::name('audit_log')->insert($record);
                } catch (\Throwable $ignore) {
                    trace('AuditLog swoole single insert failed: ' . $ignore->getMessage(), 'error');
                }
            }
        }
    }

    /**
     * 判断当前是否在 Swoole 协程环境中运行
     *
     * 通过 extension_loaded 运行时检测，避免 IDE 对 Swoole\Coroutine 类
     * 的静态分析报错（未安装 Swoole 扩展的环境）。
     *
     * @return bool
     */
    protected function isSwooleCoroutine(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }

        /** @disregard P1009 IDE may not have Swoole stubs installed */
        return \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * 获取 Swoole 协程上下文（ArrayObject）
     *
     * 将 \Swoole\Coroutine 引用收敛到此处，避免 handle() 中多处
     * 直接引用导致 IDE 报 P1009。
     *
     * @return \ArrayObject
     */
    protected function getCoroutineContext(): \ArrayObject
    {
        /** @disregard P1009 IDE may not have Swoole stubs installed */
        return \Swoole\Coroutine::getContext();
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
