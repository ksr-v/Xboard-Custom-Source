# Docker 一键部署与 Root 自动入口验证

本版 v2026.10.23.1 使用 NodeEndpoint 1.0.7、SmartExpiry 1.2.0、二维码审计副本 1.2.0 和 FlowScope 2.4.0，Root 工具为 v1.0.9 / tools 1.0.6。

专用 aaPanel 虚拟机完成默认 **MySQL / MariaDB + 内置 Redis** 从零安装。数据库使用宝塔 MariaDB 10.11 二进制启动的独立实例，空库与账号均为测试专用；没有读取现有宝塔数据库 Root 密码。五项数据库输入及管理员信息后，只需一次 YES，自动完成权限、镜像、四插件、私有 HTTPS、口令托管和公共 CA 同步。

- 普通 PHP 身份固定为容器 1000:1000，Root 私钥不可读；宿主 Root 资产及脚本不进入应用镜像。
- 真实 TLS 节点握手和合成 IPv4 上报均为 200；认证 WSS 101、Accept 和 auth.success 首帧通过。
- 同源码重跑不构建、停服、迁移、初始化或重启，证书、APP_KEY、凭据密文和设置保持。
- 实际 MySQL 并发保存屏障验证通过；管理员的新设置不会被 Root helper 覆盖。
- 直接 CLI 卸载后再次校验工具缓存通过，没有字节码污染。
- 既有标准 SQLite Docker 项目还完成容器删除重建、旧 CID 拒绝、原 CA 私钥证明后换绑及跨项目拒绝。

Root Python 202 项（Windows 200 通过、2 项跳过）；同组 Docker 安全检查在 Linux 47 项全部通过。后端 Python 104 项（100 通过、4 项 Linux 专用检查在 Windows 跳过），包含 Docker 入口 6 项与简化安装器 40 项。PHP 原生插件 36 项、命令 API 9 项、后端集成 13 项、归档重试 5 项和 Chrome 复制按钮 9 项通过。

最终 MySQL 详细记录见 [fresh-auto.json](../verification/aapanel-docker-fresh-auto.json)。换绑功能候选记录见 [auto-root.json](../verification/aapanel-docker-auto-root.json)，最终版本保留其换绑实现并补充禁写字节码；最终从零安装及维护后复用已使用固定最终资产重新验证。

原有 8 份 Nginx 配置和关键进程保持；测试站点、入口、容器、独立数据库实例及临时代理已清理，防火墙恢复，私有恢复材料仅留在 VM Root 目录。实测跳过自动公共端口放行；未覆盖公网 Go 地址探测、代理业务流量、ARM64 或生产升级。已有基础及手工测试记录保留为[历史记录](aapanel-docker-validation.zh-CN.md)，不与本次结果混算。
