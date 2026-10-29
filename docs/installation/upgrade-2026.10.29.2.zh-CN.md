# 更新至 v2026.10.29.2：Root 助手与后台登录复用

本页只覆盖本仓库的 aaPanel + Linux + PHP 8.3 + 原生 PHP-FPM + Nginx/OpenResty + MySQL/MariaDB + Redis。先更新面板，再逐台升级配套 Node `v1.13-orphan.7`。已有站点不重新执行 `init.sh`、`xboard:install` 或生成 APP_KEY。

当前隔离原生新站初始化及真实 Root 操作 `45/45`、基础探测 `4/4` 已通过；**最终发行归档升级与 CA、配置、管理员禁用状态保全仍待授权后验证**。Windows PHP 8.2 的默认插件套件通过 `23` 组，但旧 SubInfo 原包要求 PHP 8.3 的分支跳过，尚未声称 Linux PHP 8.3 整套无跳过验收。

## 保存现状

安排维护窗口，先在站点备份副本和一台测试节点演练。暂停此站点的队列、WebSocket 长驻任务和外部调度，记录实际服务名称、运行用户及命令。保存一致性数据库备份，以及原 `.env` / APP_KEY、源码、`plugins/`、`storage/app/`、公共 CA、NodeEndpoint Root 状态及其外置私钥。备份放在 Root 私有目录，确认可恢复，不上传到公开仓库。

保留原机器身份、Token、CA 和 Node 配置。升级 Node 服务会断开已有代理连接；不要用新机器安装命令替换已有绑定。

## 使用本 Release 的原生归档

以 Root 登录面板服务器，从 [v2026.10.29.2 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.29.2) 取得 `xboard.tar.gz` 和 `SHA256SUMS`。核对发布摘要后，在新的私有目录解包；归档中没有 vendor、运行数据或已安装插件。

```bash
set -euo pipefail
install -d -m 700 /root/xboard-upgrade-20261029-2
cd /root/xboard-upgrade-20261029-2
curl -fL --proto '=https' --tlsv1.2 -o xboard.tar.gz \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.2/xboard.tar.gz
curl -fL --proto '=https' --tlsv1.2 -o SHA256SUMS \
  https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.29.2/SHA256SUMS
sha256sum -c SHA256SUMS
mkdir -m 700 source
tar -xzf xboard.tar.gz -C source
```

替换实际站点、用户、PHP、Composer 和数据库备份路径。备份必须已完成、非空、权限为 `0600`；升级器不会生成数据库备份。锁定的 `composer.json` 与 `composer.lock` 必须与现有站点一致，不运行 `composer update` 绕过检查。

**下方 Root 归档升级命令默认包含入口与助手处理。** `deploy-release.py` 会调用 `install-panel.py upgrade`；不加 `--skip-endpoint` 时，会为已有有效原生 HTTPS 入口安装或更新受限本机助手，授权范围与新初始化相同：由本站网站 UID 调用固定入口任务，并受 Root 私有策略及允许端口限制。它不会仅凭网页请求创建 Root 服务。若明确延期本次入口与助手处理，应显式传入 `--skip-endpoint`；这不会卸载已有助手或改变插件禁用选择。下面的默认命令保持不跳过。

```bash
chmod 600 /root/实际数据库备份.sql
python3 -I /root/xboard-upgrade-20261029-2/source/scripts/deploy-release.py \
  --archive /root/xboard-upgrade-20261029-2/xboard.tar.gz \
  --sha256 "$(awk '$2 == "xboard.tar.gz" { print $1 }' /root/xboard-upgrade-20261029-2/SHA256SUMS)" \
  --app /www/wwwroot/实际面板目录 --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --database-backup /root/实际数据库备份.sql
```

工具会校验归档、保存源码恢复记录，再以站点用户执行锁定依赖、迁移和默认插件同步；Root 协调器按上述默认范围处理既有入口和助手。保留 `.env`、APP_KEY、运行数据、独立上传插件、默认插件配置及管理员启停选择。未知修改过的插件会拒绝覆盖；不要删除所有权校验或用旧整份 Admin 文件绕过。

成功后按实际配置重载该站点 PHP-FPM，并重启对应队列、Node WebSocket worker，恢复唯一调度任务。新租约逻辑需要新的 PHP 长驻进程；只重载 Nginx 不会更新旧 worker。

本版将 NodeEndpoint 更新至 `1.1.0`，继续捆绑 SubInfoCustomizer `1.0.1`；缺少该插件的站点会自动安装并启用。已独立安装官方 `1.0.0` 的站点会升级并保留原配置、模板和管理员禁用选择；已安装较新版不降级，未知修改拒绝覆盖。请在升级前确认这一新增默认行为。订阅核心桥接在升级后由插件重新校验和应用，禁用后恢复原生信息。

默认插件通过后台卸载并完整删除后，后续默认同步会重新安装并启用。希望长期停用某项时使用“禁用”，该选择会保留。已有但缺失或修改过文件的目录会拒绝覆盖，应先核对现场及备份。

本版继续保留默认首计划与设备在线修复。已经手动应用旧独立补丁工具的站点，归档升级后不要再次运行旧补丁器，它可能正确地拒绝新的文件版本。

## 助手核对与单独初始化的适用情形

通过本页默认 Root 归档流程升级、且已有有效原生入口时，助手会随升级安装或更新，不需要另行初始化。仅上传插件、手动更新源码，或显式使用 `--skip-endpoint` 跳过本次入口处理而助手仍未安装时，才需单独运行下方首次初始化命令。NodeEndpoint `1.1.0` 可在助手尚未安装时正常安装和启用，原有通讯入口继续工作；网页本身不会创建 Root 服务。

完成应用升级后，以管理员通过面板 HTTPS 地址打开同源 `/node-endpoint`，核对助手状态。页面自动尝试复用当前后台登录，仍由服务端检查管理员权限；没有有效后台登录时保留账号密码和手动 Bearer Token 验证。需要单独初始化时，从助手不可用区域“复制首次初始化命令”，以 Root 在面板服务器 SSH 中执行。使用该初始化命令，不用原有配置入口命令覆盖已有部署。

本版固定 Root 安装入口 `root-installer-v1.0.10`、不可变 Root tools `1.0.6` 和助手 `1.0.0`。符合上述单独初始化条件、且已有有效原生 HTTPS 入口时，可执行以下固定命令，与网页提供的首次初始化命令一致：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.10/install.sh | bash -s -- --enable-web-manager
```

若尚未建立有效 Root HTTPS 入口，先按 Root SSH 向导配置入口和信任链；助手不负责凭空创建或接管未知部署。

Root 更新会保留已有 CA、入口配置和机器身份；已有有效入口也会安装助手，不要求重建私有 CA。核对站点目录、网站用户及实际服务后完成助手绑定，再返回页面刷新状态。新面板的 Root 初始化会在五插件安装启用和入口配置完成后自动安装助手；明确使用 `--skip-endpoint` 仍会将入口与助手一起延期。不要对旧站重新运行应用全新安装。

助手可用后，先保存实际 HTTPS 设置，再生成操作预览，核对目标、根 CA 保留说明和执行阶段，输入完整确认文字后执行。更改设置或预览过期后必须重新生成计划，不会自动执行。端口须在 Root 策略批准范围内；新增允许端口通过 SSH 审阅。任务可按阶段查看，并在浏览器刷新或应用缓存清空后恢复本管理员保留的最近任务；Root 重启会将未完成任务标记中断，不自动重放。任务已被清理时页面提示记录不可用，助手仍可正常生成新预览。完成后显式同步公共 CA 与状态，再手动验证入口。失败或部分完成时保留现状和备份编号，先核对阶段结果再重试。

固定网页任务仅包括入口配置、状态刷新、服务器证书续签和入口防火墙。助手的本机授权信任边界是该站网站 UID，同一 UID 下其他进程不能视为隔离的站点。生产多站应使用专用 UID，网站 FPM 与 CLI 身份须一致；仅修改 `--app-user` 不会改变 FPM 运行用户。根 CA 轮换、私钥导出和其他 Root 操作继续通过 SSH 执行。手动上传公共 CA 与状态仍作为回退途径。

“退出本页会话”不删除主后台凭据，并暂停本标签页自动重登；需要恢复时点击“使用当前后台登录”。主后台退出、切换账号或登录过期时本页会清理凭据与 CA 口令，服务器每次请求继续核验权限。

## 节点与插件验收

已使用独立 Node `v1.13-orphan.7` 的机器无需仅为本次插件捆绑重复升级。尚未升级设备修复的节点，从 [独立 Node v1.13-orphan.7](https://github.com/ksr-v/Xboard-Node--Custom-Source/releases/tag/v1.13-orphan.7) 使用该 tag 的安装器和摘要升级。保留原绑定、Token、公共 CA 和配置，不确认新机器替换身份操作。先在一台测试节点确认正常，再逐台处理；旧节点兼容模式不提供严格的全局中心准入。

刷新管理后台，打开“订阅信息管理”，确认保存、预览和实际订阅一致；0 行/禁用应保留全部真实节点，禁用后恢复原生信息逻辑。

核对以下行为：

1. 五个默认插件（NodeEndpoint `1.1.0`、SmartExpiry `1.2.0`、QRCode Extend `1.2.0`、FlowScope `2.4.0`、SubInfoCustomizer `1.0.1`）及其他独立插件状态、配置和入口正常；已有 SubInfo 模板及禁用选择保留。新机器安装入口保持 Node `v1.13-orphan.7`。
2. 创建用户默认显示首个实际计划，手选“无”和其他计划有效；新建用户继承套餐设备限额。
3. 同一账号从不同来源 IP 连接时按限额准入；同出口 IP 的多连接仍按一个来源计数。
4. 正常关闭最后连接后在线数下降；客户端异常断网和节点失联按 keepalive/租期处理。页面在前台约每 5 秒刷新，不把它解释为所有异常断网都在 5 秒内清零。
5. 连续连接超过原 300 秒周期仍正确续期；原有用户、节点、套餐、流量和 CA 未被重置。
6. Root 助手初始化后显示已连接；预览需输入完整确认文字，任务阶段与备份编号可见，公共 CA 可显式校验同步。现有 CA 与禁用选择保持不变。

实际验证边界见[本版验证范围](release-2026.10.29.2-validation.zh-CN.md)。没有把已验证的 VLESS/TCP、REST 环境扩展为全部协议和生产部署承诺。

## 失败恢复

原生升级器在 `/var/backups/xboard-upgrades/` 保存 Root 私有数据库备份副本和被替换源码。应用更新开始前失败会尝试恢复源码；迁移和插件生命周期开始后失败会保留维护模式及证据，不自动把新数据库配回旧代码。

恢复时先停止使用新准入协议的节点，按对应备份恢复面板代码和数据，再恢复匹配的 Node 二进制与配置。保存问题现场，勿重新初始化站点、换 APP_KEY、重建 CA 或清空 Redis。独立补丁工具的 remove 仅适用于其精确识别的版本，不用于撤销本 Release 的任意源码。
