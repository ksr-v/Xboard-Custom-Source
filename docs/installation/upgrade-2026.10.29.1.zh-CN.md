# 更新至 v2026.10.29.1：默认加入订阅信息管理

本页只覆盖本仓库的 aaPanel + Linux + PHP 8.3 + 原生 PHP-FPM + Nginx/OpenResty + MySQL/MariaDB + Redis。先更新面板，再逐台升级配套 Node `v1.13-orphan.7`。已有站点不重新执行 `init.sh`、`xboard:install` 或生成 APP_KEY。

## 保存现状

安排维护窗口，先在站点备份副本和一台测试节点演练。暂停此站点的队列、WebSocket 长驻任务和外部调度，记录实际服务名称、运行用户及命令。保存一致性数据库备份，以及原 `.env` / APP_KEY、源码、`plugins/`、`storage/app/`、公共 CA、NodeEndpoint Root 状态及其外置私钥。备份放在 Root 私有目录，确认可恢复，不上传到公开仓库。

保留原机器身份、Token、CA 和 Node 配置。升级 Node 服务会断开已有代理连接；不要用新机器安装命令替换已有绑定。

## 使用本 Release 的原生归档

以 Root 登录面板服务器，从 [v2026.10.29.1 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.29.1) 取得 `xboard.tar.gz` 和 `SHA256SUMS`。核对发布摘要后，在新的私有目录解包；归档中没有 vendor、运行数据或已安装插件。

```bash
set -euo pipefail
install -d -m 700 /root/xboard-upgrade-20261029-1
cd /root/xboard-upgrade-20261029-1
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.1/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.1/SHA256SUMS
sha256sum -c SHA256SUMS
mkdir -m 700 source
tar -xzf xboard.tar.gz -C source
```

替换实际站点、用户、PHP、Composer 和数据库备份路径。备份必须已完成、非空、权限为 `0600`；升级器不会生成数据库备份。锁定的 `composer.json` 与 `composer.lock` 必须与现有站点一致，不运行 `composer update` 绕过检查。

```bash
chmod 600 /root/实际数据库备份.sql
python3 -I /root/xboard-upgrade-20261029-1/source/scripts/deploy-release.py \
  --archive /root/xboard-upgrade-20261029-1/xboard.tar.gz \
  --sha256 "$(awk '$2 == "xboard.tar.gz" { print $1 }' /root/xboard-upgrade-20261029-1/SHA256SUMS)" \
  --app /www/wwwroot/实际面板目录 --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --database-backup /root/实际数据库备份.sql
```

工具会校验归档、保存源码恢复记录，再以站点用户执行锁定依赖、迁移和默认插件同步。保留 `.env`、APP_KEY、运行数据、独立上传插件、默认插件配置及管理员启停选择。未知修改过的插件会拒绝覆盖；不要删除所有权校验或用旧整份 Admin 文件绕过。

成功后按实际配置重载该站点 PHP-FPM，并重启对应队列、Node WebSocket worker，恢复唯一调度任务。新租约逻辑需要新的 PHP 长驻进程；只重载 Nginx 不会更新旧 worker。

本版在原四插件基础上加入 SubInfoCustomizer `1.0.1`；缺少该插件的站点会自动安装并启用。已独立安装官方 `1.0.0` 的站点会升级并保留原配置、模板和管理员禁用选择；已安装较新版不降级，未知修改拒绝覆盖。请在升级前确认这一新增默认行为。订阅核心桥接在升级后由插件重新校验和应用，禁用后恢复原生信息。

默认插件通过后台卸载并完整删除后，后续默认同步会重新安装并启用。希望长期停用某项时使用“禁用”，该选择会保留。已有但缺失或修改过文件的目录会拒绝覆盖，应先核对现场及备份。

本版继续保留默认首计划与设备在线修复。已经手动应用旧独立补丁工具的站点，归档升级后不要再次运行旧补丁器，它可能正确地拒绝新的文件版本。

## 节点与插件验收

已使用独立 Node `v1.13-orphan.7` 的机器无需仅为本次插件捆绑重复升级。尚未升级设备修复的节点，从 [独立 Node v1.13-orphan.7](https://github.com/ksr-v/Xboard-Node--Custom-Source/releases/tag/v1.13-orphan.7) 使用该 tag 的安装器和摘要升级。保留原绑定、Token、公共 CA 和配置，不确认新机器替换身份操作。先在一台测试节点确认正常，再逐台处理；旧节点兼容模式不提供严格的全局中心准入。

刷新管理后台，打开“订阅信息管理”，确认保存、预览和实际订阅一致；0 行/禁用应保留全部真实节点，禁用后恢复原生信息逻辑。

核对以下行为：

1. 五个默认插件（NodeEndpoint `1.0.10`、SmartExpiry `1.2.0`、QRCode Extend `1.2.0`、FlowScope `2.4.0`、SubInfoCustomizer `1.0.1`）及其他独立插件状态、配置和入口正常；已有 SubInfo 模板及禁用选择保留。新机器安装入口保持 Node `v1.13-orphan.7`。
2. 创建用户默认显示首个实际计划，手选“无”和其他计划有效；新建用户继承套餐设备限额。
3. 同一账号从不同来源 IP 连接时按限额准入；同出口 IP 的多连接仍按一个来源计数。
4. 正常关闭最后连接后在线数下降；客户端异常断网和节点失联按 keepalive/租期处理。页面在前台约每 5 秒刷新，不把它解释为所有异常断网都在 5 秒内清零。
5. 连续连接超过原 300 秒周期仍正确续期；原有用户、节点、套餐、流量和 CA 未被重置。

实际验证边界见[本版验证范围](release-2026.10.29.1-validation.zh-CN.md)。没有把已验证的 VLESS/TCP、REST 环境扩展为全部协议和生产部署承诺。

## 失败恢复

原生升级器在 `/var/backups/xboard-upgrades/` 保存 Root 私有数据库备份副本和被替换源码。应用更新开始前失败会尝试恢复源码；迁移和插件生命周期开始后失败会保留维护模式及证据，不自动把新数据库配回旧代码。

恢复时先停止使用新准入协议的节点，按对应备份恢复面板代码和数据，再恢复匹配的 Node 二进制与配置。保存问题现场，勿重新初始化站点、换 APP_KEY、重建 CA 或清空 Redis。独立补丁工具的 remove 仅适用于其精确识别的版本，不用于撤销本 Release 的任意源码。
