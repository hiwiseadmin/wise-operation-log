<?php
declare(strict_types=1);

namespace app\controller;

use wise\operationlog\attribute\OperationLog;

/**
 * 带「类级默认配置」的 Fixture 控制器，用于测试 getClassDefaults / mergeWithDefaults 继承逻辑。
 */
#[OperationLog(channel: 'admin', tags: ['cls'], level: 'WARNING')]
class DefaultsController
{
    #[OperationLog(template: '默认配置方法')]
    public function delete(): array
    {
        return ['code' => 0];
    }
}
