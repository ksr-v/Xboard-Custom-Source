# 全新安装 v2026.10.29.1：aaPanel / PHP-FPM

本页用于 Linux 上新建的空站点：aaPanel / 宝塔、PHP 8.3、原生 PHP-FPM、Nginx / OpenResty、MySQL / MariaDB 和 Redis。已有面板使用[本版升级说明](upgrade-2026.10.29.1.zh-CN.md)，保留原数据库、`.env` / APP_KEY、机器身份和 CA，不执行下方全新安装。

本版默认安装 NodeEndpoint `1.0.10`、SmartExpiry `1.2.0`、QRCode Extend 审计副本 `1.2.0`、FlowScope `2.4.0` 和 SubInfoCustomizer `1.0.1`。五项在全新安装时自动安装并启用，无需另传插件 ZIP。配套 Node 保持 `v1.13-orphan.7`。实际验收及归档验证边界见[本版验证范围](release-2026.10.29.1-validation.zh-CN.md)。

## 1. 准备环境

准备 Root SSH、Python 3.10+、PHP 8.3、Nginx / OpenResty、Redis、空 MySQL / MariaDB 数据库及独立账号。另需 OpenSSL、`ss`、`runuser`、`curl`、`tar` 和 `sha256sum`。安装器不代装面板管理软件、创建数据库账号或升级系统。

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

从 [v2026.10.29.1 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.29.1) 下载正式附件 `xboard.tar.gz`、`SHA256SUMS`，核对发布摘要。在 Root 私有下载目录执行：

```bash
set -euo pipefail
install -d -m 700 /root/xboard-install-20261029-1
cd /root/xboard-install-20261029-1
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.1/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.1/SHA256SUMS
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

随后核对 NodeEndpoint Root 入口计划，并按本机提示输入完整确认短语，形式为 `INSTALL 实际地址:实际端口`。Root 入口继续使用 `root-installer-v1.0.9` / Root tools `1.0.6`；这是独立的 Root 工具版本，不是插件版本或 Node standalone tools 版本。

新的 CA 口令随机生成，使用原 APP_KEY 加密存入独立凭据表；CA 私钥保持在网站外的 Root 私有目录。数据库、`.env` / APP_KEY、Root 状态和外置私钥须一起备份，CA 加密备份离线保存。管理员可在 `/node-endpoint` 的证书区显式查看/复制托管口令，不应放入公开日志或问题报告。

本机防火墙和云安全组分别管理。Root 工具只处理支持的本机规则，云安全组仍需放行所选 TCP 端口；没有 WS 后端时先使用 REST。明确延期配置独立入口时可用 `--skip-endpoint`，但完成应用安装不表示该入口或公共 CA 已就绪。

如果安装在应用初始化后中断，保留数据库、`.env` 和输出，按实际失败阶段恢复；不要重复执行全新安装、删除 APP_KEY 或重建 CA。未知 FPM / Root 布局会拒绝自动处理，应先修正站点配置。

## 5. 启动队列和调度

在 Supervisor 中为本站建立一个队列任务，运行用户为网站用户（示例 `www`），工作目录为实际站点目录，启动命令为：

```bash
/www/server/php/83/bin/php artisan horizon
```

启用自动启动和异常重启。另以同一网站用户每分钟执行一次调度；若管理界面仅能以 Root 运行计划任务，使用降权命令：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/实际新站目录/artisan schedule:run
```

路径与 PHP 版本应和安装时相同，只配置一套调度。设备状态每分钟清理依赖正常调度；前台列表约 5 秒刷新并不代替后台任务。可选 WS 部署应另按实际入口、进程及网络验证，本页不声称真实 WS 链路已验收。

## 6. 安装配套节点并检查

登录管理端，确认五插件已启用，NodeEndpoint 的实际入口与公共 CA 验证成功。建立套餐、权限组、机器及节点，使用该机器生成的安装命令；新机入口固定独立 Node `v1.13-orphan.7`。先完成面板再安装 Node，不用新机命令替换已有机器身份。

创建用户应默认选中 API 首个实际计划；手选“无”或其他计划保持有效。检查新用户继承套餐设备限制、同 IP 的多连接去重、不同来源 IP 按限额准入、正常断开后在线数下降、长连接正常续期。只有全部相关 Node 升级才提供严格跨节点中心准入；同 NAT 出口后的不同物理设备仍共享一个来源 IP。

FlowScope 的采集器仍在对应节点部署，面板插件启用不等于采集器已运行。保存站点、数据库、APP_KEY、Root CA 和机器身份的可恢复备份。新发行完整归档的安装验收结果以随包 `VALIDATION.md` 为准，历史教程和此前候选构建不能代替该记录。

## 7. 订阅信息管理

刷新后台，打开左侧“订阅信息管理”。默认三行显示订阅日期/UID、剩余/总流量、到期/重置天数；可保存 0–4 行、九个动态变量及两种日期格式。预览使用示例数据，正式订阅使用当前用户数据。保存后下一次获取订阅生效。

该插件启用时接管订阅顶部信息；禁用后恢复原生逻辑并保留模板。只隐藏信息行不会移除真实节点。未知订阅核心或后台构建会拒绝启用，应使用本发行完整归档，不绕过兼容校验。
