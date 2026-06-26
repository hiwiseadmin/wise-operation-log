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
use wise\operationlog\trait\DotPathTrait;
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
        // === #11 操作耗时记录：记录开始时间 ===
        $startTime = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        // 检查中间件是否启用
        $middlewareConfig = $this->getConfig('middleware', []);
        if (!($middlewareConfig['enable'] ?? true)) {
            return $response;
        }

        // === #11 计算操作耗时（毫秒） ===
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        LogContext::set('__duration_ms', $duration);

        try {
            $this->processOperationLog($request, $response, $duration);
        } catch (\Throwable $e) {
            trace('OperationLog middleware error: ' . $e->getMessage(), 'error');
        } finally {
            // #9 异步写入：优先使用 register_shutdown_function 延迟写入
            if ($this->getConfig('async_write.enable', false)) {
                $this->registerShutdownFlush();
            } else {
                OperationLogListener::flush();
                LogContext::clear();
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
        // 解析控制器类名和方法名
        $controllerClass = $this->resolveControllerClass($request);
        $action = $request->action();

        if ($controllerClass === null || empty($action)) {
            return;
        }

        // #8 检查 exclude 排除规则
        if ($this->isExcluded($request, $controllerClass)) {
            return;
        }

        // 获取方法上的 OperationLog Attribute 实例（带缓存）
        $attributes = $this->getMethodAttributes($controllerClass, $action);
        if (empty($attributes)) {
            return;
        }

        // 获取类级默认配置（带缓存）
        $classDefault = $this->getClassDefaults($controllerClass);

        // 获取响应数据
        $responseData = $this->getResponseData($response);

        // 获取方法参数名映射
        $methodParams = $this->getMethodParams($controllerClass, $action, $request);

        // 构建变量池
        $variables = $this->buildVariables($request, $responseData, $methodParams);

        // 获取操作者信息
        $operatorInfo = $this->getOperatorInfo();

        foreach ($attributes as $attrData) {
            // 合并类级默认配置
            $attrData = $this->mergeWithDefaults($attrData, $classDefault);

            // 跳过无 template 的属性（纯类级默认不应生成日志）
            if (empty($attrData['template'])) {
                continue;
            }

            // 检查 conditions 条件
            if (!$this->checkConditions($attrData['conditions'], $responseData)) {
                continue;
            }

            // 渲染模板
            $content = VariableParser::render($attrData['template'], $variables);

            // #9 安全 JSON 编码 params（防止超大请求体）
            $paramsJson = '';
            if ($attrData['recordParams']) {
                $paramsJson = $this->safeJsonEncodeParams($request);
            }

            // === #11 慢操作自动标记 ===
            $tags = $attrData['tags'] ?? [];
            $slowThreshold = (int) $this->getConfig('performance.slow_threshold_ms', 3000);
            if ($duration > 0 && $duration >= $slowThreshold) {
                $tags[] = 'slow';
            }

            // 组装完整日志数据
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

            // === #4 敏感数据脱敏 ===
            $logData = $this->maskSensitiveData($logData);

            // 触发事件（Listener 缓冲，最后批量写入）
            event(new OperationLogEvent($logData));
        }
    }

    // ==========================================
    //  #4 敏感数据脱敏
    // ==========================================

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

            // 脱敏 params
            if (($scope['params'] ?? true) && !empty($logData['params'])) {
                $decoded = json_decode($logData['params'], true);
                if (is_array($decoded)) {
                    $masked = $masker->mask($decoded, $patterns);
                    $logData['params'] = json_encode($masked, JSON_UNESCAPED_UNICODE);
                }
            }

            // 脱敏 content
            if ($scope['content'] ?? true) {
                $logData['content'] = $masker->maskContent($logData['content']);
            }

            // 脱敏 extra
            if (($scope['extra'] ?? true) && !empty($logData['extra'])) {
                $decoded = json_decode($logData['extra'], true);
                if (is_array($decoded)) {
                    $masked = $masker->mask($decoded, $patterns);
                    $logData['extra'] = json_encode($masked, JSON_UNESCAPED_UNICODE);
                }
            }
        } catch (\Throwable $e) {
            trace('OperationLog sensitive masking failed: ' . $e->getMessage(), 'error');
        }

        return $logData;
    }

    // ==========================================
    //  #9 配置懒加载 + params 大小限制
    // ==========================================

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
        $params = $request->param();
        $json = json_encode($params, JSON_UNESCAPED_UNICODE);

        $maxSize = (int) $this->getConfig('storage.max_params_size', 65536); // 64KB
        if (strlen($json) > $maxSize) {
            return json_encode([
                '_truncated'      => true,
                '_original_size'  => strlen($json),
                '_preview'        => mb_substr($json, 0, min($maxSize, 2048), 'UTF-8'),
            ], JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    // ==========================================
    //  原有方法（优化后）
    // ==========================================

    /**
     * #4 解析控制器完整类名
     * 优先使用 ThinkPHP6 的 parseClass()，兼容多应用模式、控制器后缀等
     */
    protected function resolveControllerClass(Request $request): ?string
    {
        $controller = $request->controller();
        if (empty($controller)) {
            return null;
        }

        // 使用 ThinkPHP6 标准 parseClass 解析（处理多应用/后缀/命名空间）
        $class = app()->parseClass('controller', $controller);

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

        // #9 优化：IP 排除优先（最快，O(1) in_array）
        $excludeIps = $exclude['ips'] ?? [];
        if (!empty($excludeIps) && in_array($request->ip(), $excludeIps, true)) {
            return true;
        }

        // 排除指定控制器类
        $excludeControllers = $exclude['controllers'] ?? [];
        foreach ($excludeControllers as $pattern) {
            if (str_contains($pattern, '*')) {
                // 通配符匹配
                if (fnmatch($pattern, $controllerClass)) {
                    return true;
                }
            } else {
                // 精确匹配
                if ($controllerClass === ltrim($pattern, '\\')) {
                    return true;
                }
            }
        }

        // 排除指定路由（正则匹配请求URL，最慢放最后）
        $excludeRoutes = $exclude['routes'] ?? [];
        $requestUrl = $request->url();
        foreach ($excludeRoutes as $pattern) {
            if (preg_match('#' . $pattern . '#', $requestUrl)) {
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

        if ($cacheEnable) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $defaults = null;
        try {
            $reflectionClass = new ReflectionClass($className);
            $attrs = $reflectionClass->getAttributes(OperationLogAttribute::class);

            if (!empty($attrs)) {
                /** @var OperationLogAttribute $instance */
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
            Cache::set($cacheKey, $defaults, 3600);
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

        // template：方法未设置时继承类默认
        if (empty($methodAttr['template']) && !empty($classDefault['template'])) {
            $methodAttr['template'] = $classDefault['template'];
        }

        // channel：未显式设置时继承类默认
        if ($methodAttr['channel'] === 'default' && $classDefault['channel'] !== 'default') {
            $methodAttr['channel'] = $classDefault['channel'];
        }

        // conditions：方法为空时继承类默认
        if (empty($methodAttr['conditions']) && !empty($classDefault['conditions'])) {
            $methodAttr['conditions'] = $classDefault['conditions'];
        }

        // recordParams：方法未开启时继承类默认
        if (!$methodAttr['recordParams'] && $classDefault['recordParams']) {
            $methodAttr['recordParams'] = true;
        }

        // #5 level：方法未显式设置时继承类默认
        if (($methodAttr['level'] ?? 'INFO') === 'INFO' && ($classDefault['level'] ?? 'INFO') !== 'INFO') {
            $methodAttr['level'] = $classDefault['level'];
        }

        // tags：合并去重
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
            $attrs = $reflectionMethod->getAttributes(OperationLog::class);

            foreach ($attrs as $attr) {
                /** @var OperationLog $instance */
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
            // 反射失败时忽略
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
            'method_params'  => $methodParams,
            'request_params' => $request->param(),
            'response'       => $responseData,
            '$sys'           => $systemVars,
        ];
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
                return json_encode($extra, JSON_UNESCAPED_UNICODE);
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
            if ($actualValue != $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
