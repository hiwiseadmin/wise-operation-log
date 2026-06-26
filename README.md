# wiseadmin/wise-operation-log

> 基于 PHP8 Attribute 的声明式操作日志记录 —— ThinkPHP 6/8 操作日志扩展

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn) 
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--operation--log-orange)](https://packagist.org/packages/wiseadmin/wise-operation-log)

---

## 特性

| 模块 | 说明 |
|------|------|
| **PHP8 Attribute** | 声明式 `#[OperationLog]` 注解，零侵入记录操作日志 |
| **模板引擎** | `{变量名}` 占位符 + `|filter` 管道过滤，灵活组装日志内容 |
| **变量来源** | 自定义上下文 / 方法参数名 / 请求参数 / 响应数据 / 系统变量 五级匹配 |
| **类级注解** | 支持类级默认配置，方法自动继承 channel / tags / conditions |
| **条件日志** | `conditions` 参数按响应值条件记录 |
| **队列支持** | 支持 topthink/think-queue 异步写入，队列不可用时自动降级同步 |
| **Swoole 协程** | LogContext 自动检测运行环境，Swoole 协程隔离存储 |
| **TTL 自动清理** | 按天数 + 条数上限自动清理过期日志 |
| **排除规则** | 按 channel / controller / route / IP 排除不记录日志的请求 |
| **Facade 门面** | `OperationLog::query()` / `cleanup()` / `retain()` / `stats()` 一行搞定 |

---

## 安装

```bash
composer require wiseadmin/wise-operation-log
```

安装完成后：

- **配置自动加载**：框架启动时通过 Composer 自动发现机制载入操作日志配置
- **数据表自动创建**：服务首次启动时自动检测并创建 `operation_log` 数据表，无需手动操作

> 如需自定义配置，执行 `php think wise:operationlog:publish` 将配置文件发布到项目 `config/` 目录后修改即可。

---

## 快速开始

### 1. 在控制器上使用 Attribute

```php
use wise\operationlog\attribute\OperationLog;

// 类级注解：配置公共默认值（template 可省略）
#[OperationLog(channel: 'admin', tags: ['user'])]
class UserController
{
    // 自动继承 channel + tags，只需写 template
    #[OperationLog(template: '删除了用户{user.name}')]
    public function delete($id) {}

    // 继承 channel + tags，覆盖 recordParams
    #[OperationLog(template: '创建了用户{user.name}', recordParams: true)]
    public function create() {}
}
```

### 2. 在上下文中设置变量

```php
use wise\operationlog\context\LogContext;

LogContext::set('title', '我的文章标题');
LogContext::set('article', ['title' => '文章标题', 'id' => 1]);
```

### 3. 注册中间件

在全局中间件配置 `app/middleware.php` 中添加：

```php
return [
    \wise\operationlog\middleware\OperationLog::class,
];
```

---

## 配置

发布配置：

```bash
php think wise:operationlog:publish
```

---

## Attribute 参数说明

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `template` | string | `''` | 日志模板，类级可省略，方法级必填 |
| `channel` | string | `'default'` | 日志频道 |
| `conditions` | array | `[]` | 条件日志，格式 `["字段路径" => "期望值"]` |
| `recordParams` | bool | `false` | 是否记录请求参数 |
| `tags` | array | `[]` | 日志标签 |

---

## 模板语法

### 占位符

使用 `{变量名}` 格式引用变量：

```
创建了文章：{title}
修改了用户：{user.name}
```

### 变量来源优先级

1. **自定义上下文** — `LogContext::set()` 设置的变量
2. **方法参数名** — 控制器方法参数对应请求参数值
3. **请求参数** — `Request::param()` 所有参数
4. **响应数据** — `{response.code}` 引用响应中的字段
5. **系统变量** — `{$sys.datetime}` 引用系统内置变量

### 过滤器

使用 `|` 管道符应用过滤器：

```
{time|date:'Y-m-d'}
{name|default:'匿名'}
{time|date:'Y-m-d'|default:'-'}
```

| 过滤器 | 用法 | 说明 |
|--------|------|------|
| `default` | `{name\|default:'匿名'}` | 变量为空时使用默认值 |
| `date` | `{time\|date:'Y-m-d H:i'}` | 时间戳格式化 |

自定义过滤器在配置文件中注册：

```php
'custom_filters' => [
    'mask' => function($val) { return substr((string)$val, 0, 3) . '***'; },
],
```

---

## 类级注解（默认配置）

```php
#[OperationLog(channel: 'admin', tags: ['user'])]
class UserController
{
    #[OperationLog(template: '删除了用户{user.name}')]
    public function delete() {}

    #[OperationLog(template: '创建了用户{user.name}', recordParams: true)]
    public function create() {}
}
```

合并规则：
- `template`：方法为空时继承类默认
- `channel`：方法值为 'default' 时继承类默认
- `conditions`：方法为空时继承类默认
- `recordParams`：方法为 false 时继承类默认
- `tags`：合并去重（类 + 方法）

---

## 扩展字段（extra）

### 方式一：配置解析器

```php
'extra_resolver' => function() {
    return [
        'department' => session('department'),
        'request_id' => request()->header('x-request-id'),
    ];
},
```

### 方式二：监听保存前事件

```php
use wise\operationlog\event\OperationLogSavingEvent;
use think\facade\Event;

Event::listen(OperationLogSavingEvent::class, function(OperationLogSavingEvent $event) {
    $event->logData['extra'] = json_encode([
        'department' => session('department'),
        'custom'     => '任意数据',
    ]);
});
```

---

## 日志管理（Facade）

```php
use wise\operationlog\facade\OperationLog;

// 查询日志
$result = OperationLog::query(['channel' => 'admin'], 1, 20);

// 清理30天前的日志
$deleted = OperationLog::cleanup(30);

// 保留策略：30天 + 最多10万条
$deleted = OperationLog::retain(30, 100000);

// 统计信息
$stats = OperationLog::stats();
```

---

## TTL 自动清理

在配置文件中启用：

```php
'ttl' => [
    'days'         => 90,    // 保留90天
    'max_records'  => 0,     // 不限制条数
    'auto_cleanup' => true,  // 服务启动时概率触发自动清理
],
```

也可通过命令手动清理：

```bash
# 清理30天前的日志
php think wise:operationlog:clean 30

# 保留30天 + 最多10万条
php think wise:operationlog:clean 30 --max=100000
```

---

## 排除规则

在配置文件中设置排除规则，跳过不需要记录日志的请求：

```php
'exclude' => [
    'channels'    => ['debug', 'test'],
    'controllers' => ['app\controller\Debug*'],  // 支持 fnmatch 通配符
    'routes'      => ['^/api/health'],           // 正则匹配URL
    'ips'         => ['127.0.0.1'],
],
```

---

## 条件日志

```php
#[OperationLog(
    template: '删除了文章',
    conditions: ['code' => 0]  // 仅当响应 code 为 0 时记录
)]
```

支持点分路径：`['data.status' => 'success']`

---

## 操作者信息

```php
'operator' => [
    'id_resolver'   => function() { return session('admin_id'); },
    'name_resolver' => function() { return session('admin_name'); },
],
```

---

## 队列支持

```php
'queue' => [
    'enable'     => true,
    'queue_name' => 'operation_log',
],
```

需安装 `topthink/think-queue`。若队列不可用，自动降级为同步写入。

---

## 自定义存储

```php
use wise\operationlog\storage\StorageInterface;

class RedisStorage implements StorageInterface
{
    public function save(array $log): void { /* ... */ }
}
```

```php
'storage' => [
    'driver' => \app\storage\RedisStorage::class,
],
```

---

## Swoole 协程支持

`LogContext` 自动检测运行环境：
- **Swoole 协程**：使用 `Coroutine::getContext()` 隔离存储，避免协程间数据污染
- **PHP-FPM**：使用静态数组存储

无需额外配置，开箱即用。

---

## 配置项

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `storage.driver` | 存储驱动类名 | `DatabaseStorage::class` |
| `storage.table` | 数据库表名 | `operation_log` |
| `storage.connection` | 数据库连接名 | `''` |
| `operator.id_resolver` | 操作者ID获取闭包 | `session('admin_id')` |
| `operator.name_resolver` | 操作者名称获取闭包 | `session('admin_name')` |
| `extra_resolver` | 扩展字段解析闭包 | `null` |
| `system_vars` | 系统内置变量 | `datetime` |
| `custom_filters` | 自定义过滤器 | `[]` |
| `cache.enable` | 是否启用反射缓存 | `true` |
| `cache.key_prefix` | 缓存前缀 | `oplog_attr:` |
| `queue.enable` | 是否启用队列 | `false` |
| `queue.queue_name` | 队列名称 | `operation_log` |
| `middleware.enable` | 是否启用中间件 | `true` |
| `ttl.days` | 保留天数 | `0` |
| `ttl.max_records` | 最多保留条数 | `0` |
| `ttl.auto_cleanup` | 自动清理开关 | `false` |
| `exclude.channels` | 排除频道 | `[]` |
| `exclude.controllers` | 排除控制器（支持通配符） | `[]` |
| `exclude.routes` | 排除路由（正则） | `[]` |
| `exclude.ips` | 排除IP | `[]` |

---

## 目录结构

```
wise-operation-log/
├── composer.json
├── config/
│   └── wise-operation-log.php       # 配置文件
├── database/
│   └── migrations/                  # 数据库迁移
└── src/
    ├── Service.php                  # 服务提供者
    ├── attribute/
    │   └── OperationLog.php         # #[OperationLog] 注解类
    ├── context/
    │   └── LogContext.php           # 日志上下文管理器
    ├── middleware/
    │   └── OperationLog.php         # 中间件
    ├── event/
    │   └── OperationLogSavingEvent.php  # 保存前事件
    ├── facade/
    │   └── OperationLog.php         # Facade
    ├── storage/
    │   ├── StorageInterface.php     # 存储接口
    │   └── DatabaseStorage.php      # 数据库存储
    └── trait/
        ├── ServiceTrait.php
        └── CommandTrait.php
```

---

## License

MIT
