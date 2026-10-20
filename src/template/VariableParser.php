<?php

declare(strict_types=1);

namespace wise\operationlog\template;

use wise\operationlog\traits\DotPathTrait;

/**
 * 变量解析器
 * 解析日志模板中的占位符并替换为实际值
 */
class VariableParser
{
    use DotPathTrait;

    /**
     * 单例实例缓存（#9 性能优化：避免每变量创建实例）
     */
    protected static ?self $parserInstance = null;

    /**
     * 自定义过滤器注册表
     * @var array<string, callable>
     */
    protected static array $customFilters = [];

    /**
     * 配置过滤器是否已加载标记
     * @var bool
     */
    protected static bool $configLoaded = false;

    /**
     * 获取单例实例
     */
    protected static function getInstance(): self
    {
        return self::$parserInstance ??= new static();
    }

    /**
     * 注册自定义过滤器
     *
     * @param string   $name     过滤器名称
     * @param callable $callback 过滤器回调
     */
    public static function registerFilter(string $name, callable $callback): void
    {
        static::$customFilters[$name] = $callback;
    }

    /**
     * 重置配置加载标记（用于测试或配置热更新场景）
     */
    public static function resetConfigLoaded(): void
    {
        static::$configLoaded = false;
        static::$parserInstance = null;
    }

    /**
     * 渲染模板
     *
     * @param string               $template  模板字符串
     * @param array<string, mixed> $variables 变量池
     * @return string 渲染后的字符串
     */
    public static function render(string $template, array $variables): string
    {
        // 仅首次调用时加载配置中的自定义过滤器
        if (!static::$configLoaded) {
            static::loadConfigFilters();
            static::$configLoaded = true;
        }

        return preg_replace_callback(
            '/\{([a-zA-Z0-9_.\$]+)(\|[^}]+)?\}/',
            function (array $matches) use ($variables) {
                $varPath = $matches[1];
                $filterStr = isset($matches[2]) ? $matches[2] : '';

                $value = static::resolveVariable($varPath, $variables);

                if ($filterStr !== '') {
                    $value = static::applyFilters($value, $filterStr);
                }

                return $value !== null ? (string) $value : '';
            },
            $template
        );
    }

    /**
     * 解析变量路径获取值
     * 优先级：自定义上下文 > 方法参数名 > 请求参数 > 响应数据(response.) > 系统变量($sys.)
     *
     * @param string               $varPath   变量路径
     * @param array<string, mixed> $variables 变量池
     * @return mixed
     */
    protected static function resolveVariable(string $varPath, array $variables): mixed
    {
        $instance = static::getInstance();

        // 系统内置变量 $sys. 前缀
        if (str_starts_with($varPath, '$sys.')) {
            return $instance->getByDotPath($variables['$sys'] ?? [], substr($varPath, 5));
        }

        // 响应数据 response. 前缀
        if (str_starts_with($varPath, 'response.')) {
            return $instance->getByDotPath($variables['response'] ?? [], substr($varPath, 9));
        }

        // 自定义上下文
        $value = $instance->getByDotPath($variables['context'] ?? [], $varPath);
        if ($value !== null) {
            return $value;
        }

        // 方法参数名
        $value = $instance->getByDotPath($variables['method_params'] ?? [], $varPath);
        if ($value !== null) {
            return $value;
        }

        // 请求参数
        $value = $instance->getByDotPath($variables['request_params'] ?? [], $varPath);
        if ($value !== null) {
            return $value;
        }

        return null;
    }

    /**
     * 应用过滤器链
     *
     * @param mixed  $value     原始值
     * @param string $filterStr 过滤器字符串，如 "|date:'Y-m-d'|default:'-'"
     * @return mixed
     */
    protected static function applyFilters(mixed $value, string $filterStr): mixed
    {
        $filterStr = ltrim($filterStr, '|');
        if ($filterStr === '') {
            return $value;
        }

        $filters = preg_split('/\|(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $filterStr);

        foreach ($filters as $filter) {
            $filter = trim($filter);
            if ($filter === '') {
                continue;
            }
            $value = static::applySingleFilter($value, $filter);
        }

        return $value;
    }

    /**
     * 应用单个过滤器
     *
     * @param mixed  $value          输入值
     * @param string $filterDef      过滤器定义，如 "date:'Y-m-d'"
     * @return mixed
     */
    protected static function applySingleFilter(mixed $value, string $filterDef): mixed
    {
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):(.+)$/', $filterDef, $matches)) {
            $filterName = $matches[1];
            $filterArg = static::parseFilterArg($matches[2]);
        } else {
            $filterName = $filterDef;
            $filterArg = null;
        }

        // 自定义过滤器优先
        if (isset(static::$customFilters[$filterName])) {
            return (static::$customFilters[$filterName])($value, $filterArg);
        }

        return match ($filterName) {
            'default' => ($value === null || $value === '') ? $filterArg : $value,
            'date'    => static::filterDate($value, $filterArg ?? 'Y-m-d H:i:s'),
            default   => $value,
        };
    }

    /**
     * 解析过滤器参数值
     * 支持单引号包裹的字符串参数
     *
     * @param string $arg 原始参数字符串
     * @return string|null
     */
    protected static function parseFilterArg(string $arg): ?string
    {
        $arg = trim($arg);
        if (preg_match("/^'(.*)'$/", $arg, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^"(.*)"$/', $arg, $matches)) {
            return $matches[1];
        }
        return $arg;
    }

    /**
     * 日期格式化过滤器
     *
     * @param mixed  $value   时间戳或日期字符串
     * @param string $format  日期格式
     * @return string
     */
    protected static function filterDate(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            if (is_numeric($value)) {
                return date($format, (int) $value);
            }
            $timestamp = strtotime((string) $value);
            if ($timestamp === false) {
                return (string) $value;
            }
            return date($format, $timestamp);
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /**
     * 从配置加载自定义过滤器
     */
    protected static function loadConfigFilters(): void
    {
        $customFilters = config('wise-operation-log.custom_filters', []);
        foreach ($customFilters as $name => $callback) {
            if (is_callable($callback) && !isset(static::$customFilters[$name])) {
                static::$customFilters[$name] = $callback;
            }
        }
    }
}
