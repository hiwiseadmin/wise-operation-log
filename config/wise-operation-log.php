<?php

declare(strict_types=1);

/**
 * 操作日志配置文件
 */
return [
    // ==========================================
    //  存储配置
    // ==========================================
    'storage' => [
        'driver'     => \wise\operationlog\storage\DatabaseStorage::class,
        'connection' => '',
        // #9 性能优化：params 字段最大大小（字节），超出后截断
        'max_params_size' => 65536,  // 64KB
    ],

    // ==========================================
    //  操作者信息获取
    // ==========================================
    'operator' => [
        'id_resolver'   => function () {
            return auth_id('admin');
        },
        'name_resolver' => function () {
            $user = auth_user('admin');
            return $user ? ($user->name ?? $user->nickname ?? $user->username ?? '') : '';
        },
    ],

    // 扩展字段解析器
    'extra_resolver' => null,
    // 示例：
    // 'extra_resolver' => function() {
    //     return [
    //         'department'  => session('department'),
    //         'request_id'  => request()->header('x-request-id'),
    //     ];
    // },

    // ==========================================
    //  系统内置变量（#11 新增 duration_ms / duration_s）
    // ==========================================
    'system_vars' => [
        'datetime' => function () {
            return date('Y-m-d H:i:s');
        },
        // #11 操作耗时（毫秒）
        'duration_ms' => function () {
            return \wise\operationlog\context\LogContext::get('__duration_ms', 0);
        },
        // #11 操作耗时（秒）
        'duration_s' => function () {
            $ms = \wise\operationlog\context\LogContext::get('__duration_ms', 0);
            return $ms > 0 ? round($ms / 1000, 2) : 0;
        },
    ],

    // ==========================================
    //  自定义过滤器
    // ==========================================
    'custom_filters' => [
        // 示例：手机号脱敏
        // 'mask' => function($val) { return substr($val, 0, 3) . '***'; },
    ],

    // ==========================================
    //  缓存配置
    // ==========================================
    'cache' => [
        'enable'     => true,
        'key_prefix' => 'oplog_attr:',
    ],

    // ==========================================
    //  队列配置
    // ==========================================
    'queue' => [
        'enable'     => false,
        'queue_name' => 'operation_log',
    ],

    // ==========================================
    //  #10 缓冲配置
    // ==========================================
    'buffer' => [
        'max_size'      => 500,   // 单次请求最大缓冲条数（触发分段刷新）
        'shutdown_save' => true,  // 是否在 shutdown 兜底写入（Service 注册）
        'queue_batch'   => true,  // 是否将整批日志作为一个队列任务
    ],

    // ==========================================
    //  中间件配置
    // ==========================================
    'middleware' => [
        'enable' => true,
    ],

    // ==========================================
    //  #9 异步写入配置
    // ==========================================
    'async_write' => [
        'enable' => false,       // 开启后使用 register_shutdown_function 延迟写入
    ],

    // ==========================================
    //  #5 日志级别配置
    // ==========================================
    'level' => [
        'default' => 'INFO',     // 默认日志级别
    ],

    // ==========================================
    //  #11 性能监控配置
    // ==========================================
    'performance' => [
        'slow_threshold_ms' => 3000,  // 慢操作阈值（毫秒），超过后自动加 slow 标签
    ],

    // ==========================================
    //  #6 TTL 日志保留策略
    // ==========================================
    'ttl' => [
        'days'         => 0,    // 保留天数，0 表示不自动清理
        'max_records'  => 0,    // 最多保留条数，0 表示不限制
        'auto_cleanup' => false, // 是否在服务启动时概率触发自动清理
    ],

    // ==========================================
    //  #8 归档配置
    // ==========================================
    'archive' => [
        // 是否启用归档
        'enable' => false,

        // 归档触发方式
        'trigger' => [
            'command' => true,   // 手动命令 php think wise:operationlog:archive
            'cron'    => false,  // 建议配合 cron 定时
            'auto'    => false,  // 概率触发
        ],

        // 归档 N 天前的日志
        'days' => 90,

        // 归档目标（策略互斥，优先级：table > csv > json）
        'target' => [
            'type'       => 'table',   // table / csv / json
            // table 模式：归档到同一数据库的另一张表
            'table_name' => 'operation_log_archive',
            // csv/json 模式：归档到文件
            'file_path'  => runtime_path('archive/operation_log'),
            'chunk_size' => 5000,
        ],

        // 归档后操作
        'after_archive' => [
            'delete_source' => true,   // 归档后是否删除原表数据
        ],

        // 压缩（仅 file 模式）
        'compress' => false,
    ],

    // ==========================================
    //  #4 敏感数据脱敏配置
    // ==========================================
    'sensitive' => [
        // 是否启用脱敏
        'enable' => true,

        // 脱敏字段规则（按字段名匹配，支持 * 通配符）
        'fields' => [
            // 替换脱敏（完全替换为 ***）
            'password'        => 'replace',
            'old_password'    => 'replace',
            'new_password'    => 'replace',
            'confirm_password'=> 'replace',
            'token'           => 'replace',
            'secret'          => 'replace',
            'access_token'    => 'replace',
            'api_key'         => 'replace',
            '*.password'      => 'replace',
            '*passwd*'        => 'replace',
            '*secret*'        => 'replace',
            '*token*'         => 'replace',

            // 手机号脱敏 → 138****5678
            'phone'           => 'phone',
            'mobile'          => 'phone',
            'tel'             => 'phone',
            'telephone'       => 'phone',

            // 身份证脱敏 → 320***********1234
            'id_card'         => 'id_card',
            'id_number'       => 'id_card',
            'idcard'          => 'id_card',

            // 邮箱脱敏 → z***@example.com
            'email'           => 'email',
            'mail'            => 'email',

            // 银行卡脱敏 → 6222 **** **** 1234
            'bank_card'       => 'bank_card',
            'bank_account'    => 'bank_card',

            // 姓名脱敏 → 张*
            'real_name'       => 'name',
            'full_name'       => 'name',
        ],

        // 自动识别脱敏（对 content 中出现的手机号/身份证/邮箱自动脱敏 — 即使没有字段匹配）
        'auto_detect' => true,

        // 脱敏范围
        'scope' => [
            'params'   => true,   // 脱敏 recordParams 入库的字段
            'content'  => true,   // 脱敏内容描述模板渲染后的结果
            'extra'    => true,   // 脱敏 extra 扩展字段
            'response' => true,   // 脱敏模板变量中的响应数据（防止 {response.xxx} 明文入库）
        ],
    ],

    // ==========================================
    //  #8 排除规则
    // ==========================================
    'exclude' => [
        'controllers' => [],     // 排除的控制器类（支持 fnmatch 通配符），如 ['app\controller\Debug*']
        'routes'      => [],     // 排除的路由（正则匹配URL），如 ['^/api/health']
        'ips'         => [],     // 排除的IP地址，如 ['127.0.0.1']
    ],

    // ==========================================
    //  审计日志配置（独立于操作日志，无条件全量记录）
    // ==========================================
    'audit' => [
        // 是否启用审计日志
        'enabled'        => true,

        // 记录哪些 HTTP 方法的请求
        'methods'        => ['POST', 'PUT', 'DELETE', 'PATCH'],

        // 白名单排除路径（支持通配符 *）
        'exclude'        => ['admin/login/*', 'admin/captcha/*'],

        // 是否记录请求参数 SHA256 哈希
        'params_hash'    => true,

        // 是否采信客户端 x-tenant-id 请求头作为租户标识。
        // 该请求头客户端可控，开启后审计归属可能被伪造，默认关闭。
        'trust_request_tenant_header' => false,

        // 缓冲区大小（条），满后自动批量写入
        'buffer_size'    => 200,

        // 日志保留天数（供清理命令使用）
        'retention_days' => 1095,
    ],

    // ==========================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ==========================================
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable'     => true,
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups'     => [
            // 后台「操作日志」页面（/admin/operation_log/*）：投递到 admin 应用，
            // 以相对前缀 '' 落地，鉴权由 admin 应用级 RBAC 中间件统一负责。
            'admin_operation_log' => [
                'enable'     => true,
                'prefix'     => 'admin',
                'middleware' => [],
            ],
        ],
    ],
];
