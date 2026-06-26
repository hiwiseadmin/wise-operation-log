<?php

declare(strict_types=1);

namespace wise\operationlog\trait;

use think\facade\Config;

/**
 * WiseAdmin 包 Service 提供者共用 Trait
 *
 * 提供配置自动合并功能，宿主配置优先覆盖包默认配置。
 */
trait ServiceTrait
{
    /**
     * 合并包默认配置到全局配置
     *
     * @param string $configName 配置名（不含 .php 后缀）
     * @return array 合并后的配置
     */
    protected function mergeConfig(string $configName): array
    {
        $defaultConfig = $this->loadPackageDefaultConfig($configName);
        if ($defaultConfig === null) {
            return [];
        }

        $appConfig = Config::get($configName, []);
        $mergedConfig = $this->arrayMergeRecursive($defaultConfig, $appConfig);
        Config::set($mergedConfig, $configName);
        return $mergedConfig;
    }

    /**
     * 加载包默认配置文件
     */
    protected function loadPackageDefaultConfig(string $configName): ?array
    {
        $filePath = $this->getPackageConfigFilePath($configName);
        if (!file_exists($filePath)) {
            return null;
        }
        return require $filePath;
    }

    /**
     * 获取包内配置文件的绝对路径
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }

    /**
     * 递归合并数组（宿主配置优先）
     */
    protected function arrayMergeRecursive(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (is_int($key)) {
                $result[] = $value;
            } elseif (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->arrayMergeRecursive($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
