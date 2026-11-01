# 全新安装 v2026.11.01.1：宝塔 / aaPanel / PHP-FPM

本页用于 Linux 上新建的空站点：宝塔 / aaPanel、PHP 8.3、原生 PHP-FPM、Nginx / OpenResty、MySQL / MariaDB 和 Redis。已有面板使用[本版升级说明](upgrade-2026.11.01.1.zh-CN.md)，保留原数据库、`.env` / APP_KEY、机器身份和 CA。

本版全新安装会自动安装并启用以下五个功能插件，无需另传插件 ZIP：

| 插件 | 内置版本 |
| --- | --- |
| NodeEndpoint | `1.1.3` |
| SmartExpiry | `1.2.0` |
| QRCode Extend | `1.3.1` |
| FlowScope | `2.4.0` |
| SubInfoCustomizer | `1.0.1` |

面板归档固定为 `v2026.11.01.1`。新机器使用面板生成的 Node 安装命令，取得独立 Node 仓库的最新正式发行安装器，由对应安装器核对二进制和摘要。插件日后可分别在线更新，见[在线更新说明](../operations/plugin-online-update.zh-CN.md)。

## 1. 准备环境

准备 Root SSH、Python 3.10+、PHP 8.3、Nginx / OpenResty、systemd、Redis、空 MySQL / MariaDB 数据库及独立账号。另需 OpenSSL、`ss`、`runuser`、`curl`、`tar`、`find`、`awk` 和 `sha256sum`。安装器不代装宝塔、升级系统或创建数据库账号。

为本站配置与实际 PHP-FPM 进程一致的网站系统用户。下文的 `www` 是示例，须与本站 FPM 身份一致；仅修改安装命令的 `--app-user` 不会改变 FPM 身份。Root 助手按网站 UID 授权，多站生产环境应使用专用 UID；多个站点共用 `www` 时共用授权边界。

PHP CLI 与网站 PHP-FPM 使用同一版本。启用锁定依赖需要的扩展：`redis`、`fileinfo`、`mbstring`、`curl`、DOM / XML、`openssl`、`pcntl`、`posix`、`pdo_mysql` 等；安装 PHP `zip` 或系统 `unzip` 供 Composer 解压。Composer 会检查完整平台要求，缺项应先补齐。本文的 FPM 路线不要求先安装 Swoole 或 Event。

确认 PHP CLI 中 `putenv`、`proc_open`、`pcntl_alarm`、`pcntl_signal`、`symlink` 等安装与队列所需函数可用。安装器在数据库初始化前预检 `symlink`，不需要为存储链接额外开放 `exec`。

### 1.1 安装并核对 OPcache

在 **宝塔 / aaPanel → PHP 8.3 → 安装扩展（Install extensions）** 中安装 `opcache`，确保扩展加载到本站实际使用的 PHP 8.3 FPM。在这套 FPM 实际读取的配置中核对：

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

内存单位为 MB。扩展路径沿用安装器生成的真实值，不重复追加 `zend_extension`。仅写 `opcache.enable=1` 不会代替安装与加载扩展。保留时间戳检查，让后续插件写入和源码更新能够被周期检查。

按运行中 FPM master 的实际二进制、配置路径和启动参数核对。宝塔默认路径可用以下只读预检；服务另带 `-c` 等参数时，沿用该服务的配置：

```bash
/www/server/php/83/sbin/php-fpm -t
/www/server/php/83/sbin/php-fpm -i | grep -E 'Server API|Loaded Configuration File|Zend OPcache|opcache\.(enable |memory_consumption|interned_strings_buffer|max_accelerated_files|validate_timestamps|revalidate_freq)'
```

确认 `Server API` 为 FPM/FastCGI、配置路径正确、存在 Zend OPcache 信息且参数符合上述设置。预检反映该二进制按当前配置启动时的加载情况；安装扩展或改配置后，还需按实际服务支持的方式重载 FPM，让运行中的 worker 加载配置，先核对同一 FPM 承载的站点范围。

CLI 的 `php -m` / `php -i` 或 CLI `opcache_get_status()` 不能证明网站 FPM 已启用缓存。实际运行缓存状态应在本站 FPM 请求上下文核对，不公开 `phpinfo` 或长期保留诊断页面。详细说明见[OPcache 安装与 FPM 验证](aapanel.zh-CN.md#php83-opcache)。

### 1.2 准备固定 Composer PHAR

本例使用 Composer `2.10.3`，以 Root 下载并核对固定摘要：

```bash
set -euo pipefail
install -d -o root -g root -m 755 /opt/xboard-tools
curl -fL --proto '=https' --tlsv1.2 \
  https://getcomposer.org/download/2.10.3/composer.phar \
  -o /opt/xboard-tools/composer-2.10.3.phar
printf '%s\n' '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /opt/xboard-tools/composer-2.10.3.phar' | sha256sum -c -
chown root:root /opt/xboard-tools/composer-2.10.3.phar
chmod 0644 /opt/xboard-tools/composer-2.10.3.phar
```

必须看到摘要校验 `OK` 才继续。安装器接受可信 Composer 2.2+ 的 2.x 版本；旧 2.0 / 2.1 不满足锁定依赖要求。后续显式传入上述 PHAR，避免误选系统旧版本。不执行 `composer update`、修改 `composer.lock` 或忽略平台要求。

## 2. 创建空站并下载本版归档

在宝塔 / aaPanel 创建独立网站，选择 PHP 8.3，并创建空 MySQL / MariaDB 数据库及独立账号。保存数据库名、账号和密码。网站目录必须是该新站的专用空目录；若有控制面板占位文件，先逐项核实，再通过站点工具移除，包括隐藏文件。不要清空已有业务目录。

在 Root 私有下载目录取得 [v2026.11.01.1 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.11.01.1) 的正式附件：

```bash
set -euo pipefail
umask 077
install -d -m 700 /root/xboard-install-20261101-1
cd /root/xboard-install-20261101-1
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.11.01.1/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.11.01.1/SHA256SUMS
awk '$2 == "xboard.tar.gz" { print }' SHA256SUMS > xboard.archive.sha256
test "$(wc -l < xboard.archive.sha256)" -eq 1
sha256sum -c xboard.archive.sha256
```

必须看到 `xboard.tar.gz: OK` 才继续。`SHA256SUMS` 也列出其他附件，这里只下载了源码归档，因此只抽取其中唯一的 `xboard.tar.gz` 行校验。缺失行、重复行或校验失败都应停止；不要直接对未下载的整张资产表执行校验。

确认实际新站目录存在且为空，再解压。将下方 `xboard_site_dir` 替换为刚创建的网站目录，引号应保留；在前述下载目录中执行：

```bash
set -euo pipefail
xboard_site_dir='/www/wwwroot/实际新站目录'
test -d "$xboard_site_dir"
test ! -L "$xboard_site_dir"
xboard_site_entry="$(find "$xboard_site_dir" -mindepth 1 -maxdepth 1 -print -quit)"
test -z "$xboard_site_entry"
tar -xzf xboard.tar.gz -C "$xboard_site_dir"
```

目录非空时先回到宝塔检查，不强行清空或混合两版源码。使用正式 `xboard.tar.gz`，不以 GitHub 自动生成的源码 ZIP 代替发行资产。归档含五插件源与公共 Root 工具资产，不含 vendor、数据库、运行配置或私钥。

## 3. 配置实际 PHP-FPM 网站

先在宝塔的网站设置中完成：

1. 运行目录设为 **`public/`**。
2. PHP 选择 **8.3**，保留宝塔为该站配置的实际 PHP-FPM 后端。
3. 设置 Laravel 路由规则：

   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$query_string;
   }
   ```

4. 检查 Nginx 配置，启动 / 重载 Nginx 和 PHP 8.3 FPM。

此时 Composer 和数据库尚未安装，网页暂时不可用；安装器需要发现真实运行的站点和 FPM 后端。先完成本节，再运行 `init.sh`，不要预先猜测 socket 或改套 Octane 代理。

### 网站 HTTPS 与节点通讯端口

网站浏览器入口通常使用 `80/TCP`、`443/TCP`。下一节的 `--port 8443` 用于 NodeEndpoint 独立节点通讯入口，不会自动为网站域名配置 `443` 或 SSL 证书。

在宝塔该网站的 SSL 设置中安装覆盖实际域名、未过期的证书及对应私钥，启用 HTTPS，保留 `public/` 和 PHP-FPM 设置。核对域名的 A / AAAA 记录指向实际源站，本机防火墙与云安全组允许网站所需端口。

使用 Cloudflare **Full (strict)** 时，源站须提供有效且覆盖该域名的 HTTPS 证书，可由公共可信 CA 或 Cloudflare Origin CA 签发；检查并重载 Nginx 后，再确认回源正常。Origin CA 证书用于 Cloudflare 到源站的连接，浏览器直连源站可能不受信任。NodeEndpoint 的自建私有 CA 属于节点信任链，不能直接代替网站证书。相关要求见 [Cloudflare Full (strict)](https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/) 和 [Origin CA](https://developers.cloudflare.com/ssl/origin-configuration/origin-ca/)。

## 4. 以 Root 运行安装入口

将实际目录、网站用户及 PHP 路径替换为本机值，在网站应用根目录执行：

```bash
cd /www/wwwroot/实际新站目录
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

安装器先检查已有安装状态、实际网站和权限，再降权为网站用户安装锁定依赖、初始化数据库并安装启用五插件。按提示配置数据库、Redis 及管理员信息。**本文使用 MySQL / MariaDB，选择数据库类型时明确选择 MySQL，不直接接受 SQLite 默认项**，随后填写已准备的数据库和独立账号。私下保存管理员初始信息。

首次配置节点通讯地址时，可以选择自动检测本机公网 IPv4，或手工输入实际 IP / 域名。自动检测结果须核对，检测失败必须手工填写。上方没有预填 `--host`，不会把文档示例 IP 写成通讯地址。

随后核对 NodeEndpoint Root 入口计划，按本机提示输入完整确认短语，形式为：

```text
INSTALL 实际地址:实际端口
```

这是安装器内的确认输入，须照本机显示的地址与端口填写，`INSTALL` 后有一个空格；不是另行执行的 Shell 命令。

正常流程完成独立 HTTPS 入口、公共 CA 和本机 Root 助手的初始化，无需再手工下载独立 Root 安装器。本版使用固定 Root 安装入口 `root-installer-v1.0.10`、Root tools `1.0.6` 和助手 `1.0.0`，安装资产由摘要校验。

新 CA 的口令随机生成，使用 APP_KEY 加密保存在独立凭据表中；CA 私钥位于网站外的 Root 私有目录。管理员可在 `/node-endpoint` 的证书区显式查看 / 复制托管口令，不将其放入公开日志。数据库、原 `.env` / APP_KEY、Root 状态和外置私钥须一起备份。

本机防火墙与云安全组分别管理，云安全组仍需放行实际节点通讯端口，例如 `8443/TCP`。没有 WS 后端时先完成 REST 对接。明确延期配置独立入口时可加 `--skip-endpoint`：五插件仍安装启用，独立入口、公共 CA 与助手一起延期，节点入口尚未就绪。

### 4.1 核对助手与节点入口

安装返回后，通过面板 HTTPS 地址登录后台，打开同源 `/node-endpoint`。核对本机 Root 助手已连接，公共 CA 与已保存入口验证正常；systemd 进程显示启动不等于助手已可应答。

页面可以复用当前后台管理员登录。保存实际设置后，先生成操作预览，核对目标与阶段，再按页面要求输入完整确认文字执行配置、刷新、证书续签或防火墙任务。公共 CA 和状态通过“校验并同步公共 CA 与状态”导入，再点击“验证已保存入口”。实际 Node 的认证与网络可达性仍需在节点机器验证。

安装中断时保留数据库、`.env` 和输出，按失败阶段恢复。已开始应用初始化后，不重复执行全新安装、删除 APP_KEY 或重建 CA；助手未就绪也按对应阶段排查。

## 5. 配置计划任务与 Horizon 守护

原生 PHP-FPM 安装器不会自动创建 cron 或 Supervisor 任务。安装完成后还需设置**每分钟调度**和**持续运行的 Horizon**。前者驱动订单检查、流量重置和在线清理等调度，后者执行队列任务，两项都要保留。

下列网站用户、PHP 路径和目录须与安装时一致，目录为含 `artisan` 的应用根目录。

### 5.1 宝塔计划任务：每分钟调度

打开 **「计划任务」→「添加任务」**，填写：

| 项目 | 设置 |
| --- | --- |
| 任务类型 | Shell 脚本 |
| 名称 | Xboard 定时调度；多站时附本站名称 |
| 周期 | 每 1 分钟，即 `* * * * *` |
| 执行用户 | root；脚本降权到网站用户 |
| 脚本内容 | 下方命令，替换实际用户和目录 |

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan schedule:run
```

保存后手动“执行”，检查日志没有 PHP、数据库、Redis 或权限错误；再等待下一分钟，确认日志新增自动执行记录。同一站点只保留一套调度，不重复添加 cron 或 `schedule:work`。前台列表刷新不代替定时调度。

### 5.2 Supervisor：持续运行 Horizon

在宝塔的进程守护管理 / Supervisor 中添加本站任务：

| 项目 | 设置 |
| --- | --- |
| 名称 | xboard-horizon；多站时附本站名称 |
| 运行用户 | 网站用户，例如 `www` |
| 工作目录 | `/www/wwwroot/实际新站目录` |
| 启动命令 | 下方命令，替换实际目录 |
| 守护进程数量 | `1`；Horizon 自行管理队列工作进程 |
| 自动启动 | 启用 |
| 自动重启 | `autorestart=true`，包括正常退出后的重启 |

```bash
/www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan horizon
```

保存并启动，确认状态持续运行；若退出，查看任务输出日志。仅设置“异常退出才重启”不足以支持 Horizon 的优雅切换，须核对实际 `autorestart=true`。

### 5.3 核对本版流量队列默认值

确认本站 `.env` 的 `APP_ENV=production`。本版生产 `data-pipeline` 使用 `balance=simple`，未设置 `HORIZON_DATA_PIPELINE_MAX` 时固定分配 **3 个 worker**，由 `traffic_fetch`、`stat`、`user_alive_sync` 三个队列各使用一个；这是流量组的数量，Horizon 还有业务、通知等其他队列进程。显式 `HORIZON_DATA_PIPELINE_MAX=6` 时，这三个队列各两个 worker。

先沿用默认值，再根据真实积压与服务器资源调整。以网站用户查看应用加载的配置与服务状态：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan config:show horizon
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan horizon:status
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan schedule:list
```

在配置中核对 `environments.production.data-pipeline` 的 `balance`、三个队列和 `maxProcesses`，并结合 Supervisor 实际使用的目录、用户、环境与进程核对。CLI 配置输出不能单独证明已运行的 Horizon 读到相同配置。`horizon:status` 应显示运行中；`schedule:list` 只确认调度表可加载，周期执行仍以宝塔自动记录为准。

安装后若修改 `.env`，还需核对配置缓存；已有缓存时，审阅全部配置后按部署流程重建，`config:cache` 会使其他设置一起生效。Horizon 已运行时，确认 Supervisor 自动拉起正常，再以网站用户执行 `artisan horizon:terminate --wait`，待当前任务结束后使用新配置。详细边界见[流量队列与配置切换](aapanel.zh-CN.md#horizon-fixed-workers)。

## 6. 安装节点并验收

在后台建立套餐、权限组、机器和节点，核对节点通讯入口与公共 CA，复制**该机器页面生成的安装命令**，在对应节点新机器以 Root SSH 执行。本版命令使用独立 Node 仓库最新正式发行的 `install.sh`，由安装器继续校验匹配资产；不要照抄旧版本号、其他机器 Token 或新建身份命令去替换已有机器。

当前生成的命令含 `sudo bash`，节点需已安装 `sudo`；若已是 Root，可将命令中的 `sudo bash` 改为 `bash`，其余参数保持原样。

节点防火墙及 Hysteria2 端口管理按实际 Node 发行和[节点操作说明](../operations/node-management-hy2.zh-CN.md)核对。本机规则不代替云安全组；使用端口跳跃时，云安全组仍需允许广告 UDP 范围。同一网络命名空间内避免多个 Node 进程同时管理相同规则。

FlowScope 的采集器在对应节点另外部署，面板插件启用不代表采集器已运行。WS 为可选项，可以先使用 REST；启用 WS 后须独立核对后端进程、Nginx 路由及实际 WSS 链路。

完成后逐项检查：

- 管理端可以登录，五个功能插件已启用，二维码、续期与订阅信息管理入口正常。
- Root 助手、公共 CA 与 NodeEndpoint 独立 HTTPS 入口状态正常，实际 Node 能认证、上线和上报。
- 宝塔计划任务有每分钟自动执行记录，Horizon 持续运行，对应应用、FPM、队列日志没有错误。
- 使用实际用户获取订阅并连接节点，核对流量统计、在线状态与套餐限制。在线设备按来源 IP 去重，同 NAT 下的不同物理设备不能区分。

后台“订阅信息管理”可调整订阅顶部 0–4 行信息。保存后，下一次获取订阅使用新模板；预览采用示例数据。禁用插件会恢复原生逻辑并保留模板，隐藏信息行不会移除真实节点。

保存数据库、原 `.env` / APP_KEY、源码、插件配置、`storage/app/`、公共 CA、Root 状态及网站外 CA 私钥的可恢复备份。CA 加密备份离线保存，同时记录实际 Node 版本和机器身份。后续更新使用[本版升级说明](upgrade-2026.11.01.1.zh-CN.md)。

## 7. 新装失败时检查

若提示目录非空、没有发现 FPM 网站、缺 PHP 扩展或 Composer 版本过旧，先修正对应前置条件。数据库交互使用本文准备的 MySQL / MariaDB 数据库，连接失败时检查服务、账号和权限。应用初始化后失败，保留现场与日志，按失败阶段恢复，不通过重跑全新安装或删除 `.env` 处理。

Cloudflare 521 表示回源连接被拒绝，检查 Nginx 是否运行、网站是否监听当前加密模式所需端口，以及 DNS 和防火墙。HTTP 500 则结合请求时间查看 Nginx、PHP-FPM 和本站 `storage/logs/` 日志。可在源站使用以下只读检查，替换实际域名：

```bash
ss -lntp | grep -E ':(80|443)[[:space:]]'
/www/server/nginx/sbin/nginx -t
curl -I -H 'Host:实际域名' http://127.0.0.1/
curl -kI --resolve 实际域名:443:127.0.0.1 https://实际域名/
```

Nginx 仅绑定特定本机 IP 时，将 `127.0.0.1` 改为该绑定地址。最后一条的 `-k` 仅诊断本地响应，不改变正式证书校验配置。日志按实际文件名读取：

```bash
cd /www/wwwroot/实际新站目录
ls -lt storage/logs/
tail -n 80 storage/logs/实际日志文件名
```

分享日志前隐藏密码、Token、APP_KEY 和私钥。本文给出本版源码的新装操作；已完成的发行校验、离线回归及完整新装测试的覆盖范围，以[本版验证记录](release-2026.11.01.1-validation.zh-CN.md)及发布附件 `VALIDATION.md` 为准。
