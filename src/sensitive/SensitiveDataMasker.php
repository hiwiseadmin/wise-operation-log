<?php

declare(strict_types=1);

namespace wise\operationlog\sensitive;

/**
 * 敏感数据脱敏器
 *
 * 支持多种脱敏策略：替换、手机号、身份证、邮箱、银行卡、姓名
 * 对数组数据递归脱敏，对模板内容自动识别脱敏
 *
 * 使用示例：
 *   $masker = new SensitiveDataMasker();
 *   $masked = $masker->mask($params, $patterns);      // 数组脱敏
 *   $masked = $masker->maskContent('手机13812345678'); // 内容脱敏
 */
class SensitiveDataMasker
{
    /**
     * 脱敏策略映射
     */
    private const STRATEGIES = [
        'replace'   => 'maskReplace',
        'phone'     => 'maskPhone',
        'id_card'   => 'maskIdCard',
        'email'     => 'maskEmail',
        'bank_card' => 'maskBankCard',
        'name'      => 'maskName',
    ];

    /**
     * 手机号正则
     */
    private const PHONE_PATTERN = '/1[3-9]\d{9}/';

    /**
     * 身份证正则（18位）
     */
    private const ID_CARD_PATTERN = '/\d{17}[\dXx]/';

    /**
     * 邮箱正则
     */
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    /**
     * 对数组数据递归脱敏
     *
     * @param array  $data     待脱敏数据
     * @param array  $patterns 脱敏字段规则（key => 脱敏策略名），如 ['password' => 'replace', 'phone' => 'phone']
     * @param string $path     当前字段路径（用于通配符匹配）
     * @return array
     */
    public function mask(array $data, array $patterns, string $path = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;
            $matchedPattern = $this->matchPattern((string) $key, $currentPath, $patterns);

            if (is_array($value)) {
                $result[$key] = $this->mask($value, $patterns, $currentPath);
            } elseif ($matchedPattern && is_string($value) && $value !== '') {
                $result[$key] = $this->applyMask($value, $matchedPattern);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 模板内容脱敏：对 content 字符串中的敏感模式做正则替换
     *
     * 自动识别手机号、身份证、邮箱并脱敏。
     *
     * @param string $content 日志内容
     * @return string
     */
    public function maskContent(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        // 手机号脱敏: 1[3-9]\d{9} → 138****5678
        $content = preg_replace_callback(
            self::PHONE_PATTERN,
            fn($m) => $this->maskPhone($m[0]),
            $content
        );

        // 身份证脱敏: \d{17}[\dXx] → 320***********1234
        $content = preg_replace_callback(
            self::ID_CARD_PATTERN,
            fn($m) => $this->maskIdCard($m[0]),
            $content
        );

        // 邮箱脱敏: xxx@example.com → x***@example.com
        $content = preg_replace_callback(
            self::EMAIL_PATTERN,
            fn($m) => $this->maskEmail($m[0]),
            $content
        );

        return $content;
    }

    // ==========================================
    //  单项脱敏方法
    // ==========================================

    /**
     * 替换脱敏：全部替换为 ***
     */
    private function maskReplace(string $value): string
    {
        return '***';
    }

    /**
     * 手机号脱敏：保留前3后4 → 138****5678
     */
    private function maskPhone(string $value): string
    {
        $len = strlen($value);
        if ($len < 7) {
            return substr($value, 0, 3) . '***';
        }
        return substr($value, 0, 3) . '****' . substr($value, -4);
    }

    /**
     * 身份证脱敏：保留前3后4 → 320***********1234
     */
    private function maskIdCard(string $value): string
    {
        $len = strlen($value);
        if ($len < 8) {
            return substr($value, 0, 3) . '***';
        }
        return substr($value, 0, 3) . str_repeat('*', $len - 7) . substr($value, -4);
    }

    /**
     * 邮箱脱敏：local部分保留首字符 → z***@example.com
     */
    private function maskEmail(string $value): string
    {
        $parts = explode('@', $value, 2);
        if (count($parts) !== 2) {
            return $this->maskReplace($value);
        }
        $local  = $parts[0];
        $domain = $parts[1];
        $maskedLocal = $local[0] . '***';
        return $maskedLocal . '@' . $domain;
    }

    /**
     * 银行卡脱敏：保留前4后4 → 6222 **** **** 1234
     */
    private function maskBankCard(string $value): string
    {
        $len = strlen($value);
        if ($len < 8) {
            return substr($value, 0, 4) . '****';
        }
        return substr($value, 0, 4) . ' **** **** ' . substr($value, -4);
    }

    /**
     * 姓名脱敏：保留首字 → 张*
     */
    private function maskName(string $value): string
    {
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= 1) {
            return $value;
        }
        return mb_substr($value, 0, 1, 'UTF-8') . str_repeat('*', $len - 1);
    }

    // ==========================================
    //  辅助方法
    // ==========================================

    /**
     * 字段匹配逻辑
     *
     * 支持 * 通配符（fnmatch），同时匹配字段名和完整路径。
     *
     * @param string $fieldName 字段名（如 'password'）
     * @param string $fullPath  完整路径（如 'user.profile.password'）
     * @param array  $patterns  脱敏规则
     * @return string|null 匹配到的策略名，null 表示不脱敏
     */
    private function matchPattern(string $fieldName, string $fullPath, array $patterns): ?string
    {
        foreach ($patterns as $pattern => $strategy) {
            // 精确匹配字段名
            if ($pattern === $fieldName) {
                return $strategy;
            }
            // 通配符匹配字段名
            if (str_contains($pattern, '*') && fnmatch($pattern, $fieldName)) {
                return $strategy;
            }
            // 通配符匹配完整路径
            if (str_contains($pattern, '*') && fnmatch($pattern, $fullPath)) {
                return $strategy;
            }
        }
        return null;
    }

    /**
     * 执行脱敏策略
     */
    private function applyMask(string $value, string $strategy): string
    {
        if (!isset(self::STRATEGIES[$strategy])) {
            return $value;
        }
        $method = self::STRATEGIES[$strategy];
        return $this->$method($value);
    }
}
