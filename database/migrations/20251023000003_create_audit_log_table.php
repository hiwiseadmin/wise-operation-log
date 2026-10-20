<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 审计日志表
 *
 * 与操作日志表（operation_log）完全解耦，独立存储审计记录。
 * 审计日志无条件全量记录所有匹配请求，不依赖开发者 Attribute 标注。
 */
class CreateAuditLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('audit_log', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '审计日志表',
        ]);

        $table->addColumn(Column::string('action', 100)->setNull(false)->setComment('操作标识（点号格式，如 admin.user.delete）'))
            ->addColumn(Column::string('target_type', 50)->setNull(true)->setComment('目标类型（从控制器类名推断）'))
            ->addColumn(Column::string('target_id', 100)->setNull(true)->setComment('目标ID（请求参数中的 id）'))
            ->addColumn(Column::string('result', 10)->setNull(false)->setDefault('unknown')->setComment('操作结果: success/failure/error/unknown'))
            ->addColumn(Column::string('category', 20)->setNull(false)->setDefault('normal')->setComment('操作分类: danger/warning/normal/read'))
            ->addColumn(Column::string('method', 10)->setNull(false)->setComment('HTTP 请求方法'))
            ->addColumn(Column::string('path', 500)->setNull(false)->setComment('请求路径'))
            ->addColumn(Column::string('params_hash', 64)->setNull(true)->setComment('请求参数 SHA256 哈希'))
            ->addColumn(Column::integer('operator_id')->setNull(false)->setSigned(false)->setDefault(0)->setComment('操作者ID'))
            ->addColumn(Column::string('operator_name', 50)->setNull(false)->setDefault('system')->setComment('操作者名称'))
            ->addColumn(Column::string('tenant_id', 50)->setNull(true)->setComment('租户ID'))
            ->addColumn(Column::string('ip', 45)->setNull(false)->setComment('请求IP（支持 IPv6）'))
            ->addColumn(Column::string('user_agent', 500)->setNull(true)->setComment('用户代理'))
            ->addColumn(Column::integer('duration_ms')->setNull(true)->setSigned(false)->setComment('操作耗时（毫秒）'))
            ->addColumn(Column::integer('status_code')->setNull(true)->setLimit(6)->setComment('HTTP 响应状态码'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['action', 'create_time'], ['name' => 'idx_action_time'])
            ->addIndex(['operator_id', 'create_time'], ['name' => 'idx_operator_time'])
            ->addIndex(['target_type', 'target_id'], ['name' => 'idx_target'])
            ->addIndex(['ip', 'create_time'], ['name' => 'idx_ip_time'])
            ->addIndex(['tenant_id', 'create_time'], ['name' => 'idx_tenant_time'])
            ->addIndex(['result', 'create_time'], ['name' => 'idx_result_time'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('audit_log')) {
            $this->table('audit_log')->drop()->save();
        }
    }
}
