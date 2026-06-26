<?php

declare(strict_types=1);

namespace wise\operationlog\context;

/**
 * 操作日志上下文管理器
 *
 * Swoole 协程适配：
 * - 协程环境（getCid() > 0）→ 使用 Coroutine Context 隔离存储
 * - PHP-FPM 环境 → 使用静态数组存储
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
        if (self::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx[self::COROUTINE_KEY])) {
                $ctx[self::COROUTINE_KEY] = [];
            }
            $ctx[self::COROUTINE_KEY][$key] = $value;
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
        if (self::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return $ctx[self::COROUTINE_KEY][$key] ?? $default;
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
        if (self::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return $ctx[self::COROUTINE_KEY] ?? [];
        }
        return static::$context;
    }
	
    /**
     * 清空所有上下文变量
     */
    public static function clear(): void
    {
        if (self::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            unset($ctx[self::COROUTINE_KEY]);
            return;
        }
        static::$context = [];
    }

    protected static function isSwooleCoroutine(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
