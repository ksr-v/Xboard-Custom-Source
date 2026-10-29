# Xboard

当前独立版本：**2026.10.29.1**。默认内置 NodeEndpoint **1.0.10**、SmartExpiry **1.2.0**、QRCode Extend **1.2.0（审计副本）**、FlowScope **2.4.0** 和 SubInfoCustomizer **1.0.1**；全新安装时自动安装并启用。已有面板升级保留各插件配置、公共 CA 和管理员已有的禁用选择，可显式选择全部启用。

本版默认加入“订阅信息管理”，可编辑 0–4 行订阅顶部信息、九个动态变量及日期格式。已独立安装官方 SubInfoCustomizer 1.0.0 的站点自动升级并保留模板和禁用选择；此前未安装的站点会安装并启用。插件未知核心/后台构建仍拒绝启用。其余四插件与配套 Node 版本保持不变。

继续保留在线设备过期统计与跨节点 IP 限额修复，用户列表前台约每 5 秒刷新；创建用户默认选择 API 返回的首个实际计划，并继承该计划的设备限制。保留此前 Hysteria2 自动 UDP 转发、`node` 管理菜单、限速和路由修复。已有 aaPanel / PHP-FPM 面板按[本次更新教程](docs/installation/upgrade-2026.10.29.1.zh-CN.md)操作；尚未升级设备修复时先更新后端，再逐台升级独立 xboard-node `v1.13-orphan.7`。已使用该 Node 版本的机器无需仅为本次插件捆绑重复升级。无需卸载插件或重建 CA，不对已有机器重新执行新装/`REPLACE`。升级保留机器身份、Token、配置与公共 CA。

在线“设备”按每账号来源 IP 去重，同一 NAT 出口后的多台物理设备不能区分。严格跨节点限额需要所有相关 Node 升级；页面刷新周期不等于所有异常断网的清零时间。完整变化与安装资产见 [v2026.10.29.1 发行说明](docs/releases/v2026.10.29.1.md)。SubInfoCustomizer 从本版起加入默认五插件，独立仓库继续维护源码。

Hysteria2 节点选择版本 `2`，连接端口可填 `42450-42453`、服务监听端口填 `42443`、跳跃间隔填 `5` 秒；Node 收到配置后自动建立、更新或撤销自身 UDP 转发，无需手写 NAT。节点需要 Linux nftables 和 `CAP_NET_ADMIN`；云安全组仍须允许广告 UDP 范围，宿主 INPUT 允许转发后的监听口。Docker 的权限、宿主端口发布与网络命名空间需另行核对。更改范围可能中断已有代理连接。

标准 systemd 节点首次升级后，SSH 输入小写 `node`：选择 `1` 在线更新最新正式版、`2` 重启、`3` 卸载、`4` 修改对接面板地址／Token、`5` 查看最近100行日志、`6` 查看状态。Token 隐藏输入，先验证新地址与认证再应用；保留原 machine/node ID、实例和 CA。未来直接在此选择 `1`，由固定自维护仓库的正式 Release 取得并校验安装器和二进制。已有 Node.js 的 `node` 命令时不会覆盖；详见[节点管理和自动跳跃说明](docs/operations/node-management-hy2.zh-CN.md)。

宝塔 / aaPanel 原生全新安装按 [v2026.10.29.1 安装教程](docs/installation/install-2026.10.29.1.zh-CN.md) 操作；已有面板使用[本版归档升级说明](docs/installation/upgrade-2026.10.29.1.zh-CN.md)。按教程取得并校验本 tag 的 `xboard.tar.gz` 和固定 Composer PHAR，完成新网站配置后，在站点目录以 Root 执行；用户名和 PHP 路径以本机为准，命令不传 `--host`：

```bash
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

首次配置时选择 `1` 自动检测本机公网 IPv4（默认，回车选择），或 `2` 手工输入 IP / 域名。自动模式显示候选，回车接受或输入其他实际地址；检测失败必须手工填写。手工模式不请求公网检测服务。已有真实 Root 入口或显式 `--host` 跳过该菜单，不自动覆盖原地址。安装器先准备文件权限，再降权执行 Composer、数据库迁移和插件生命周期，最后处理独立 HTTPS 入口、本机防火墙及公共 CA 导入。Root 工具和私钥位于应用目录之外。

旧教程若已将 `203.0.113.10` 示例 IP 安装为实际入口，按[地址修复说明](docs/installation/bundled-native.zh-CN.md#已有入口误用了文档示例-ip)使用 `--repair-address`；沿用原 CA，为正确地址重签服务器证书并保存设置，不重装面板或重建 CA。

日后在“节点通讯设置”更改 IP 并保存后，在“SSH 配置入口”点击“一键复制 Root 工具命令”，以 Root 通过 SSH 登录这台面板服务器执行。命令使用已保存地址；向导中核对端口和 WS 模式，完成入口修改后回到插件页“验证入口”。网页保存和复制操作不会直接执行 Root 命令。

Root 入口继续使用 root-installer-v1.0.9 / tools 1.0.6，保留私有 HTTPS 入口的 FlowScope IPv4 上报路径。全新安装的 Root 计划会包含经过审计的精确 POST 路径。尚未包含该路径的已有入口，仅升级面板或插件不会自动改写原 Nginx，可在面板服务器以 Root 执行一次：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --refresh-entry
```

原生 HTTPS FPM / Octane 刷新流程保留原 CA、服务器证书、Token 和数据库，不写插件设置或防火墙。历史标准 Docker 宿主 Root 自动流程见 [Docker 安装与接入教程](docs/installation/aapanel-docker.zh-CN.md)。确认 FlowScope 2.4.0 启用、节点使用独立 Node v1.13-orphan.7 且能访问 IPv4 检测服务；旧 404 最多缓存 10 分钟后自动重报。IPv4 上报不要求 Python 采集器或开启周期流量。

已有面板不要执行全新安装或生成 APP_KEY；使用已校验发行包的 Root 升级工具。五插件无需逐个上传。FlowScope 采集器仍在对应节点机器部署，云安全组需另行放行。原生 FPM 与 Docker 各有历史验证记录；本次五插件的原生 PHP-FPM 和 PHP 8.2 CLI 验证结果及边界见随包 `VALIDATION.md`；Docker 整套镜像/升级未重复验收。

本次修补明确要求 Composer **2.2+**，安装器会在安装依赖前检查版本，并修正首次创建 Composer 缓存时的父目录权限。旧 Composer 的 runtime API 不满足锁定依赖时应指定可信新版 PHAR，保留锁定依赖版本，不执行 `composer update`。

本版开发联测与发行包验证边界见 [v2026.10.29.1 验证范围](docs/installation/release-2026.10.29.1-validation.zh-CN.md)。

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

Use the [v2026.10.29.1 native PHP-FPM installation guide](docs/installation/install-2026.10.29.1.zh-CN.md). The earlier [aaPanel + Docker guide](docs/installation/aapanel-docker.zh-CN.md) ([English](docs/en/installation/aapanel-docker.md)) retains its historical deployment scope; this release did not repeat Docker installation or upgrade validation. Actual tested scope is recorded with the matching Release.

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

For this release, follow the [v2026.10.29.1 native PHP-FPM update guide](docs/installation/upgrade-2026.10.29.1.zh-CN.md) (Chinese). Upgrade the backend first, then independent Node `v1.13-orphan.7`, keeping the original database, `APP_KEY`, machine bindings and private-CA trust. The current device-state validation used native PHP-FPM; earlier Docker instructions retain their historical validation scope.

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
