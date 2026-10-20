<?php
declare(strict_types=1);

namespace app\controller;

use wise\operationlog\attribute\OperationLog;

/**
 * 测试用 Fixture 控制器，用于驱动 OperationLog 中间件的完整流程。
 */
class SampleController
{
    #[OperationLog(template: '删除了用户{user.name}，状态{response.code}')]
    public function delete(int $id, string $name = 'tester'): array
    {
        return ['code' => 0];
    }

    #[OperationLog(channel: 'admin', template: '更新了配置{config.key}', conditions: ['response.code' => 0], tags: ['cfg'])]
    public function update(): array
    {
        return ['code' => 0];
    }

    #[OperationLog(template: '创建了记录', recordParams: true)]
    public function store(): array
    {
        return ['code' => 0];
    }

    // 无 Attribute 的方法，用于测试“方法无注解直接返回”分支
    public function plain(): array
    {
        return [];
    }
}
