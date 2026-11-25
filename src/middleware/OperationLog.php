<?php

declare(strict_types=1);

namespace wise\operationlog\middleware;

use ReflectionClass;
use ReflectionMethod;
use wise\operationlog\attribute\OperationLog as OperationLogAttribute;
use wise\operationlog\context\LogContext;
use wise\operationlog\event\OperationLogEvent;
use wise\operationlog\listener\OperationLogListener;
use wise\operationlog\sensitive\SensitiveDataMasker;
use wise\operationlog\template\VariableParser;
use wise\operationlog\traits\DotPathTrait;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 操作日志中间件
 *
 * 核心流程：
 * 1. 请求处理完成获得 Response
 * 2. 解析控制器类/方法上的 OperationLog Attribute（带缓存）
 * 3. 检查 exclude 排除规则
 * 4. 合并类级默认配置
 * 5. 检查 conditions 条件
 * 6. 构建变量池 → VariableParser 渲染模板
 * 7. 敏感数据脱敏
 * 8. 触发 OperationLogEvent
 * 9. 请求结束时 flush 缓冲区（批量写入） + 清空 LogContext
 */
class OperationLog
{
    use DotPathTrait;

    /**
     * 配置缓存（#9 性能优化：避免每请求多次调用 config()）
     */
    protected static ?array $cachedConfig = null;

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
                OperationLogListener::flush();
            } catch (\Throwable $ignore) {
                trace('OperationLog flush on exception failed: ' . $ignore->getMessage(), 'error');
            } finally {
                LogContext::clear();
            }

            throw $e;
        }

        $middlewareConfig = $this->getConfig('middleware', []);
        if (!($middlewareConfig['enable'] ?? true)) {
            LogContext::clear();
            return $response;
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        LogContext::set('__duration_ms', $duration);

        try {
            $this->processOperationLog($request, $response, $duration);
        } catch (\Throwable $e) {
            trace('OperationLog middleware error: ' . $e->getMessage(), 'error');
        } finally {
            if ($this->getConfig('async_write.enable', false)) {
                if (\wise\support\runtime\Runtime::inCoroutine()) {
                    \wise\support\runtime\Runtime::defer(static function (): void {
                        try {
                            OperationLogListener::flush();
                            LogContext::clear();
                        } catch (\Throwable $e) {
                            trace('OperationLog defer flush failed: ' . $e->getMessage(), 'error');
                        }
                    });
                } else {
                    $this->registerShutdownFlush();
                }
            } else {
                try {
                    OperationLogListener::flush();
                } catch (\Throwable $e) {
                    trace('OperationLog flush failed: ' . $e->getMessage(), 'error');
                } finally {
                    LogContext::clear();
                }
            }
        }

        return $response;
    }

    /**
     * 注册 shutdown 函数实现异步写入（#9 性能优化）
     */
    /** @var bool 防止 Swoole 常驻进程重复注册 */
    private static bool $shutdownFlushRegistered = false;

    protected function registerShutdownFlush(): void
    {
        if (self::$shutdownFlushRegistered) {
            return;
        }
        self::$shutdownFlushRegistered = true;

        register_shutdown_function(function () {
            try {
                OperationLogListener::flush();
                LogContext::clear();
            } catch (\Throwable $e) {
                trace('OperationLog shutdown flush failed: ' . $e->getMessage(), 'error');
            }
        });
    }

    /**
     * 处理操作日志
     *
     * @param Request  $request  请求对象
     * @param Response $response 响应对象
     * @param float    $duration 操作耗时（毫秒）
     */
    protected function processOperationLog(Request $request, Response $response, float $duration = 0.0): void
    {
        $controllerClass = $this->resolveControllerClass($request);
        $action = $request->action();

        if ($controllerClass === null || empty($action)) {
            return;
        }

        if ($this->isExcluded($request, $controllerClass)) {
            return;
        }

        $attributes = $this->getMethodAttributes($controllerClass, $action);
        if (empty($attributes)) {
            return;
        }

        $classDefault = $this->getClassDefaults($controllerClass);

        $responseData = $this->getResponseData($response);

        $methodParams = $this->getMethodParams($controllerClass, $action, $request);

        $variables = $this->buildVariables($request, $responseData, $methodParams);

        $operatorInfo = $this->getOperatorInfo();

        foreach ($attributes as $attrData) {
            $attrData = $this->mergeWithDefaults($attrData, $classDefault);

            if (empty($attrData['template'])) {
                continue;
            }

            if (!$this->checkConditions($attrData['conditions'], $responseData)) {
                continue;
            }

            $content = VariableParser::render($attrData['template'], $variables);

            $paramsJson = '';
            if ($attrData['recordParams']) {
                $paramsJson = $this->safeJsonEncodeParams($request);
            }

            $tags = $attrData['tags'] ?? [];
            $slowThreshold = (int) $this->getConfig('performance.slow_threshold_ms', 3000);
            if ($duration > 0 && $duration >= $slowThreshold) {
                $tags[] = 'slow';
            }

            $logData = [
                'channel'       => $attrData['channel'],
                'level'         => $attrData['level'] ?? $this->getConfig('level.default', 'INFO'),
                'content'       => $content,
                'params'        => $paramsJson,
                'duration_ms'   => $duration > 0 ? (int) $duration : null,
                'operator_id'   => $operatorInfo['id'],
                'operator_name' => $operatorInfo['name'],
                'request_url'   => $request->url(),
                'ip'            => $request->ip(),
                'user_agent'    => $request->header('user-agent', ''),
                'extra'         => $this->resolveExtra(),
                'tags'          => !empty($tags) ? implode(',', $tags) : '',
                'create_time'    => date('Y-m-d H:i:s'),
            ];

            $logData = $this->maskSensitiveData($logData);

            event(new OperationLogEvent($logData));
        }
    }


    /**
     * 敏感数据脱敏
     *
     * 根据配置 sensitive 段，对 params/content/extra 进行脱敏处理。
     *
     * @param array<string, mixed> $logData 日志数据
     * @return array<string, mixed>
     */
    protected function maskSensitiveData(array $logData): array
    {
        $sensitiveConfig = $this->getConfig('sensitive', []);
        $enable = $sensitiveConfig['enable'] ?? false;

        if (!$enable || empty($sensitiveConfig['fields'] ?? [])) {
            return $logData;
        }

        try {
            $masker = new SensitiveDataMasker();
            $patterns = $sensitiveConfig['fields'];
            $scope = $sensitiveConfig['scope'] ?? [];

            if (($scope['params'] ?? true) && !empty($logData['params'])) {
                $decoded = json_decode($logData['params'], true);
                if (is_array($decoded)) {
                    $masked = $masker->mask($decoded, $patterns);
                    $logData['params'] = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
                }
            }

            if (($sensitiveConfig['auto_detect'] ?? true) && ($scope['content'] ?? true)) {
                $logData['content'] = $masker->maskContent($logData['content']);
            }

            if (($scope['extra'] ?? true) && !empty($logData['extra'])) {
                $decoded = json_decode($logData['extra'], true);
                if (is_array($decoded)) {
                    $masked = $masker->mask($decoded, $patterns);
                    $logData['extra'] = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
                }
            }
        } catch (\Throwable $e) {
            trace('OperationLog sensitive masking failed: ' . $e->getMessage(), 'error');
        }

        return $logData;
    }


    /**
     * 获取配置值（带单请求缓存）
     *
     * @param string|null $key     配置 key（支持点分路径）
     * @param mixed       $default 默认值
     * @return mixed
     */
    protected function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if (self::$cachedConfig === null) {
            self::$cachedConfig = config('wise-operation-log') ?? [];
        }
        if ($key === null) {
            return self::$cachedConfig;
        }
        return $this->getByDotPath(self::$cachedConfig, $key) ?? $default;
    }

    /**
     * 安全 JSON 编码 params（#9 性能优化：防止超大请求体）
     *
     * 超过阈值时截断标记，避免 params 字段无限膨胀。
     *
     * @param Request $request
     * @return string
     */
    protected function safeJsonEncodeParams(Request $request): string
    {
        $flags  = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $params = $request->param();
        $json   = json_encode($params, $flags);

        if (!is_string($json)) {
            return '{}';
        }

        $maxSize = (int) $this->getConfig('storage.max_params_size', 65536);
        if (strlen($json) > $maxSize) {
            $truncated = json_encode([
                '_truncated'      => true,
                '_original_size'  => strlen($json),
                '_preview'        => mb_substr($json, 0, min($maxSize, 2048), 'UTF-8'),
            ], $flags);

            return is_string($truncated) ? $truncated : '{"_truncated":true}';
        }

        return $json;
    }


    /**
     * #4 解析控制器完整类名
     *
     * 兼容三类控制器：
     *   1. **包命名空间控制器**（如 `wise\market\controller\admin\Plugin@index`）——
     *      框架的 Callback 调度会把「命名空间前缀」塞进 `Request::layer()`
     *      （`wise/market`），而 `controller()` 只保留 `admin/Plugin`。
     *      此时 `parseClass()` 会丢掉命名空间前缀、解析成 `app\admin\controller\admin\Plugin`，
     *      导致 `class_exists()` 恒为 false → 日志静默丢失。
     *      故优先用 `layer` 拼回完整 FQCN：`{layer}\controller\{controller}`；
     *   2. **多应用控制器**（`app\admin\controller\Role`）——`layer` 为空，
     *      `parseClass()` 已由 think-multi-app 把 `namespace` 设为 `app\admin`，直接命中；
     *   3. **单应用控制器**（`app\controller\Sample`）——同上，`parseClass()` 直接命中。
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
     * #8 检查是否命中排除规则
     *
     * #9 优化：IP 排除优先（O(1)），控制器排除居中，路由正则最慢放最后。
     */
    protected function isExcluded(Request $request, string $controllerClass): bool
    {
        $exclude = $this->getConfig('exclude', []);

        $excludeIps = $exclude['ips'] ?? [];
        if (!empty($excludeIps) && in_array($request->ip(), $excludeIps, true)) {
            return true;
        }

        $excludeControllers = $exclude['controllers'] ?? [];
        foreach ($excludeControllers as $pattern) {
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $controllerClass, FNM_NOESCAPE)) {
                    return true;
                }
            } else {
                if ($controllerClass === ltrim($pattern, '\\')) {
                    return true;
                }
            }
        }

        $excludeRoutes = $exclude['routes'] ?? [];
        $requestUrl = $request->url();
        foreach ($excludeRoutes as $pattern) {
            if (strlen($pattern) > 500) {
                continue;
            }
            $result = @preg_match('#^' . $pattern . '$#', $requestUrl);
            if ($result === false) {
                trace('OperationLog invalid exclude route pattern: ' . $pattern, 'error');
                continue;
            }
            if ($result) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取类级 OperationLog 默认配置（带缓存）
     *
     * 取类上第一个 OperationLog Attribute 作为该类所有方法的默认值。
     * 若类上无注解，返回 null。
     *
     * @param string $className 控制器类名
     * @return array<string, mixed>|null
     */
    protected function getClassDefaults(string $className): ?array
    {
        $cacheConfig = $this->getConfig('cache', []);
        $cacheEnable = $cacheConfig['enable'] ?? true;
        $keyPrefix   = $cacheConfig['key_prefix'] ?? 'oplog_attr:';

        $cacheKey = $keyPrefix . $className . ':__class__';

        $noDefaults = ['__no_defaults__' => true];

        if ($cacheEnable) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached === $noDefaults ? null : $cached;
            }
        }

        $defaults = null;
        try {
            $reflectionClass = new ReflectionClass($className);
            $attrs = $reflectionClass->getAttributes(OperationLogAttribute::class);

            if (!empty($attrs)) {
                $instance = $attrs[0]->newInstance();
                $defaults = [
                    'template'     => $instance->template,
                    'channel'      => $instance->channel,
                    'conditions'   => $instance->conditions,
                    'recordParams' => $instance->recordParams,
                    'tags'         => $instance->tags,
                    'level'        => $instance->level,
                ];
            }
        } catch (\Throwable $e) {
            trace('OperationLog class reflection error: ' . $e->getMessage(), 'error');
        }

        if ($cacheEnable) {
            Cache::set($cacheKey, $defaults ?? $noDefaults, 3600);
        }

        return $defaults;
    }

    /**
     * 合并类级默认配置到方法级
     *
     * 规则：
     * - template：方法值为空时使用类默认
     * - channel：方法值为 'default' 时使用类默认
     * - conditions：方法为空时使用类默认
     * - recordParams：方法为 false 时使用类默认
     * - tags：合并去重（类 + 方法）
     * - level：方法为 INFO 且类有默认时使用类默认（#5）
     *
     * @param array<string, mixed>       $methodAttr   方法级属性
     * @param array<string, mixed>|null  $classDefault 类级默认（null 表示无需合并）
     * @return array<string, mixed>
     */
    protected function mergeWithDefaults(array $methodAttr, ?array $classDefault): array
    {
        if ($classDefault === null) {
            return $methodAttr;
        }

        if (empty($methodAttr['template']) && !empty($classDefault['template'])) {
            $methodAttr['template'] = $classDefault['template'];
        }

        if ($methodAttr['channel'] === 'default' && $classDefault['channel'] !== 'default') {
            $methodAttr['channel'] = $classDefault['channel'];
        }

        if (empty($methodAttr['conditions']) && !empty($classDefault['conditions'])) {
            $methodAttr['conditions'] = $classDefault['conditions'];
        }

        if (!$methodAttr['recordParams'] && $classDefault['recordParams']) {
            $methodAttr['recordParams'] = true;
        }

        if (($methodAttr['level'] ?? 'INFO') === 'INFO' && ($classDefault['level'] ?? 'INFO') !== 'INFO') {
            $methodAttr['level'] = $classDefault['level'];
        }

        $methodAttr['tags'] = array_values(
            array_unique(array_merge($classDefault['tags'], $methodAttr['tags']))
        );

        return $methodAttr;
    }

    /**
     * 获取方法上的 OperationLog Attribute 实例（带缓存）
     *
     * @param string $className  控制器类名
     * @param string $methodName 方法名
     * @return array<int, array<string, mixed>>
     */
    protected function getMethodAttributes(string $className, string $methodName): array
    {
        $cacheConfig = $this->getConfig('cache', []);
        $cacheEnable = $cacheConfig['enable'] ?? true;
        $keyPrefix = $cacheConfig['key_prefix'] ?? 'oplog_attr:';

        $cacheKey = $keyPrefix . $className . ':' . $methodName;

        if ($cacheEnable) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $attributes = [];
        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);
            $attrs = $reflectionMethod->getAttributes(OperationLogAttribute::class);

            foreach ($attrs as $attr) {
                $instance = $attr->newInstance();
                $attributes[] = [
                    'template'     => $instance->template,
                    'channel'      => $instance->channel,
                    'conditions'   => $instance->conditions,
                    'recordParams' => $instance->recordParams,
                    'tags'         => $instance->tags,
                    'level'        => $instance->level,
                ];
            }
        } catch (\Throwable $e) {
            trace('OperationLog reflection error: ' . $e->getMessage(), 'error');
            return [];
        }

        if ($cacheEnable) {
            Cache::set($cacheKey, $attributes, 3600);
        }

        return $attributes;
    }

    /**
     * 获取响应数据（转为数组）
     *
     * @param Response $response 响应对象
     * @return array<string, mixed>
     */
    protected function getResponseData(Response $response): array
    {
        $content = $response->getContent();

        if (empty($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        if (method_exists($response, 'getData')) {
            $rawData = $response->getData();
            if (is_array($rawData)) {
                return $rawData;
            }
            if (is_string($rawData)) {
                $decoded = json_decode($rawData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * 获取方法参数名与请求值的映射
     *
     * @param string  $className  控制器类名
     * @param string  $methodName 方法名
     * @param Request $request    请求对象
     * @return array<string, mixed>
     */
    protected function getMethodParams(string $className, string $methodName, Request $request): array
    {
        $params = [];
        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);
            $reflectionParams = $reflectionMethod->getParameters();
            $requestParams = $request->param();

            foreach ($reflectionParams as $param) {
                $paramName = $param->getName();
                if (array_key_exists($paramName, $requestParams)) {
                    $params[$paramName] = $requestParams[$paramName];
                }
            }
        } catch (\Throwable $e) {
        }

        return $params;
    }

    /**
     * 构建变量池
     *
     * @param Request              $request      请求对象
     * @param array<string, mixed> $responseData 响应数据
     * @param array<string, mixed> $methodParams 方法参数映射
     * @return array<string, mixed>
     */
    protected function buildVariables(Request $request, array $responseData, array $methodParams): array
    {
        $systemVars = [];
        $sysVarConfig = $this->getConfig('system_vars', []);
        foreach ($sysVarConfig as $key => $resolver) {
            if (is_callable($resolver)) {
                $systemVars[$key] = $resolver();
            }
        }

        return [
            'context'        => LogContext::all(),
            'method_params'  => $this->maskTree($methodParams),
            'request_params' => $this->maskTree($request->param()),
            'response'       => $this->getConfig('sensitive.scope.response', false)
                ? $this->maskTree($responseData)
                : $responseData,
            '$sys'           => $systemVars,
        ];
    }

    /**
     * 按 sensitive.fields 对模板变量树脱敏
     *
     * 变量池中的 request_params / response 会被模板引用并渲染进 content，
     * 必须在渲染前完成字段脱敏（maskContent 的正则只识别手机号/身份证/邮箱，
     * 无法覆盖口令、Token 等）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function maskTree(array $data): array
    {
        $sensitiveConfig = $this->getConfig('sensitive', []);
        if (!($sensitiveConfig['enable'] ?? false) || empty($sensitiveConfig['fields'] ?? [])) {
            return $data;
        }

        try {
            return (new SensitiveDataMasker())->mask($data, $sensitiveConfig['fields']);
        } catch (\Throwable $e) {
            trace('OperationLog variable masking failed: ' . $e->getMessage(), 'error');
            return $data;
        }
    }

    /**
     * 获取操作者信息
     *
     * @return array{ id: int, name: string }
     */
    protected function getOperatorInfo(): array
    {
        $operatorConfig = $this->getConfig('operator', []);

        $idResolver = $operatorConfig['id_resolver'] ?? null;
        $nameResolver = $operatorConfig['name_resolver'] ?? null;

        $id = 0;
        $name = 'system';

        if (is_callable($idResolver)) {
            $resolved = $idResolver();
            $id = $resolved !== null ? (int) $resolved : 0;
        }

        if (is_callable($nameResolver)) {
            $resolved = $nameResolver();
            $name = $resolved !== null ? (string) $resolved : 'system';
        }

        return ['id' => $id, 'name' => $name];
    }

    /**
     * 解析 extra 扩展字段
     *
     * 调用配置中的 extra_resolver 闭包获取扩展数据，
     * 返回 JSON 字符串。闭包返回 null 或非数组时返回空字符串。
     *
     * @return string
     */
    protected function resolveExtra(): string
    {
        $resolver = $this->getConfig('extra_resolver');

        if (!is_callable($resolver)) {
            return '';
        }

        try {
            $extra = $resolver();
            if (is_array($extra) && !empty($extra)) {
                $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                return is_string($json) ? $json : '';
            }
        } catch (\Throwable $e) {
            trace('OperationLog extra_resolver error: ' . $e->getMessage(), 'error');
        }

        return '';
    }

    /**
     * 检查条件是否满足
     *
     * @param array<string, mixed> $conditions   条件配置
     * @param array<string, mixed> $responseData 响应数据
     * @return bool
     */
    protected function checkConditions(array $conditions, array $responseData): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $fieldPath => $expectedValue) {
            $actualValue = $this->getByDotPath($responseData, $fieldPath);

            if ($actualValue === null && str_starts_with($fieldPath, 'response.')) {
                $actualValue = $this->getByDotPath($responseData, substr($fieldPath, 9));
            }

            if ($actualValue != $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
