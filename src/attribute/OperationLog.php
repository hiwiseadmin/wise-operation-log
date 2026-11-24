<?php

declare(strict_types=1);

namespace wise\operationlog\attribute;

use Attribute;

/**
 * 操作日志 Attribute
 * 支持放置在类（作为默认配置）和方法上
 *
 * 类级用法：仅配置公共默认值，无需 template
 *   #[OperationLog(channel: 'admin', tags: ['user'])]
 *
 * 方法级用法：必须指定 template
 *   #[OperationLog(template: '删除了用户{user.name}')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OperationLog
{
    /**
     * @param string $template     日志模板，支持占位符如 {name}（类级可省略，方法级必填）
     * @param string $channel      日志频道
     * @param array  $conditions   条件日志，格式：["字段路径" => "期望值"]
     * @param bool   $recordParams 是否记录请求参数
     * @param array  $tags         日志标签
     * @param string $level        日志级别: DEBUG/INFO/NOTICE/WARNING/ERROR/CRITICAL（默认 INFO）
     */
    public function __construct(
        public string $template = '',
        public string $channel = 'default',
        public array $conditions = [],
        public bool $recordParams = false,
        public array $tags = [],
        public string $level = 'INFO'
    ) {}
}
