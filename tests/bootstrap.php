<?php
/**
 * PHPUnit 引导文件（wise-operation-log）
 *
 * - 加载 Composer autoload（src/vendor）
 * - 手动注册包 PSR-4（含测试命名空间、fixture 控制器命名空间）
 * - 引入 ThinkPHP 全局助手（app/config/event/trace...）
 * - 引导最小 think\App 容器 + SQLite(:memory:) 数据库 + 建表
 * - 加载包默认配置（wise-operation-log）
 */

// ==================== Composer autoload ====================
$candidates = [
    dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', // src/vendor
    dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', // 仓库根 vendor
];

foreach ($candidates as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

// ==================== 手动 PSR-4 ====================
$packageSrc = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
$fixturesDir = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures';

spl_autoload_register(function (string $class) use ($packageSrc, $fixturesDir): void {
    $maps = [
        'wise\\operationlog\\tests\\' => __DIR__ . DIRECTORY_SEPARATOR,
        'wise\\operationlog\\'        => $packageSrc . DIRECTORY_SEPARATOR,
        'app\\controller\\'           => $fixturesDir . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR,
        'app\\admin\\controller\\'    => $fixturesDir . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR,
        'wise\\market\\controller\\'  => $fixturesDir . DIRECTORY_SEPARATOR . 'market' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR,
    ];
    foreach ($maps as $prefix => $base) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $file = $base . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
            return;
        }
    }
});

// ==================== ThinkPHP 全局助手 ====================
if (!function_exists('app')) {
    $frameworkHelper = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'topthink' . DIRECTORY_SEPARATOR . 'framework'
        . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helper.php';
    if (file_exists($frameworkHelper)) {
        require_once $frameworkHelper;
    }
}

// ==================== 引导 think\App + 容器 ====================
if (class_exists('think\\App')) {
    try {
        $app = new \think\App();
        \think\Container::setInstance($app);

        config([
            'log' => [
                'default'  => 'file',
                'channels' => [
                    'file' => [
                        'type' => 'File',
                        'path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-operation-log-test.log',
                    ],
                ],
            ],
            'cache' => [
                'default' => 'file',
                'stores'  => [
                    'file' => [
                        'type' => 'File',
                        'path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-operation-log-cache',
                    ],
                ],
            ],
            'database' => [
                'default' => 'test',
                'connections' => [
                    'test' => [
                        'type'     => 'sqlite',
                        'database' => ':memory:',
                        'prefix'   => 'ws_',
                        'charset'  => 'utf8',
                    ],
                ],
            ],
            // 包默认配置
            'wise-operation-log' => require __DIR__ . DIRECTORY_SEPARATOR . '..'
                . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-operation-log.php',
        ]);

        bind('request', \think\Request::class);

        // 宿主 App 注册了 think\Request <-> app\Request 的循环别名绑定，
        // 会使 Container::getAlias() 无限递归耗尽内存。此处移除反向别名打破循环。
        try {
            $bindProp = new \ReflectionProperty(\think\Container::class, 'bind');
            $bindProp->setAccessible(true);
            $bindings = $bindProp->getValue($app);
            if (isset($bindings['app\\Request'])) {
                unset($bindings['app\\Request']);
                $bindProp->setValue($app, $bindings);
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        // 关键：将容器中的 DbManager 注入 think\Model 静态属性，
        // 否则模型查询会因 Model::$db 为 null 而报 "connect() on null"。
        if (class_exists('think\\Model') && method_exists('think\\Model', 'setDb')) {
            \think\Model::setDb(app('db'));

            // 注册模型 maker，补齐 datetime 转换格式，避免读取 create_time 时
            // TimeStamp::formatDateTime(null,...) 抛 str_contains(null) 错误。
            \think\Model::maker(function (\think\Model $model): void {
                if ($model->getDateFormat() === null) {
                    $model->setDateFormat('Y-m-d H:i:s');
                }
            });
        }

        // 建表（前缀 ws_ 由框架自动添加）
        $ddl = [
            'ws_operation_log' => '
                CREATE TABLE IF NOT EXISTS ws_operation_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel VARCHAR(50),
                    level VARCHAR(20),
                    operator_id INTEGER,
                    operator_name VARCHAR(100),
                    request_url VARCHAR(500),
                    ip VARCHAR(50),
                    user_agent VARCHAR(500),
                    tags VARCHAR(255),
                    content TEXT,
                    params TEXT,
                    extra TEXT,
                    duration_ms INTEGER,
                    create_time VARCHAR(32)
                )',
            'ws_operation_log_archive' => '
                CREATE TABLE IF NOT EXISTS ws_operation_log_archive (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel VARCHAR(50),
                    level VARCHAR(20),
                    operator_id INTEGER,
                    operator_name VARCHAR(100),
                    request_url VARCHAR(500),
                    ip VARCHAR(50),
                    user_agent VARCHAR(500),
                    tags VARCHAR(255),
                    content TEXT,
                    params TEXT,
                    extra TEXT,
                    duration_ms INTEGER,
                    archived_at VARCHAR(32),
                    create_time VARCHAR(32)
                )',
            'ws_audit_log' => '
                CREATE TABLE IF NOT EXISTS ws_audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    action VARCHAR(100),
                    target_type VARCHAR(50),
                    target_id VARCHAR(100),
                    result VARCHAR(10),
                    category VARCHAR(20),
                    method VARCHAR(10),
                    path VARCHAR(500),
                    params_hash VARCHAR(64),
                    operator_id INTEGER,
                    operator_name VARCHAR(50),
                    tenant_id VARCHAR(50),
                    ip VARCHAR(45),
                    user_agent VARCHAR(500),
                    duration_ms INTEGER,
                    status_code INTEGER,
                    create_time VARCHAR(32)
                )',
        ];

        foreach ($ddl as $sql) {
            \think\facade\Db::connect('test')->execute($sql);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "wise-operation-log bootstrap failed: " . $e->getMessage() . "\n");
    }
}
