# 全新安装 v2026.10.29.2：aaPanel / PHP-FPM

本页用于 Linux 上新建的空站点：aaPanel / 宝塔、PHP 8.3、原生 PHP-FPM、Nginx / OpenResty、MySQL / MariaDB 和 Redis。已有面板使用[本版升级说明](upgrade-2026.10.29.2.zh-CN.md)，保留原数据库、`.env` / APP_KEY、机器身份和 CA，不执行下方全新安装。

本版默认安装 NodeEndpoint `1.1.0`、SmartExpiry `1.2.0`、QRCode Extend 审计副本 `1.2.0`、FlowScope `2.4.0` 和 SubInfoCustomizer `1.0.1`。五项在全新安装时自动安装并启用，无需另传插件 ZIP。配套 Node 保持 `v1.13-orphan.7`。实际验收及归档验证边界见[本版验证范围](release-2026.10.29.2-validation.zh-CN.md)。

当前隔离原生新站初始化已成功退出，真实 Root 操作 `45/45`、基础探测 `4/4` 通过。最终发行归档的升级与保全测试尚待授权；Linux PHP 8.3 整套插件测试也尚未完成，不能以组件检查代替这两项结果。

## 1. 准备环境

准备 Root SSH、Python 3.10+、PHP 8.3、Nginx / OpenResty、systemd、Redis、空 MySQL / MariaDB 数据库及独立账号。另需 OpenSSL、`ss`、`runuser`、`curl`、`tar` 和 `sha256sum`。安装器不代装面板管理软件、创建数据库账号或升级系统。

为本站配置与实际 PHP-FPM 进程一致的网站系统用户。Root 助手按 UID 授权，生产多站应使用专用 UID；多个站点共用 `www` 时共用授权边界。下文的 `www` 是示例，须与本站 FPM 身份一致，仅修改 CLI 的 `--app-user` 不会改变 FPM 身份。

PHP CLI 与网站 PHP-FPM 使用同一版本，启用锁定依赖需要的扩展：`redis`、`fileinfo`、`mbstring`、`curl`、DOM / XML、`openssl`、`pcntl`、`posix`、`pdo_mysql` 等。安装 PHP `zip` 或系统 `unzip` 供 Composer 解压；Composer 会检查完整平台要求，缺项应先补齐。原生 FPM 路线不要求 Swoole 或 Event。

确认 PHP CLI 中 `putenv`、`proc_open`、`pcntl_alarm`、`pcntl_signal`、`symlink` 等相应函数可用。安装器会在数据库初始化前预检 `symlink`；不要用忽略平台要求来绕过检查，也不需要为存储链接额外开放 `exec`。

本例固定 Composer `2.10.3`，以 Root 下载并核对其固定摘要：

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

校验必须为 `OK`。安装器接受可信 Composer 2.2+ 的 2.x 版本；旧 2.0 / 2.1 不满足锁定依赖的 runtime API。不要运行 `composer update`，不要修改 `composer.lock`。

## 2. 创建空站并取得固定归档

在 aaPanel / 宝塔创建独立网站，选择 PHP 8.3，准备空数据库及账号。网站目录必须是该新站的专用空目录；若有控制面板占位文件，先逐项核实后通过站点工具移除。不要清空已有业务目录，也不要用递归删除命令绕过目录非空提示。

从 [v2026.10.29.2 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.29.2) 下载正式附件 `xboard.tar.gz`、`SHA256SUMS`，核对发布摘要。在 Root 私有下载目录执行：

```bash
set -euo pipefail
install -d -m 700 /root/xboard-install-20261029-2
cd /root/xboard-install-20261029-2
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.2/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.2/SHA256SUMS
sha256sum -c SHA256SUMS
tar -xzf xboard.tar.gz -C /www/wwwroot/实际新站目录
```

最后一行必须替换为已确认的空站目录；校验失败不继续。使用正式 `xboard.tar.gz`，不要用 GitHub 自动生成的源码 ZIP 代替本页的发行资产校验。归档含五插件源与公共 Root 工具资产，不含 vendor、数据库、运行配置或私钥。

## 3. 配置实际 PHP-FPM 网站

在站点设置中将运行目录设为 `public/`，PHP 选择 8.3，保留 aaPanel 为该站配置的 PHP-FPM 后端。配置 Laravel 路由规则：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}
```

检查 Nginx 配置并启动 / 重载 Nginx、PHP-FPM。此时 Composer 和数据库尚未安装，网页尚不可用；但安装器需要发现真实运行的站点与 FPM 后端，不能预先猜测 socket 或套用 Octane 代理。

### 网站域名 HTTPS 与 Cloudflare

浏览器访问的网站通常使用 HTTP `80` / HTTPS `443`。下一节的 `--port 8443` 用于 NodeEndpoint 独立节点通讯入口；安装器不会因此自动为网站域名配置 `443` 或网站 SSL 证书。

使用 Cloudflare 代理及 **Full (strict)** 时，在宝塔该域名网站的 SSL 设置中安装未过期、覆盖实际域名的证书和对应私钥，启用 HTTPS `443`，保留该站的 `public/` 运行目录及 PHP-FPM 配置。证书可由公共可信 CA 或 Cloudflare Origin CA 签发；Cloudflare 会校验证书有效期、签发者和域名。检查并重载 Nginx 后，再确认 Cloudflare 的 Full (strict) 回源正常。要求见 [Cloudflare Full (strict) 文档](https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/)。

Cloudflare Origin CA 证书用于 Cloudflare 到源站的连接，关闭代理后浏览器直接访问源站可能提示不受信任。NodeEndpoint 自建私有 CA 属于节点信任链，不能直接当作 Cloudflare Full (strict) 所需的网站证书。Origin CA 的部署及信任范围见 [Cloudflare 官方说明](https://developers.cloudflare.com/ssl/origin-configuration/origin-ca/)。

核对 Cloudflare 的 A / AAAA 记录指向实际可达的源站，并在本机防火墙及云安全组允许网站所需的 `80/TCP`、`443/TCP`；若限制回源来源，规则须允许 Cloudflare。安装器为节点通讯入口处理的 `8443/TCP` 规则不替代网站端口规则。完成源站 HTTPS 配置后保持 Full (strict)，不通过改成 Flexible 来跳过回源 HTTPS。

## 4. 以 Root 运行安装入口

替换实际站点目录、网站用户及 PHP 路径，再执行：

```bash
cd /www/wwwroot/实际新站目录
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

安装器先检查已有安装状态、网站和权限，再降权为网站用户安装锁定依赖、初始化数据库并执行五插件原生安装启用。按应用提示输入数据库、Redis 及管理员信息，私下保存管理员初始信息。无需先手工安装五插件或以 Root 运行应用队列。

首次节点通讯地址可选择自动检测本机公网 IPv4，或手工输入实际 IP / 域名。自动检测结果必须由管理员核对，失败时手工输入；文档示例地址不能用于真实通讯。上方没有预填 `--host`，不会把占位 IP 作为实际地址。

随后核对 NodeEndpoint Root 入口计划，并按本机提示输入完整确认短语，形式为 `INSTALL 实际地址:实际端口`。本版固定 Root 安装入口 `root-installer-v1.0.10`、不可变 Root tools `1.0.6` 及本机助手 `1.0.0`，由发行资产摘要校验；它们与插件和 Node standalone tools 分别版本化。

新的 CA 口令随机生成，使用原 APP_KEY 加密存入独立凭据表；CA 私钥保持在网站外的 Root 私有目录。数据库、`.env` / APP_KEY、Root 状态和外置私钥须一起备份，CA 加密备份离线保存。管理员可在 `/node-endpoint` 的证书区显式查看/复制托管口令，不应放入公开日志或问题报告。

本机防火墙和云安全组分别管理。Root 工具只处理支持的本机规则，云安全组仍需放行所选 TCP 端口；没有 WS 后端时先使用 REST。明确延期配置独立入口时可用 `--skip-endpoint`：五插件仍正常安装启用，独立入口、公共 CA 与 Root 助手一并延期；此时不能把应用安装成功视为节点通讯入口已就绪。

如果安装在应用初始化后中断，保留数据库、`.env` 和输出，按实际失败阶段恢复；不要重复执行全新安装、删除 APP_KEY 或重建 CA。未知 FPM / Root 布局会拒绝自动处理，应先修正站点配置。

### 4.1 核对 Root 助手与网页管理

正常的 Root 初始化流程在应用和五插件安装启用后，完成独立 HTTPS 入口配置，再自动安装并绑定本机 Root 助手。已有有效入口也会进入助手初始化，不因无需重新签发 CA 而跳过。结束前核对安装输出中的助手状态；若该阶段失败，保留现有数据库、APP_KEY、CA 和现场，从失败阶段恢复。

通过面板的 HTTPS 地址登录管理端，然后打开同源 `/node-endpoint`。本页可复用当前管理员后台登录；凭据只在本页内存使用，服务器仍核验管理员权限。后台退出、切换账号或登录过期后，本页会清理会话；“退出本页会话”保留后台登录，点击“使用当前后台登录”可恢复。

确认 Root 助手显示已连接。先保存实际 HTTPS 地址和端口，选择配置、刷新、续签服务器证书或防火墙任务，生成操作预览，核对目标和阶段，再输入页面展示的完整确认文字执行。入口端口须在 Root 策略批准范围内，新增允许端口继续通过 SSH 审阅。任务结果逐阶段显示，刷新浏览器可恢复本管理员保留的最近任务；部分完成、失败或中断时应核对现状，再生成新预览。Root 重启不会自动重放未完成任务；记录已被清理时页面提示重新预览。公共 CA 与状态通过“校验并同步公共 CA 与状态”导入，之后点击“验证已保存入口”，并在实际 Node 上独立验证认证与 WSS。

助手只提供固定任务，使用与本站网站进程相同的 UID 作为信任边界，不提供同一 UID 内应用之间的隔离。网页不提供任意 Shell、任意文件写入、根 CA 轮换或私钥导出；后两项继续由 Root SSH 工具执行。不要将网站 UID 与不受信任的其他应用共用。插件安装启用不依赖助手已存在；助手尚未安装时页面显示一次性 SSH 初始化指引，手动导入公共 CA 和状态仍可使用。

## 5. 必做：宝塔计划任务和队列守护

原生 PHP-FPM 安装器不会自动创建 cron 或 Supervisor 任务。第四节安装完成后，还要手工完成下面两项：**每分钟运行一次调度**，以及**持续运行 Horizon 队列**。订单检查、流量重置、在线状态清理等依赖调度；队列任务由 Horizon 处理。

下方的 `实际站点目录`、网站用户 `www` 和 PHP 路径均应替换为本站实际值，并与安装时保持一致。站点目录是包含 `artisan` 的应用根目录。

### 5.1 宝塔计划任务：每分钟执行调度

打开宝塔左侧 **「计划任务」→「添加任务」**，按下面填写：

| 项目 | 设置 |
| --- | --- |
| 任务类型 | Shell 脚本 |
| 任务名称 | Xboard 定时调度（多站点时加本站名称） |
| 执行周期 | 每 1 分钟执行一次，相当于 `* * * * *` |
| 执行用户 | root；下面的脚本会降权为网站用户 `www` |
| 脚本内容 | 使用下方命令，替换实际目录后保存 |

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际站点目录/artisan schedule:run
```

保存后点击这条任务的 **「执行」**，查看执行日志，确认没有 PHP、数据库、Redis 或权限错误。再等待下一分钟，确认日志中新增了自动执行记录；只有手动执行成功还不能证明周期已配置正确。

同一站点保留一套定时调度。若已有执行相同命令的 cron 或持续运行的 `schedule:work`，沿用已有配置并核对运行记录，避免重复添加。前台用户列表约 5 秒刷新不会替代此任务。

### 5.2 Supervisor：持续运行 Horizon 队列

在宝塔的进程守护管理 / Supervisor 中为本站添加守护任务：

| 项目 | 设置 |
| --- | --- |
| 任务名称 | xboard-horizon（多站点时加本站名称） |
| 运行用户 | 网站用户，例如 `www` |
| 运行目录 | `/www/wwwroot/实际站点目录` |
| 启动命令 | 使用下方命令，替换实际目录 |
| 守护进程数量 | 1；Horizon 自行管理队列工作进程 |
| 自动启动 / 异常重启 | 均启用 |

```bash
/www/server/php/83/bin/php /www/wwwroot/实际站点目录/artisan horizon
```

保存并启动守护任务，确认其状态持续为运行中；若启动后退出，先查看该任务的输出日志。Horizon 由 Supervisor 持续运行，5.1 的每分钟调度仍需保留。

### 5.3 检查配置

可在 Root SSH 中以网站用户检查队列状态和已注册的调度：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际站点目录/artisan horizon:status
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际站点目录/artisan schedule:list
```

`horizon:status` 应显示 Horizon 正在运行。`schedule:list` 只验证调度表能够加载，实际周期执行应以 5.1 的宝塔自动执行记录为准。

可选 WS 部署应另按实际入口、进程及网络验证，本页不声称真实 WS 链路已验收。

## 6. 安装配套节点并检查

登录管理端，确认五插件已启用，NodeEndpoint 的实际入口与公共 CA 验证成功。建立套餐、权限组、机器及节点，使用该机器生成的安装命令；新机入口固定独立 Node `v1.13-orphan.7`。先完成面板再安装 Node，不用新机命令替换已有机器身份。

创建用户应默认选中 API 首个实际计划；手选“无”或其他计划保持有效。检查新用户继承套餐设备限制、同 IP 的多连接去重、不同来源 IP 按限额准入、正常断开后在线数下降、长连接正常续期。只有全部相关 Node 升级才提供严格跨节点中心准入；同 NAT 出口后的不同物理设备仍共享一个来源 IP。

FlowScope 的采集器仍在对应节点部署，面板插件启用不等于采集器已运行。保存站点、数据库、APP_KEY、Root CA 和机器身份的可恢复备份。新发行完整归档的安装验收结果以随包 `VALIDATION.md` 为准，历史教程和此前候选构建不能代替该记录。

## 7. 订阅信息管理

刷新后台，打开左侧“订阅信息管理”。默认三行显示订阅日期/UID、剩余/总流量、到期/重置天数；可保存 0–4 行、九个动态变量及两种日期格式。预览使用示例数据，正式订阅使用当前用户数据。保存后下一次获取订阅生效。

该插件启用时接管订阅顶部信息；禁用后恢复原生逻辑并保留模板。只隐藏信息行不会移除真实节点。未知订阅核心或后台构建会拒绝启用，应使用本发行完整归档，不绕过兼容校验。

## 8. 新装后出现 Cloudflare 521 或 HTTP 500

[Cloudflare 521](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/error-521/) 表示回源连接被拒绝。先检查 Nginx 是否运行、网站是否监听加密模式所需端口，以及 DNS 和防火墙是否正确。Full / Full (strict) 下缺少 `443` 监听是排查方向之一，不能仅凭 521 就断定没有安装证书。

在源站 SSH 执行以下只读检查，把命令中的域名替换为实际网站域名：

```bash
ss -lntp | grep -E ':(80|443)[[:space:]]'
/www/server/nginx/sbin/nginx -t
curl -I -H 'Host:实际域名' http://127.0.0.1/
curl -kI --resolve 实际域名:443:127.0.0.1 https://实际域名/
```

若 Nginx 仅绑定特定本机 IP，将 `127.0.0.1` 换成该绑定地址。最后一条保留域名和 TLS SNI，`-k` 仅用于诊断本地源站响应，不改变网站或 Cloudflare 的证书校验设置。

如果 HTTPS 返回 521、HTTP 返回 500，应分别核对 HTTPS 连接和 HTTP 500 的原因。500 说明该请求已获得服务器错误响应；结合请求时间检查本站 Nginx 错误日志、PHP-FPM 日志及 `storage/logs/` 下最新的 Laravel 日志（默认按日生成）。公开响应头只能作为线索，最终原因以源站配置与日志为准。

先列出实际日志，再将最后一行的文件名替换为列表中对应请求日期的日志，常见名称为 `laravel-YYYY-MM-DD.log`：

```bash
cd /www/wwwroot/实际站点目录
ls -lt storage/logs/
tail -n 80 storage/logs/实际日志文件名
```

保留当前数据库、`.env` / APP_KEY、插件设置和安装输出；不要通过重跑全新安装、删除 `.env` 或重新生成 APP_KEY 处理 521 / 500。分享诊断输出前隐藏密码、Token、APP_KEY 和私钥。
