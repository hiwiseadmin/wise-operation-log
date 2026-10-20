<?php
declare(strict_types=1);

namespace wise\operationlog\tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use think\Request;

/**
 * wise-operation-log 测试基类
 *
 * 提供：
 * - 构造 think\Request 测试替身的辅助方法
 * - 反射调用受保护/私有方法的辅助
 * - 跨用例静态状态隔离（LogContext / Listener / AuditLog / VariableParser / Middleware 等）
 * - 包配置注入辅助
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('config')) {
            // 重置包配置为默认，避免上个用例污染（fixture/自定义在用例内覆盖）
            config([
                'wise-operation-log' => require __DIR__ . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-operation-log.php',
            ]);
        }
    }

    protected function tearDown(): void
    {
        // 静态状态隔离
        $this->resetStatic('wise\\operationlog\\context\\LogContext', 'context');
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'buffer');
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'storageInstance');
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'configLoaded');
        $this->resetStatic('wise\\operationlog\\listener\\OperationLogListener', 'maxBufferSize');
        $this->resetStatic('wise\\operationlog\\middleware\\AuditLog', 'buffer');
        $this->resetStatic('wise\\operationlog\\middleware\\AuditLog', 'shutdownRegistered');
        $this->resetStatic('wise\\operationlog\\middleware\\OperationLog', 'cachedConfig');
        $this->resetStatic('wise\\operationlog\\middleware\\OperationLog', 'shutdownFlushRegistered');

        if (class_exists('wise\\operationlog\\template\\VariableParser')) {
            \wise\operationlog\template\VariableParser::resetConfigLoaded();
        }

        if (function_exists('config')) {
            config([
                'wise-operation-log' => require __DIR__ . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-operation-log.php',
            ]);
        }

        parent::tearDown();
    }

    // ==========================================
    //  请求构造
    // ==========================================

    /**
     * 构造 think\Request 测试替身
     *
     * @param string $url
     * @param string $method
     * @param array  $params
     * @param array  $headers
     * @param string $rawBody
     * @param array  $extra 允许注入 controller/action/pathinfo/realIP
     * @return Request
     */
    protected function makeRequest(
        string $url = 'http://localhost/operation/test',
        string $method = 'POST',
        array $params = [],
        array $headers = [],
        string $rawBody = '',
        array $extra = []
    ): Request {
        $request = new Request();
        $request->setMethod($method);

        $ref = new ReflectionClass($request);

        if ($rawBody !== '') {
            $p = $ref->getProperty('content');
            $p->setAccessible(true);
            $p->setValue($request, $rawBody);
        }

        if ($headers !== []) {
            $request->withHeader($headers);
        }

        foreach (['param', 'route', 'get', 'post'] as $key) {
            $prop = $ref->getProperty($key);
            $prop->setAccessible(true);
            $prop->setValue($request, array_merge($prop->getValue($request) ?? [], $params));
        }

        foreach (['controller', 'action', 'pathinfo', 'realIP'] as $key) {
            if (isset($extra[$key])) {
                $prop = $ref->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($request, $extra[$key]);
            }
        }

        return $request;
    }

    /**
     * 构造 JSON 响应替身（think\Response 为抽象类且无构造函数）
     */
    protected function makeResponse($data = [], int $code = 200): \think\Response
    {
        return new \wise\operationlog\tests\Fixtures\TestResponse(
            $data,
            $code,
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 构造原始内容响应替身（用于非法 JSON 内容场景）
     */
    protected function makeHtmlResponse(string $content, int $code = 200): \think\Response
    {
        return new \wise\operationlog\tests\Fixtures\TestResponse($content, $code, $content);
    }

    // ==========================================
    //  配置 / 反射辅助
    // ==========================================

    protected function setConfig(array $config): void
    {
        config(['wise-operation-log' => $config]);
    }

    protected function mergeConfig(array $config): void
    {
        $current = config('wise-operation-log') ?? [];
        config(['wise-operation-log' => array_replace_recursive($current, $config)]);
    }

    protected function invokeInaccessible(object $object, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    protected function getInaccessible(object $object, string $property)
    {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    protected function setInaccessible(object $object, string $property, $value): void
    {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    protected function resetStatic(string $class, string $property): void
    {
        if (!class_exists($class)) {
            return;
        }
        $ref = new ReflectionClass($class);
        if (!$ref->hasProperty($property)) {
            return;
        }
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $default = $prop->getDefaultValue();
        $prop->setValue(null, $default);
    }
}
