# v2026.11.01.1 验证记录

本次发行以正式 `v2026.10.31.2` 提交 `95dba9d5d9305a6cb5a99cf3417dbc50948b80eb` 为父，新增流量队列固定分配、OPcache 教程及本轮版本说明。插件源码、版本、来源锁和 Root 工具沿用该正式基线；`bundle_version` 标识本次面板发行。

已完成的本地验证：

- `config/horizon.php` 和 `tests/Queue/fixed-data-pipeline.php` 的 PHP 语法检查通过。
- 真实 Horizon `v5.33.1` 的 3 项离线行为回归通过，依赖版本和提交与 `composer.lock` 一致：默认三个队列各保留一个 worker；显式预算为 6 时各保留两个；旧 auto 策略的正对照确实创建并替换 worker。
- 上述行为测试只替换队列、指标和操作系统进程边界，不启动应用、Redis 或子进程。测试使用本地 PHP 8.2，不代表 PHP 8.3 的完整安装验收或生产吞吐测试。
- Docker 入口的 Shell 语法检查和既有 6 项入口回归通过；未构建或发布 Docker 镜像。
- 本轮文档链接、显式锚点、代码围栏和 `git diff --check` 已检查。

原生构建器从干净提交生成归档，检查插件文件来源锁及 Root 工具固定摘要，排除环境凭据、vendor 和运行数据。本次构建提交、归档摘要、文件图及附件核验结果记录在发行附件 `build.json`、`latest.json`、`plugin-source-lock.json` 和 `RELEASE-VERIFICATION.json` 中；以附件记录为准，不沿用历史发行的通过数。

本次没有在生产服务器执行完整归档升级。完整升级需按[本版升级教程](upgrade-2026.11.01.1.zh-CN.md)保留备份并安排维护；OPcache 仍需在实际 PHP-FPM 上安装扩展、验证加载并按服务范围安排重载，面板源码更新不会安装 PHP 扩展。
