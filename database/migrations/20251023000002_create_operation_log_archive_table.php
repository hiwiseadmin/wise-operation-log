<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建操作日志归档表
 */
class CreateOperationLogArchiveTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('operation_log_archive', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '操作日志归档表',
        ]);

        $table->addColumn(Column::string('channel', 50)->setNull(false)->setDefault('')->setComment('频道标识'))
            ->addColumn(Column::string('level', 20)->setNull(false)->setDefault('INFO')->setComment('日志级别'))
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
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('原始创建时间'))
            ->addColumn(Column::dateTime('archived_at')->setNull(false)->setComment('归档时间'))
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['level', 'archived_at'], ['name' => 'idx_level_archived'])
            ->addIndex(['operator_id', 'archived_at'], ['name' => 'idx_operator_archived'])
            ->addIndex(['archived_at'], ['name' => 'idx_archived_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('operation_log_archive')) {
            $this->table('operation_log_archive')->drop()->save();
        }
    }
}
