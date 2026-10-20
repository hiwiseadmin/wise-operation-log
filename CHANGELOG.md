# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Fixed
- `AuditLog::getFieldLimits()` 与 `audit_log` 迁移列宽不一致（action/path/operator_name/tenant_id/target_type），MySQL 严格模式下 `insertAll` 报 Data too long，整批审计记录丢失
- `AuditLog::flush()` 先清空缓冲区后写入，失败即全量丢弃；改为降级逐条写入并回写失败记录
- `AnomalyDetector` 三处查询违反 `ONLY_FULL_GROUP_BY`（MySQL 5.7+ 默认开启），1055 错误被 try/catch 吞掉导致频率/级别异常检测永远返回空
- `detectOffHourActivity()` 跨天条件写错（`OR create_time <= 次日06:00` 覆盖全天），白天记录被误判为夜间操作
- `LogArchiver::getFilePath()` 路径前缀校验缺尾分隔符（`/runtime_evil` 可通过），且 realpath 失败时未解析 `..`
- `sensitive.auto_detect`、`sensitive.scope.response` 配置为 dead flag；模板变量未脱敏，`{request_params.password}` / `{response.data.token}` 会明文写入 content
- 归档 CSV 未做公式注入防护
- 审计 `tenant_id` 直接采信客户端 `x-tenant-id` 请求头，可被伪造污染审计归属
- 未捕获异常的请求不产生任何审计记录（`$next()` 位于 try 之外）
- `OperationLogSavingEvent` 监听器异常会冒泡并击穿业务请求
- Swoole 协程下 `OperationLogListener` 缓冲区未做协程隔离；常驻进程 `register_shutdown_function` 兜底不触发，改为协程 `defer` 写入
- `LogManager::query()` 的 tags 条件未转义 LIKE 通配符
- 归档目标表写入未遵循 `storage.connection` 配置
- `detectNewIpBatch()` 未排除本窗口内的记录，窗口内新产生的日志会被当作"历史"，导致新 IP 永远检测不出来
- `AuditLog::resolveTenantId()` 未使用当前处理的请求对象，且以 `PHP_SAPI` 作为判断依据并不严谨
- `OperationLog::resolveControllerClass()` 对**包命名空间控制器**（如 `wise\market\controller\admin\Plugin@index`）解析失败：框架 Callback 调度把命名空间前缀塞进 `Request::layer()`，而旧实现仅 `parseClass('controller', $request->controller())`，丢掉前缀后 `class_exists()` 恒为 false → 注解写了也不生效、操作日志静默丢失。改为优先按 `{layer}\controller\{controller}` 拼回 FQCN，失败再回退 `parseClass()`
- `AuditLog::resolveTargetType()` 存在同款问题：包命名空间控制器无法解析 → `target_type` 恒为 null（审计归属丢失）。改为复用同一「layer 拼回 + parseClass 回退」口径

### Added
- 审计日志清理：`php think wise:operationlog:clean --audit [--audit-days=N]`，默认取 `audit.retention_days`
- 归档 gzip 压缩（`archive.compress`）与分块大小配置（`archive.target.chunk_size`）正式生效
- 归档手动触发开关 `archive.trigger.command` 正式生效
- 新 IP 异常检测：`AnomalyDetector::detectNewIpBatch()` 与 `wise:operationlog:anomaly --type=newip`

### Changed
- 默认脱敏策略增强：`sensitive.auto_detect` 与 `sensitive.scope.response` 默认值改为 `true`
- 新增 `audit.trust_request_tenant_header`（默认 `false`）：控制是否采信客户端 `x-tenant-id` 作为审计租户标识

## [1.0.0] - 2024-01-01

### Added
- Initial release

[1.0.0]: https://github.com/wiseadmin/wise-operation-log/releases/tag/v1.0.0
