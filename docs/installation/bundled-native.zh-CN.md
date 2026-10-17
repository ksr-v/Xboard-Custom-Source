# 独立 Xboard：内置插件与 Root 安装

本版基于用户维护的 `ksr-v/Xboard-Custom-Source`，不拉取原版 Xboard、原版子模块或原版 Node 安装源。插件源码和公共 Root 工具资产随发行包提供；PHP 第三方依赖仍按原 `composer.lock` 安装。

| 内置功能插件 | 固定版本 | 源码位置 |
| --- | --- | --- |
| NodeEndpoint | 1.0.3 | `bundled-plugins/NodeEndpoint` |
| SmartExpiry | 1.2.0 | `bundled-plugins/SmartExpiry` |
| QRCode Extend | 1.2.0，审计副本 | `bundled-plugins/Qrcodeextend` |
| FlowScope | 2.4.0，含五个采集器安装资产 | `bundled-plugins/Flowscope` |

来源 commit 和每个文件摘要记录在 `bundled-plugins/manifest.json`。安装器校验后部署到正常的 `plugins/` 目录，通过原生插件生命周期执行迁移、安装和启用。后续仍可使用原生插件管理，不以 core 同名目录遮蔽用户上传版本。

## 从零安装：宝塔 / aaPanel 原生 PHP-FPM

先准备 Linux、Python 3.10+、PHP 8.2+、可信 Composer 2、Nginx/OpenResty、数据库、Redis、OpenSSL、`ss` 和 `runuser`。按锁定依赖启用所需 PHP 扩展，CLI 与 FPM 版本应一致。安装器不升级系统或 Composer，不创建数据库账号，也不代装宝塔。

在宝塔创建专用空网站目录和数据库，网站运行目录设为 `public/`，配置 PHP-FPM / Laravel `try_files` 并启动网站。安装独立入口时需要从当前真实 Nginx 配置发现该站点和后端；不要先猜测 socket 或覆盖原有网站配置。

从已确认的发行附件取得 `xboard.tar.gz` 和 SHA256SUMS，独立核对摘要后，将源码解压到新网站的空目录。例如：

```bash
sha256sum -c SHA256SUMS
mkdir -p /www/wwwroot/panel.example.com
tar -xzf /root/xboard.tar.gz -C /www/wwwroot/panel.example.com
cd /www/wwwroot/panel.example.com

bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /usr/local/bin/composer \
  --host '你的节点通讯公网IP' --port 8443
```

PHP、Composer 和用户名必须替换为本机实际值；Composer 路径应指向可信 PHP 脚本 / PHAR。不要在已有业务目录执行本节命令。

安装顺序：

1. Root 检查专用目录、站点用户、已有 `APP_KEY` / 安装标记和实际 FPM 站点。
2. Root 准备应用、运行目录、Admin 原子补丁父目录及公共资源的权限；Root 工具缓存和 PKI 保持外置。
3. 以站点用户运行 Composer、数据库安装、四插件源校验、迁移和启用。数据库 / Redis 及管理员信息使用 Xboard 原有交互；保留终端显示的管理员初始信息。
4. 以站点用户保存 NodeEndpoint 设置，Root 创建独立 HTTPS 入口，放行本机配置 TCP 端口，降权导入公共 CA。

Root 入口会展示计划并要求输入 `INSTALL IP:8443`。`Encrypted CA passphrase` 是加密 CA 私钥的口令，至少 16 字符，隐藏输入并需要妥善保存，不是面板登录密码。私钥不得上传面板；首次 CA 和服务器证书采用双十年策略，后续证书不超过 CA 到期时间。加密导出和离线保管仍需按 NodeEndpoint 工具说明完成。

本机防火墙支持运行中的 firewalld 和 UFW。未启用 UFW 时只保存规则并报告，不自动启用整个防火墙；冲突 / 未知规则需要人工处理。默认规则允许所有来源访问该 TCP 通讯端口。服务商防火墙和云安全组仍需在控制台放行。

多站点或特殊布局不能自动确认时，安装器停止并说明原因。Docker / Octane 不套用 PHP-FPM 发现逻辑。若明确只使用域名方式或自行维护独立入口，可加 `--skip-endpoint`，四插件仍安装启用，但 IP 入口不会被标记为就绪。`--prepare-only` 只准备权限，不运行 Composer / 数据库或签发证书。

全新 SQLite 安装使用 `storage/app/private/database.sqlite`（文件 0600），已有数据库地址和 `.env` 在升级时保持原值。MySQL / PostgreSQL 的账号及库需预先创建。

## 已有面板升级

先备份数据库、原 `.env` / APP_KEY、源码、插件目录与设置、Admin 资源、NodeEndpoint 公共 CA 和 Root 状态，确认具备恢复途径。不要卸载插件、清空数据库、删除 `.env`，也不要重新运行全新安装。

使用固定发行包并独立核对 SHA-256。将同一包解压到 Root 的专用工具目录，执行其中的升级器：

```bash
mkdir -m 700 /root/xboard-upgrade-tool
tar -xzf /root/xboard.tar.gz -C /root/xboard-upgrade-tool

python3 -I /root/xboard-upgrade-tool/scripts/deploy-release.py \
  --archive /root/xboard.tar.gz --sha256 '已独立核对的64位SHA256' \
  --app /www/wwwroot/panel.example.com --app-user www \
  --php /www/server/php/83/bin/php --composer /usr/local/bin/composer \
  --database-backup /root/已完成的数据库备份.sql
```

数据库备份必须是已存在、非空、权限 0600 的普通文件；升级器核对文件形态并保存 Root 私有副本，无法仅凭文件存在证明其可恢复性。恢复演练需单独执行。

升级器校验整个归档、拒绝路径逃逸 / 链接 / 运行数据和依赖清单变更，进入维护模式并备份待替换源码。它不覆盖 `.env`、`storage/`、`vendor/`、`plugins/` 或公共存储链接。新插件来源先进入 `bundled-plugins/`，随后由应用用户校验和同步到运行目录。

已装同版本的插件会按原状态保留；已装旧版仅在来源清单可验证时升级并保留配置、记录 ID、CA 和启停选择。NodeEndpoint 已发布的 1.0.0 / 1.0.1 / 1.0.2 有固定来源认领清单。未知修改、旧二维码 1.0.7 的 core overlay 或无法确认的源码需要先单独审计，不盲目覆盖；高于内置版本的用户插件不降级。

已有 NodeEndpoint Root 入口只校验并补防火墙 / 同步 CA，不修改地址、重签证书或更换 CA。地址、端口、应用或 PHP 用户不一致时停止。未配置过入口的已有面板可在升级时首次配置；纯域名方式可显式 `--skip-endpoint`。

如果代码已通过其他受控流程部署，可以在网站目录运行：

```bash
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php --composer /usr/local/bin/composer
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
