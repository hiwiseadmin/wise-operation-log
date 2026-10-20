/**
 * operation_log.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const Layer = window.Layer;

const Controller = {
        index() {
            // 初始化表格
            const table = new Table({
                idTable: 'table',
                url: '/admin/operation_log/getList',
                showColumns: true,
                pagination: true,
                dataDataField: 'data.list', // 假设返回的数据字段为 menus
                extend: {
                    index_url: '/admin/operation_log/index',
                    preview_url: '/admin/operation_log/preview'
                },
                toolbarBtn:['preview','refresh','export'],

                columns: [{
                    field: 'ck',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle'
                },{
                    field: 'id',
                    title: 'ID',
                    width: '60',
                    sortable: true
                }, {
                    field: 'operator_name',
                    title: '操作员',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'channel',
                    title: '模块',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'level',
                    title: '级别',
                    formatter: 'status',
                    custom: {
                        'INFO': 'success',
                        'NOTICE': 'info',
                        'WARNING': 'warning',
                        'ERROR': 'danger',
                        'CRITICAL': 'danger'
                    },
                    searchList: {
                        'INFO': 'INFO',
                        'NOTICE': 'NOTICE',
                        'WARNING': 'WARNING',
                        'ERROR': 'ERROR',
                        'CRITICAL': 'CRITICAL'
                    },
                    align: 'left',
                    width: '90'
                }, {
                    field: 'content',
                    title: '日志内容',
                    formatter: 'longtext',
                    align: 'left',
                    width: '240'
                }, {
                    field: 'request_url',
                    title: '请求URL',
                    formatter: 'longtext',
                    align: 'left',
                    width: '180'
                }, {
                    field: 'ip',
                    title: 'IP地址',
                    formatter: 'normal',
                    align: 'left',
                    width: '130'
                }, {
                    field: 'duration_ms',
                    title: '耗时(ms)',
                    formatter: 'normal',
                    align: 'left',
                    width: '90'
                }, {
                    field: 'tags',
                    title: '标签',
                    formatter: 'normal',
                    align: 'left',
                    width: '120'
                }, {
                    field: 'create_time',
                    title: '操作时间',
                    formatter: 'normal',
                    align: 'left',
                    width: '160'
                }, {
                    field: 'operate',
                    buttons: ['preview','del'],
                    title: '操作',
                    formatter: 'operate'
                }]
            });
        }
    };

export default Controller;
