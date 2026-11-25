# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.3] - 2026-11-25

### Added
- 新增审计日志清理命令、归档压缩/分块与手动触发开关、新 IP 异常检测。

### Changed
- 默认开启响应脱敏与敏感字段自动识别；新增 `audit.trust_request_tenant_header`（默认 false）。

### Fixed
- 修复字段长度不一致导致批量写入失败、缓冲区写入失败丢日志、ONLY_FULL_GROUP_BY 查询报错等问题。
- 修复夜间操作误判、归档路径校验、CSV 公式注入、LIKE 通配符未转义等安全问题。
- 租户归属不再采信客户端请求头；未捕获异常与监听器异常不再导致审计缺失或业务中断。
- Swoole 协程下缓冲区隔离并改用 `defer` 写入；包命名空间控制器现可正确记录操作日志与目标类型。

## [1.0.1] - 2024-01-01

- Initial release

[1.0.1]: https://github.com/hiwiseadmin/wise-operation-log/releases/tag/v1.0.1

[Unreleased]: https://github.com/hiwiseadmin/wise-operation-log/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/hiwiseadmin/wise-operation-log/releases/tag/v1.0.3
