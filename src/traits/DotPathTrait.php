<?php

declare(strict_types=1);

namespace wise\operationlog\traits;

/**
 * 点分路径解析 Trait
 * 提供 getByDotPath() 方法，从嵌套数据结构中按点分路径取值
 *
 * 支持 array 下标和 object 属性，被 VariableParser 和 OperationLog 共用
 */
trait DotPathTrait
{
    /**
     * 通过点分路径从数据中获取值
     *
     * @param mixed  $data 数据源（数组或对象）
     * @param string $path 点分路径，如 "article.title"
     * @return mixed
     */
    protected function getByDotPath(mixed $data, string $path): mixed
    {
        if ($data === null) {
            return null;
        }

        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if ($current === null) {
                return null;
            }

            if (is_array($current)) {
                if (!array_key_exists($segment, $current)) {
                    return null;
                }
                $current = $current[$segment];
            } elseif ($current instanceof \ArrayAccess) {
                if (!isset($current[$segment])) {
                    return null;
                }
                $current = $current[$segment];
            } elseif (is_object($current)) {
                if (!property_exists($current, $segment)) {
                    return null;
                }
                $current = $current->$segment;
            } else {
                return null;
            }
        }

        return $current;
    }
}
