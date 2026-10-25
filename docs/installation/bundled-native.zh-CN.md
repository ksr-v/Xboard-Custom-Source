# 独立 Xboard：内置插件与 Root 安装

本版基于用户维护的 `ksr-v/Xboard-Custom-Source`，不拉取原版 Xboard、原版子模块或原版 Node 安装源。插件源码和公共 Root 工具资产随发行包提供；PHP 第三方依赖仍按原 `composer.lock` 安装。

配套 Node `v1.13-orphan.6` 支持 Hysteria2 自动 UDP 范围转发及 `node` 管理菜单；先更新后端再更新节点，保留原身份、Token 与 CA，无需卸载插件。新机器安装仍按本文流程，已有面板按[本次更新教程](upgrade-2026.10.25.2.zh-CN.md)。能力与防火墙边界见[节点操作说明](../operations/node-management-hy2.zh-CN.md)。

希望按原版宝塔教程的操作顺序部署时，请使用 [宝塔 / aaPanel 安装教程](aapanel.zh-CN.md)。

| 内置功能插件 | 固定版本 | 源码位置 |
| --- | --- | --- |
| NodeEndpoint | 1.0.9 | `bundled-plugins/NodeEndpoint` |
| SmartExpiry | 1.2.0 | `bundled-plugins/SmartExpiry` |
| QRCode Extend | 1.2.0，审计副本 | `bundled-plugins/Qrcodeextend` |
| FlowScope | 2.4.0，含五个采集器安装资产 | `bundled-plugins/Flowscope` |

来源 commit 和每个文件摘要记录在 `bundled-plugins/manifest.json`。安装器校验后部署到正常的 `plugins/` 目录，通过原生插件生命周期执行迁移、安装和启用。后续仍可使用原生插件管理，不以 core 同名目录遮蔽用户上传版本。

本版为 v2026.10.25.2，使用 root-installer-v1.0.9 / tools 1.0.6。全新 Root 入口计划包含经过审计的 FlowScope 出口 IPv4 精确 POST 上报路径，仍不开放管理接口或整个插件路径前缀。

## 从零安装：宝塔 / aaPanel 原生 PHP-FPM

先准备 Linux、Python 3.10+、PHP 8.3、可信 Composer 2.2 及以上的 2.x 版本、Nginx/OpenResty、数据库、Redis、OpenSSL、`ss` 和 `runuser`。按锁定依赖启用所需 PHP 扩展，CLI 与 FPM 版本应一致。安装器不升级系统或 Composer，不创建数据库账号，也不代装宝塔。

锁定依赖要求 `composer-runtime-api ^2.2`，不能使用 Composer 2.0 / 2.1。推荐以 Root 准备[Composer 官网](https://getcomposer.org/download/)的固定 **2.10.3** PHAR：

```bash
install -d -o root -g root -m 755 /opt/xboard-tools
curl -fsSL --proto '=https' --tlsv1.2 \
  https://getcomposer.org/download/2.10.3/composer.phar \
  -o /opt/xboard-tools/composer-2.10.3.phar
printf '%s\n' '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /opt/xboard-tools/composer-2.10.3.phar' | sha256sum -c -
chown root:root /opt/xboard-tools/composer-2.10.3.phar
chmod 0644 /opt/xboard-tools/composer-2.10.3.phar
```

摘要校验必须显示 `OK` 才继续。下文显式指定此 PHAR，避免选中系统旧版。这里不执行远程安装脚本，也不替换系统 Composer；不要用 `composer update` 或 `--ignore-platform-reqs` 绕过依赖要求。

在宝塔创建专用空网站目录和数据库，网站运行目录设为 `public/`，配置 PHP-FPM / Laravel `try_files` 并启动网站。安装独立入口时需要从当前真实 Nginx 配置发现该站点和后端；不要先猜测 socket 或覆盖原有网站配置。

从已确认的发行附件取得 `xboard.tar.gz` 和 SHA256SUMS，独立核对摘要后，将源码解压到新网站的空目录。例如：

```bash
sha256sum -c SHA256SUMS
mkdir -p /www/wwwroot/panel.example.com
tar -xzf /root/xboard.tar.gz -C /www/wwwroot/panel.example.com
cd /www/wwwroot/panel.example.com

bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

PHP、Composer 和用户名必须替换为本机实际值；Composer 路径应指向可信 PHP 脚本 / PHAR。不要在已有业务目录执行本节命令。

首次配置时选择通讯地址输入方式：

```text
1. 自动检测本机公网 IPv4（默认，回车选择）
2. 手工输入 IP / 域名
```

自动模式显示候选，回车接受或输入其他真实通讯 IP / 域名；检测失败必须手工输入。手工模式不请求公网检测服务。上方命令省略 `--host`，不把示例 IP 写入面板；已有真实 Root 入口或显式 `--host` 跳过该菜单，保留相应地址，不会因检测结果变化而自动修改。`203.0.113.10` 等文档示例地址不能用于实际安装。

PHP CLI 需要启用 `symlink`，用于创建 Laravel 的公共存储链接。v2026.10.18.4 新装流程在 Composer 和数据库初始化之前检查所选 PHP CLI 是否提供该函数；预检停止时先修正对应 CLI 的设置再重试，安装器不会自动修改 `php.ini` 或开放 `exec`。如果旧版后台已能登录，但新装脚本在 `storage:link` 报 undefined `exec`，应先修正 PHP 禁用函数，再按 [宝塔教程中的恢复步骤](aapanel.zh-CN.md#常见问题) 补齐链接和 Root 后续配置；不要重跑全新安装或清空 `.env`。

安装顺序：

1. Root 检查专用目录、站点用户、已有 `APP_KEY` / 安装标记和实际 FPM 站点。
2. Root 准备应用、运行目录、Admin 原子补丁父目录及公共资源的权限；Root 工具缓存和 PKI 保持外置。
3. 以站点用户运行 Composer、数据库安装、四插件源校验、迁移和启用。数据库 / Redis 及管理员信息使用 Xboard 原有交互；保留终端显示的管理员初始信息。
4. Root 创建独立 HTTPS 入口，成功后以站点用户保存 NodeEndpoint 设置，放行本机配置 TCP 端口，降权导入公共 CA。

Root 入口会展示计划并要求输入完整确认短语。核对计划后，照抄本机提示要求的内容，格式如下：

```text
INSTALL <实际通讯地址>:<端口>
```

这是格式说明，不是 Shell 命令，不要原样输入尖括号和占位文字。`INSTALL` 与地址之间有一个空格；必须包含计划中的实际地址和端口，不能只输入 `INSTALL`。新版交互输错会说明原因并再次询问；输入 `CANCEL`、发送 EOF 或按 Ctrl+C 会取消，自动化确认仍要求完整短语精确匹配。

新 CA 的口令自动随机生成，先使用面板 `APP_KEY` 加密保存在独立凭据表，再签发证书；管理员可在 HTTPS `/node-endpoint` 页面的证书区显式查看或复制。请同时备份数据库和原 `.env` / `APP_KEY`。CA 私钥不进入面板数据库，仍位于网站之外的 Root 私有目录或离线介质。

旧 CA 和已有节点信任保持原样，未托管的旧 CA 在维护时仍使用原口令。首次 CA 和服务器证书采用双十年策略，后续证书不超过 CA 到期时间；加密导出、验证和离线保管仍需按 NodeEndpoint 工具说明完成。

本机防火墙支持运行中的 firewalld 和 UFW。未启用 UFW 时只保存规则并报告，不自动启用整个防火墙；冲突 / 未知规则需要人工处理。默认规则允许所有来源访问该 TCP 通讯端口。服务商防火墙和云安全组仍需在控制台放行。

多站点或特殊布局不能自动确认时，安装器停止并说明原因。Docker / Octane 不套用 PHP-FPM 发现逻辑。若明确只使用域名方式或自行维护独立入口，可加 `--skip-endpoint`，四插件仍安装启用，但 IP 入口不会被标记为就绪。`--prepare-only` 只准备权限，不运行 Composer / 数据库或签发证书。

### 保存通讯地址后修改入口

在插件“节点通讯设置”填写新 IP / 域名并保存后，在“SSH 配置入口”点击“一键复制 Root 工具命令”，以 Root 通过 SSH 登录这台面板服务器并执行。复制的固定版本入口带有已保存的 `--host`，不使用示例地址修复专用参数；核对向导中的端口和 WS 模式，再按计划确认修改。完成后回到插件页点击“验证入口”，验证通过后重新复制节点安装命令。

保存设置和复制命令不会在网页执行 Root 操作。已有入口由 Root 向导按修改流程更新监听和对应服务器证书，沿用原 CA 和口令；若是未托管的旧 CA，仍需原口令。仅保存网页地址不能完成证书和入口变更。

全新 SQLite 安装使用 `storage/app/private/database.sqlite`（文件 0600），已有数据库地址和 `.env` 在升级时保持原值。MySQL / PostgreSQL 的账号及库需预先创建。

### 私有证书对接后 IPv4 待获取

旧独立入口遗漏 `POST /api/v2/flowscope/node-address`，会出现机器认证、负载正常但服务器详情 IPv4“待获取”。单独升级面板源码或插件 ZIP 不一定改写已装 Nginx 入口；在面板服务器以 Root 执行一次：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --refresh-entry
```

原生 HTTPS FPM / Octane 从安装记录读取当前地址、端口、站点、PHP 和 WS 模式，完整确认后刷新路径规则，保留原 CA、服务器证书、托管口令、节点 Token 和数据库，不写插件设置或防火墙。标准 Docker 现在有独立的宿主 Root 自动流程，容器换绑会核对原 CA，详见 [Docker 简化安装](aapanel-docker.zh-CN.md)。

确认 FlowScope 2.4.0 启用，节点使用独立 Node v1.13-orphan.6，并能直连 `https://api-ipv4.ip.sb/ip`。此 IPv4 来自 Node 自动报告，不要求 Python 采集器或周期流量。IP 检测成功后，旧 404 缓存最多 10 分钟，等待自动重试再刷新详情；无需重装节点、改 Token 或强制重启。只运行 `--sync-ca` 或 `--configure-firewall` 不刷新路径。改 IP 则使用普通 Root 向导，按新地址更新服务器证书。

### 旧版确认中止后继续

v2026.10.18.1 / v2026.10.18.2 若因只输入 `INSTALL` 而中止，且主程序已经安装，不要重跑 `init.sh`、删除 `.env` 或重建 APP_KEY。Root 确认中止不会回滚应用安装。在原站点目录以 Root 继续：

```bash
cd /www/wwwroot/panel.example.com &&
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

先部署本版代码，替换实际目录、PHP 路径和站点用户；核对安装器显示的通讯地址，并按计划输入包含 IP 和端口的完整确认短语。

### 已有入口误用了文档示例 IP

若旧教程中的 `203.0.113.10` 已被写入通讯设置并创建为独立入口，在同一台独立面板服务器以 Root 执行：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --repair-address
```

先选择自动检测公网 IPv4 或手工输入真实通讯 IP / 域名。自动模式核对候选并回车接受或输入实际地址，手工模式不请求公网检测服务，再确认修改计划。工具沿用原 CA 和口令，重签包含新地址 SAN 的服务器证书，以站点用户保存通讯设置并同步公共 CA；不会通过重建 CA 来修复地址。该入口要求独立后端提供 `xboard:configure-endpoint`。仅在网页改 IP 不能修复服务器证书。若尚未创建 Root 入口，使用上方已有安装的继续流程。完成后刷新插件页面并重新复制节点安装命令，不重跑 `init.sh`。

### 旧版依赖阶段失败后重试

若 **v2026.10.18.1** 只在 Composer 安装依赖阶段失败，尚未初始化应用 / 生成 APP_KEY，先按上文准备固定 Composer PHAR。旧版同时出现缓存目录权限告警时，以 Root 执行：

```bash
install -d -o root -g root -m 755 /var/cache/xboard-composer
```

再重跑原 `init.sh` 命令，显式传入 `--composer /opt/xboard-tools/composer-2.10.3.phar`。v2026.10.18.2 会自行准备缓存父目录权限。`mbstring` 重复加载警告不是 `composer-runtime-api` 依赖错误的原因，可另查 PHP 配置中的重复扩展项。保留 `.env`；若已经开始应用初始化或面板已安装，不通过删除 `.env` 强行重跑新装。

## 已有面板升级

本次 `v2026.10.25.2` 修复还要求独立 Node `v1.13-orphan.6`，完整顺序、两类部署和验证步骤见[本次更新教程](upgrade-2026.10.25.2.zh-CN.md)。只同步内置插件不足以修复旧节点内核。

先备份数据库、原 `.env` / APP_KEY、源码、插件目录与设置、Admin 资源、NodeEndpoint 公共 CA 和 Root 状态，确认具备恢复途径。不要卸载插件、清空数据库、删除 `.env`，也不要重新运行全新安装。

使用固定发行包并独立核对 SHA-256。将同一包解压到 Root 的专用工具目录，执行其中的升级器：

```bash
mkdir -m 700 /root/xboard-upgrade-tool
tar -xzf /root/xboard.tar.gz -C /root/xboard-upgrade-tool

python3 -I /root/xboard-upgrade-tool/scripts/deploy-release.py \
  --archive /root/xboard.tar.gz --sha256 '已独立核对的64位SHA256' \
  --app /www/wwwroot/panel.example.com --app-user www \
  --php /www/server/php/83/bin/php --composer /opt/xboard-tools/composer-2.10.3.phar \
  --database-backup /root/已完成的数据库备份.sql
```

数据库备份必须是已存在、非空、权限 0600 的普通文件；升级器核对文件形态并保存 Root 私有副本，无法仅凭文件存在证明其可恢复性。恢复演练需单独执行。

升级器校验整个归档、拒绝路径逃逸 / 链接 / 运行数据和依赖清单变更，进入维护模式并备份待替换源码。它不覆盖 `.env`、`storage/`、`vendor/`、`plugins/` 或公共存储链接。新插件来源先进入 `bundled-plugins/`，随后由应用用户校验和同步到运行目录。

已装同版本的插件会按原状态保留；已装旧版仅在来源清单可验证时升级并保留配置、记录 ID、CA 和启停选择。NodeEndpoint 已发布的 1.0.0 / 1.0.1 / 1.0.2 / 1.0.3 / 1.0.4 / 1.0.5 有固定来源认领清单。未知修改、旧二维码 1.0.7 的 core overlay 或无法确认的源码需要先单独审计，不盲目覆盖；高于内置版本的用户插件不降级。

已有 NodeEndpoint Root 入口在普通应用升级中只校验并补防火墙 / 同步 CA，不修改地址、重签证书或更换 CA。地址、端口、应用或 PHP 用户不一致时停止。旧入口遗漏 IPv4 路径时，升级后按上方 `--refresh-entry` 执行一次刷新。未配置过入口的已有面板可在升级时首次配置；纯域名方式可显式 `--skip-endpoint`。

如果代码已通过其他受控流程部署，可以在网站目录运行：

```bash
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php --composer /opt/xboard-tools/composer-2.10.3.phar
```

`update.sh` 不自动 fetch / merge / reset，也不追踪浮动分支。需要明确重新启用所有四插件时加 `--enable-all`；普通同步保留管理员禁用选择。不得把此命令当成数据库回滚工具。

源码复制阶段失败会尝试恢复该阶段的源码；一旦开始应用迁移或插件生命周期，失败会保留维护模式、数据库备份和源码恢复证据，不冒险把已迁移数据库与旧代码自动拼回。查明原因后再决定重试或成对恢复。Root 防火墙 / CA 后续失败也不会重新生成证书。

## 权限和运行边界

本版保留后台私有归档更新的既有能力，因此应用代码由非 Root 站点用户维护；只读 Root 代码配置与这项能力不兼容。准备权限只处理已知应用代码 / 运行目录，不操作网站目录之外的链接和挂载，不使用 777。数据库、插件和应用 PHP 始终以站点用户运行，Web 不获得 sudo 或 Root 执行通道。

SmartExpiry 与 QRCode Extend 需要在 Admin 的 `assets/`、`locales/` 和入口目录创建临时文件后原子重命名；仅给 JS 文件可写权限不足。两者共享的 `storage/framework/xboard-admin-patch.lock` 保留。二维码固定使用 1.2.0，安装时不部署旧版四文件 core overlay。

Root 工具从固定摘要的随包资产校验后复制至 `/opt/xboard-root-tools/` 和 `/opt/nodeendpoint-quick/`，再从 Root 私有缓存加载。私钥和监听状态位于 `/var/lib/node-endpoint/`，不会交给 PHP 用户。

FlowScope 默认安装启用的是面板插件；每台节点的采集器仍需在对应节点运行其 Root 安装命令，不能在面板安装时替未知远端节点建账号和服务。新机器的域名 / IP 私有 CA 双选命令由 NodeEndpoint 提供；旧节点迁移仍按保留配置流程执行。

验证结果及延期项目见 [本版测试报告](bundled-validation.zh-CN.md)。没有运行的 Docker、完整灾备、既有 Go UUID / Observer 和客户端业务测试不能据此视为通过。

## 本地构建和后续归档更新

维护者在干净、已提交的独立源码工作树中运行：

```bash
python3 -I scripts/build-private-release.py --output /你的私有发行目录
```

生成 `releases/版本/xboard.tar.gz`、SHA256SUMS、来源锁和验证报告，以及根目录 `latest.json`。包内 `build.json` 和摘要来自同一 Git commit；不包含 `.env`、数据库、运行插件、vendor、Git 或私钥。相同 commit 的包可重复构建为相同字节。

新装默认选择 `XBOARD_UPDATE_MODE=archive`，`XBOARD_UPDATE_BASE_URL` 留空，后台更新入口在配置自有 HTTPS 发行服务之前不可用。发行服务需提供 `/latest.json` 和 `/releases/版本/xboard.tar.gz`，不得填写原作者资源。已有 `.env` 不会被模板改变；首次接入该归档更新路线仍先按本页 Root 升级流程准备权限和入口。后台更新只能执行站点用户的应用升级，无法代替首次 Root 配置。
