<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 操作日志表
 */
class CreateOperationLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('operation_log', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '操作日志表',
        ]);

        $table->addColumn(Column::string('channel', 50)->setNull(false)->setDefault('')->setComment('频道标识'))
            ->addColumn(Column::string('level', 20)->setNull(false)->setDefault('INFO')->setComment('日志级别: DEBUG/INFO/NOTICE/WARNING/ERROR/CRITICAL'))
            ->addColumn(Column::text('content')->setNull(true)->setComment('日志内容描述'))
            ->addColumn(Column::text('params')->setNull(true)->setComment('请求参数JSON'))
            ->addColumn(Column::integer('duration_ms')->setNull(true)->setSigned(false)->setDefault(null)->setComment('操作耗时（毫秒）'))
            ->addColumn(Column::integer('operator_id')->setNull(true)->setSigned(false)->setComment('操作者ID'))
            ->addColumn(Column::string('operator_name', 100)->setNull(true)->setDefault('')->setComment('操作者名称'))
            ->addColumn(Column::string('request_url', 500)->setNull(true)->setDefault('')->setComment('请求URL'))
            ->addColumn(Column::string('ip', 50)->setNull(true)->setDefault('')->setComment('请求IP'))
            ->addColumn(Column::string('user_agent', 500)->setNull(true)->setDefault('')->setComment('用户代理'))
            ->addColumn(Column::string('tags', 255)->setNull(true)->setDefault('')->setComment('标签（逗号分隔）'))
            ->addColumn(Column::text('extra')->setNull(true)->setComment('扩展数据JSON'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['duration_ms'], ['name' => 'idx_duration'])
            ->addIndex(['level', 'create_time'], ['name' => 'idx_level_time'])
            ->addIndex(['operator_id', 'create_time'], ['name' => 'idx_operator_time'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('operation_log')) {
            $this->table('operation_log')->drop()->save();
        }
    }
}
