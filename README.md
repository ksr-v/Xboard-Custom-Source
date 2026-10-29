# Xboard

当前独立版本：**2026.10.29.2**。默认内置 NodeEndpoint **1.1.0**、SmartExpiry **1.2.0**、QRCode Extend **1.2.0（审计副本）**、FlowScope **2.4.0** 和 SubInfoCustomizer **1.0.1**。全新安装自动安装并启用五项；原生 PHP-FPM 的 Root 初始化随后配置独立 HTTPS 入口、导入公共 CA 并安装受限本机助手。已有面板升级保留各插件配置、公共 CA 和管理员禁用选择；只有显式选择全部启用才改变禁用状态。以 Root 执行归档升级且不加 `--skip-endpoint` 时，也会为已有有效原生入口安装或更新助手，授权范围与新初始化相同。

本版为“节点通讯设置”增加当前后台管理员登录复用，以及 Root 操作预览、完整文字确认、分阶段进度、任务恢复和公共 CA 同步。Root 归档升级默认包含助手处理；仅插件上传、手动源码升级或显式跳过入口时，才需另行初始化尚未安装的助手。之后可在网页执行已批准的固定任务。根 CA 轮换、私钥导出继续通过 SSH 处理。其余四插件版本与配套 Node `v1.13-orphan.7` 保持不变；“订阅信息管理”的模板及管理员选择继续保留。

继续保留在线设备过期统计与跨节点 IP 限额修复，用户列表前台约每 5 秒刷新；创建用户默认选择 API 返回的首个实际计划，并继承该计划的设备限制。保留此前 Hysteria2 自动 UDP 转发、`node` 管理菜单、限速和路由修复。已有 aaPanel / PHP-FPM 面板按[本次更新教程](docs/installation/upgrade-2026.10.29.2.zh-CN.md)操作；尚未升级设备修复时先更新后端，再逐台升级独立 xboard-node `v1.13-orphan.7`。已使用该 Node 版本的机器无需仅为本次插件捆绑重复升级。无需卸载插件或重建 CA，不对已有机器重新执行新装/`REPLACE`。升级保留机器身份、Token、配置与公共 CA。

在线“设备”按每账号来源 IP 去重，同一 NAT 出口后的多台物理设备不能区分。严格跨节点限额需要所有相关 Node 升级；页面刷新周期不等于所有异常断网的清零时间。完整变化与安装资产见 [v2026.10.29.2 发行说明](docs/releases/v2026.10.29.2.md)。SubInfoCustomizer 继续作为默认五插件之一，独立仓库继续维护源码。

Hysteria2 节点选择版本 `2`，连接端口可填 `42450-42453`、服务监听端口填 `42443`、跳跃间隔填 `5` 秒；Node 收到配置后自动建立、更新或撤销自身 UDP 转发，无需手写 NAT。节点需要 Linux nftables 和 `CAP_NET_ADMIN`；云安全组仍须允许广告 UDP 范围，宿主 INPUT 允许转发后的监听口。Docker 的权限、宿主端口发布与网络命名空间需另行核对。更改范围可能中断已有代理连接。

标准 systemd 节点首次升级后，SSH 输入小写 `node`：选择 `1` 在线更新最新正式版、`2` 重启、`3` 卸载、`4` 修改对接面板地址／Token、`5` 查看最近100行日志、`6` 查看状态。Token 隐藏输入，先验证新地址与认证再应用；保留原 machine/node ID、实例和 CA。未来直接在此选择 `1`，由固定自维护仓库的正式 Release 取得并校验安装器和二进制。已有 Node.js 的 `node` 命令时不会覆盖；详见[节点管理和自动跳跃说明](docs/operations/node-management-hy2.zh-CN.md)。

宝塔 / aaPanel 原生全新安装按 [v2026.10.29.2 安装教程](docs/installation/install-2026.10.29.2.zh-CN.md) 操作；已有面板使用[本版归档升级说明](docs/installation/upgrade-2026.10.29.2.zh-CN.md)。按教程取得并校验本 tag 的 `xboard.tar.gz` 和固定 Composer PHAR，完成新网站配置后，在站点目录以 Root 执行；用户名和 PHP 路径以本机为准，命令不传 `--host`：

```bash
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

首次配置时选择 `1` 自动检测本机公网 IPv4（默认，回车选择），或 `2` 手工输入 IP / 域名。自动模式显示候选，回车接受或输入其他实际地址；检测失败必须手工填写。手工模式不请求公网检测服务。已有真实 Root 入口或显式 `--host` 跳过该菜单，不自动覆盖原地址。安装器先准备文件权限，再降权执行 Composer、数据库迁移和五插件安装启用，随后处理独立 HTTPS 入口、公共 CA、本机助手和防火墙。已有有效入口仍会安装助手，保留原 CA。显式 `--skip-endpoint` 会将独立入口、公共 CA 和助手一起延期，五插件仍安装启用。Root 工具和私钥位于应用目录之外。

旧教程若已将 `203.0.113.10` 示例 IP 安装为实际入口，按[地址修复说明](docs/installation/bundled-native.zh-CN.md#已有入口误用了文档示例-ip)使用 `--repair-address`；沿用原 CA，为正确地址重签服务器证书并保存设置，不重装面板或重建 CA。

助手可用后，通过面板 HTTPS 地址打开同源 `/node-endpoint`，可复用当前后台管理员登录。保存设置后生成 Root 操作预览，核对目标并输入完整确认文字，才会执行入口配置、刷新、服务器证书续签或防火墙任务。任务按阶段显示，可恢复本人保留的最近任务；公共 CA 与状态需显式校验同步。助手尚未安装不影响插件启用，页面提供首次 SSH 初始化命令及手动导入回退。

Root 安装入口固定为 `root-installer-v1.0.10`，继续使用不可变 Root tools `1.0.6`，另安装受限助手 `1.0.0`。默认 Root 归档升级已包含助手安装或更新，无需再单独执行下方命令。只有仅上传插件、手动更新源码或之前显式 `--skip-endpoint`、尚未安装助手的旧站，才在已有有效原生 HTTPS 入口的前提下，以 Root SSH 单独初始化，保留原入口与 CA：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.10/install.sh | bash -s -- --enable-web-manager
```

助手按网站系统 UID 授权，使用 Root 私有策略绑定应用、工具摘要和允许端口。每站应使用与实际 PHP-FPM 进程一致的专用 UID；多站共用 `www` 时共享同一授权边界，仅改变 CLI 的 `--app-user` 不会改变 FPM 身份。网页没有任意 Shell、任意文件路径或未批准端口接口，根 CA 轮换与私钥导出保留在 SSH。

本版保留 FlowScope 的 IPv4 上报路径。旧入口若尚未包含该路由，在助手中预览并确认“刷新入口配置与证书状态”，或通过同一固定 Root 安装器执行 `--refresh-entry`。确认 FlowScope `2.4.0` 启用、节点使用独立 Node `v1.13-orphan.7` 且能访问 IPv4 检测服务；旧 404 最多缓存 10 分钟后自动重报。IPv4 上报不要求 Python 采集器或开启周期流量。历史标准 Docker 宿主 Root 自动流程见 [Docker 安装与接入教程](docs/installation/aapanel-docker.zh-CN.md)。

已有面板不要执行全新安装或生成 APP_KEY；使用已校验发行包的 Root 升级工具。五插件无需逐个上传。FlowScope 采集器仍在对应节点机器部署，云安全组需另行放行。本轮隔离原生新站初始化、真实 Root 操作 45/45、基础探测 4/4，以及候选 3 归档原样升级和保全 33/33 均通过；数据库备份实际恢复后 dump 一致。Linux PHP 8.3 生命周期 25/25、升级恢复 5/5 和安装器 114/114 均无跳过；另有 Python 239 项（含助手 33 项及 bootstrap 4 项）、UI 27 组和 PHP Web 桥接 27 组通过。正式发行归档的精确摘要及对应验收以附件 JSON 为准，详见本 tag 的验证记录。

本次修补明确要求 Composer **2.2+**，安装器会在安装依赖前检查版本，并修正首次创建 Composer 缓存时的父目录权限。旧 Composer 的 runtime API 不满足锁定依赖时应指定可信新版 PHAR，保留锁定依赖版本，不执行 `composer update`。

本版开发联测与发行包验证边界见 [v2026.10.29.2 验证范围](docs/installation/release-2026.10.29.2-validation.zh-CN.md)。

历史原生部署验证见 [原生测试记录](docs/installation/bundled-validation.zh-CN.md)；此前 Docker 默认 MySQL 安装、重复执行、私有 TLS/WSS、并发设置和权限验证见 [Docker 自动部署记录](docs/installation/aapanel-docker-auto-validation.zh-CN.md)。

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 11, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (Vue3 + TypeScript)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Installation

Use the [v2026.10.29.2 native PHP-FPM installation guide](docs/installation/install-2026.10.29.2.zh-CN.md). A fresh Root initialization installs and enables all five plugins, configures the HTTPS endpoint and installs the restricted local helper. `--skip-endpoint` defers the endpoint, public CA and helper. Isolated native initialization, 45 real Root checks, 4 basic probes and 33 candidate-archive upgrade/preservation checks passed. Linux PHP 8.3 lifecycle/retry suites passed 25/25 and 5/5 without skips. The matching Release's JSON evidence records the final archive identity and exact validation. The earlier [aaPanel + Docker guide](docs/installation/aapanel-docker.zh-CN.md) ([English](docs/en/installation/aapanel-docker.md)) retains its historical scope.

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

For this release, follow the [v2026.10.29.2 native PHP-FPM update guide](docs/installation/upgrade-2026.10.29.2.zh-CN.md) (Chinese). Preserve the original database, `APP_KEY`, machine bindings, private CA, settings and disabled-plugin choices. A Root archive upgrade without `--skip-endpoint` installs or updates the helper for a valid native endpoint, with the same authorization as fresh initialization. A separate `--enable-web-manager` step is needed only after plugin-only/manual source updates or an explicit skip when the helper remains absent. Its trust boundary is the site's system UID, which must match PHP-FPM; use a dedicated UID for site isolation. Independent Node remains `v1.13-orphan.7`; machines already on that version do not need another upgrade for this release.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [宝塔 / aaPanel + Docker](./docs/installation/aapanel-docker.zh-CN.md) / [English](./docs/en/installation/aapanel-docker.md)（新装一次确认；标准桥接布局支持宿主 Root 私有入口，见支持范围）

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 11 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: Vue3 + TypeScript + NaiveUI
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## ❤️ Support The Project

If this project has helped you, donations are appreciated. They help support ongoing maintenance and would make me very happy.

TRC20: `TLypStEWsVrj6Wz9mCxbXffqgt5yz3Y4XB`

## 🌟 Maintenance Notice

This project is currently under light maintenance. We will:
- Fix critical bugs and security issues
- Review and merge important pull requests
- Provide necessary updates for compatibility

However, new feature development may be limited.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process

## 🤝 Contributing

Issues and Pull Requests are welcome to help improve the project.

## 📈 Star History

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
