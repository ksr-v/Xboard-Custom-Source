# v2026.10.25.2 发行验证范围

本版基于既有后端增加 Hysteria2 配置校验与范围下发，配套独立 Node `v1.13-orphan.6` 自动管理 UDP 转发和 `node` 管理菜单。插件包固定 NodeEndpoint `1.0.9`、SmartExpiry `1.2.0`、QRCode Extend **审计副本 `1.2.0`**、FlowScope `2.4.0`。NodeEndpoint 本次更新节点安装命令版本，不重新生成 Root CA 或替换 Root 入口。

## 开发验证

- Hysteria2 自动转发：47 项专用 VM 流量、配置更新、生命周期、冲突与权限检查通过；服务端 sing-box／Xray，真实面板／NodeWorker，持续同一 TCP／UDP 会话数据校验。后端 12 项对应检查及原 13 项限速／路由回归通过。
- 节点菜单：13 项真实 PTY／systemd 联测、7 项管理脚本检查和 Linux 竞态检查通过。在线更新使用受控发行样本，不把它表述成正式公网更新已通过。
- 内置插件集成：本版 14 项通过，包含四插件真实 Composer／SQLite 安装与启用、v1.0.0 启停选择及配置／CA 保留、真实 v1.0.8 的完整源码升级、未知修改拒绝与共享前端补丁共存。
- 源码构包：`scripts/build-private-release.py` 仅从已提交、干净 HEAD 构建，检查内置插件逐文件摘要与 Root 资产固定摘要；不包含 `.env`、运行插件目录、storage、vendor、缓存或私钥。最终 commit、归档大小和 SHA-256 由同目录 `latest.json` 与 `SHA256SUMS` 给出。

上述行为证据保留在独立插件仓库的[自动转发报告](https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/blob/main/docs/HYSTERIA2_AUTO_FORWARDING_REPORT.md)及[菜单报告](https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/blob/main/docs/NODE_MANAGEMENT_TEST_REPORT.md)。历史安装、Docker与限速／路由报告保留其原版本和实际边界，不重写为本版测试。

## 发行与部署边界

当前新装教程和[升级教程](upgrade-2026.10.25.2.zh-CN.md)固定本版后端、Node 与插件版本。升级保留数据库、APP_KEY、身份和 CA，无需卸载插件；已有面板不重新执行新装初始化。

本版新完整发行包的原生安装／归档升级、Docker 重建／跨版本升级、正式公网 Node 更新、ARM64实际运行、IPv6、商业客户端、长期压力和生产迁移未在上述开发联测中重复验证。新 Node Docker 镜像也未构建／验证。应先在备份副本及一台测试节点演练，再逐台升级；不把归档校验表述为完整部署或灾难恢复验收。
