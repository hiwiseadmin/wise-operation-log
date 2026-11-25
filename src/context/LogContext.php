<?php

declare(strict_types=1);

namespace wise\operationlog\context;

use wise\support\runtime\Runtime;

/**
 * 操作日志上下文管理器
 *
 * 协程适配（C-3 收敛）：运行时的判断与协程上下文获取统一委托给
 * {@see Runtime::context()}，本类不再直接引用 `\Swoole\Coroutine`：
 * - 协程环境 → 上下文存放在协程 Context 中，按协程隔离
 * - PHP-FPM / CLI 环境 → 使用静态数组存储
 */
class LogContext
{
    private const COROUTINE_KEY = '__oplog_context';

    /** @var array<string, mixed> PHP-FPM 模式存储 */
    protected static array $context = [];

    /**
     * 设置上下文变量
     *
     * @param string $key   变量名
     * @param mixed  $value 变量值
     */
    public static function set(string $key, mixed $value): void
    {
        $ctx = Runtime::context();
        if ($ctx !== null) {
            $data                     = $ctx[self::COROUTINE_KEY] ?? [];
            $data[$key]               = $value;
            $ctx[self::COROUTINE_KEY] = $data;
            return;
        }
        static::$context[$key] = $value;
    }

    /**
     * 获取上下文变量
     *
     * @param string $key     变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $ctx = Runtime::context();
        if ($ctx !== null) {
            $data = $ctx[self::COROUTINE_KEY] ?? [];
            return $data[$key] ?? $default;
        }
        return static::$context[$key] ?? $default;
    }

    /**
     * 获取所有上下文变量
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $ctx = Runtime::context();
        if ($ctx !== null) {
            return $ctx[self::COROUTINE_KEY] ?? [];
        }
        return static::$context;
    }

    /**
     * 清空所有上下文变量
     */
    public static function clear(): void
    {
        $ctx = Runtime::context();
        if ($ctx !== null) {
            unset($ctx[self::COROUTINE_KEY]);
            return;
        }
        static::$context = [];
    }
}
