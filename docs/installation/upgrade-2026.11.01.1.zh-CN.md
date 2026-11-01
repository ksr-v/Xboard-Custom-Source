# 更新至 v2026.11.01.1：固定流量队列与 OPcache

本页用于已有的 aaPanel / 宝塔、Linux、PHP 8.3 原生 PHP-FPM、Nginx / OpenResty、MySQL / MariaDB 和 Redis 站点。使用本 tag 的完整原生归档；保留原数据库、`.env` / APP_KEY、插件配置与启停选择、机器身份及 CA。已有站点不重新执行 `init.sh`、`xboard:install` 或生成 APP_KEY。

本次改动与验证边界见[发行说明](../releases/v2026.11.01.1.md)和[本轮验证记录](release-2026.11.01.1-validation.zh-CN.md)。现场已验证特定站点的 Horizon 配置切换和单次正常订阅，不等于本版本完整归档已在生产升级或完成吞吐测试。

## 1. 备份并安排维护

先在站点副本演练，并安排完整应用升级的维护窗口。记录本站队列、WebSocket 与外部调度的实际服务名、用户和命令，按原维护流程暂停这些任务。备份一致性数据库、原 `.env` / APP_KEY、源码、`plugins/`、`storage/app/`、公共 CA 和 NodeEndpoint Root 状态及其外置私钥，保存至私有目录并确认可恢复。PHP CLI 与网站 FPM 使用同一版本和网站用户，不能只凭 pool 名称猜用户名。

准备已完成、非空、权限为 `0600` 的数据库备份文件。下方升级器会校验文件形态并保留私有副本，不会代为生成数据库备份，也不能仅凭文件存在证明可恢复性。不要清空 Redis、卸载插件或替换现有机器绑定。

## 2. 下载并核对本版归档

以 Root 使用本次专用的新下载目录：

```bash
set -euo pipefail
umask 077
install -d -m 700 /root/xboard-upgrade-20261101-1
cd /root/xboard-upgrade-20261101-1
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.11.01.1/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.11.01.1/SHA256SUMS
awk '$2 == "xboard.tar.gz" { print }' SHA256SUMS > xboard.archive.sha256
test "$(wc -l < xboard.archive.sha256)" -eq 1
sha256sum -c xboard.archive.sha256
mkdir -m 700 source
tar -xzf xboard.tar.gz -C source
```

校验必须显示 `xboard.tar.gz: OK` 才继续。本发行的 `SHA256SUMS` 也列出其他附件；这里只下载了原生归档，因此只核对其中唯一的 `xboard.tar.gz` 行，不直接对未下载的整张资产表执行 `sha256sum -c SHA256SUMS`。重复行、缺失行或校验失败均应停止。若 `source` 已存在，先检查此前操作，勿强行清空或混用源码。

## 3. 使用既有原生归档升级器

替换实际网站目录、网站用户、PHP、Composer 及数据库备份路径。`composer.json` 与 `composer.lock` 必须和本站依赖基线一致；不执行 `composer update` 绕过检查。

```bash
chmod 600 /root/实际数据库备份.sql
python3 -I /root/xboard-upgrade-20261101-1/source/scripts/deploy-release.py \
  --archive /root/xboard-upgrade-20261101-1/xboard.tar.gz \
  --sha256 "$(awk '$2 == "xboard.tar.gz" { print $1 }' /root/xboard-upgrade-20261101-1/xboard.archive.sha256)" \
  --app /www/wwwroot/实际面板目录 --app-user 实际网站用户 \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --database-backup /root/实际数据库备份.sql
```

该流程校验归档及依赖基线、保存被替换源码和数据库恢复副本，进入维护后，以网站用户完成既有应用更新、迁移和默认插件同步。保留原 `.env` / APP_KEY 与运行数据；未知修改过的插件仍按原所有权规则拒绝覆盖。

Root 协调器沿用既有入口和受限助手处理范围：已有有效原生入口时，默认包含相关处理；明确延期时才添加 `--skip-endpoint`，不会因此卸载已有助手。本发行未新建固定 SSH 批量升级器，使用上方本版归档中的 `deploy-release.py`，不将旧 tag 固定的脚本当作本版升级入口。

## 4. 核对 OPcache、配置缓存和长驻进程

aaPanel 不会因更新源码而自动安装 OPcache。在 **aaPanel → PHP 8.3 → 安装扩展（Install extensions）** 中实际安装并加载扩展，再按[OPcache 安装与 FPM 验证](aapanel.zh-CN.md#php83-opcache)核对 `128 MB / 16 MB / 20000`、`validate_timestamps=1`、`revalidate_freq=2`。按实际 PHP-FPM 服务的承载范围安排平滑重载；CLI 输出不代表线上 FPM 的运行缓存状态。

本版生产 `data-pipeline` 采用 `simple`，未设置 `HORIZON_DATA_PIPELINE_MAX` 时固定三个队列各一个 worker。原 `.env` 的显式覆盖继续保留；值为 `6` 时各两个，Docker 入口另有资源分配。核对配置缓存是否存在及实际进程参数，不能只看源文件。配置缓存重建会读取全部配置，应作为核对过的部署步骤处理。

源码升级成功后，按维护计划更新实际 PHP-FPM、本站 Horizon 和 WebSocket 长驻进程，恢复唯一调度并检查日志、队列与正常订阅。确认 Supervisor 为本站配置了 `autorestart=true`，使 Horizon 优雅退出后也会自动拉起。仅对已运行机器应用已验证的 Horizon 两项配置变化时，另见[配置缓存与优雅切换](aapanel.zh-CN.md#horizon-fixed-workers)；这不能代替完整归档升级的维护流程。

## 5. 失败时保留恢复证据

恢复记录位于升级器输出的 `/var/backups/xboard-upgrades/` 私有目录。应用更新前失败会尝试恢复源码；迁移或插件生命周期开始后失败会保留维护状态及证据，不自动把新数据库配回旧代码。保存日志与恢复目录，先核对数据和源码阶段，再按原恢复流程处理，不以重跑全新安装、重建 APP_KEY 或清空 Redis 恢复服务。
