# 更新至 v2026.10.25.1：限速和路由修复

本次需要升级两部分：**面板后端 `v2026.10.25.1` 与独立 xboard-node `v1.13-orphan.5`**。新版内置 NodeEndpoint 1.0.8、SmartExpiry 1.2.0、二维码审计副本 1.2.0 和 FlowScope 2.4.0。只上传插件 ZIP、只更新面板或只重启旧节点，都不能获得全部修复。无需先卸载其他插件。

本页用于已有面板。全新服务器仍按[宝塔原生安装](aapanel.zh-CN.md)或[宝塔 + Docker 安装](aapanel-docker.zh-CN.md)操作。不要对已有数据库运行 `init.sh`、`docker-install.sh` 或 `xboard:install`；保留原 `APP_KEY`、机器 ID、Token 和节点公共 CA。

## 1. 安排窗口并完成备份

先选一台测试节点升级并验证，再逐台处理业务节点。升级节点二进制时需要重启节点服务，已有连接会断开；日后正常保存用户限速可以在同一条连接上生效，路由/出站策略更新会主动关闭旧 TCP / UDP 流，使新连接采用新规则。sing-box 需要重建内核的用户身份变更也可能关闭旧流。

在宝塔暂停这一个面板的队列、WebSocket 和外部调度任务，记录原任务的名称、运行用户、工作目录和命令。不要停止其他网站或整个服务器。保存以下内容到 Root 私有目录，并验证数据库备份可以恢复：

- 一致性数据库备份，以及原 `.env` / `APP_KEY`；MySQL / MariaDB 可使用宝塔数据库备份，SQLite 应停止相关写入后备份或使用 SQLite 在线备份功能，不能直接复制正在写入的数据库文件。
- 原源码、主题、`plugins/`、`storage/app/`、公共 CA，以及原有配置和运行数据。
- NodeEndpoint Root 状态 `/var/lib/node-endpoint/`、所引用的原加密 CA 私钥与 Nginx 配置。私钥和解密材料只作 Root 私有备份，不上传面板或公开仓库。
- Docker 部署还需保存原 Compose 文件、项目名、完整容器 ID、镜像 ID和所有持久化挂载/卷；在停止容器后完成相应卷备份。不要执行 `down -v` 或清理旧镜像。

原版或自行修改过的面板应先在副本试升级。原生归档升级器要求 `composer.json` 和 `composer.lock` 与已安装面板相同；不一致会停止，需要单独测试依赖迁移。未知修改过的插件、旧二维码 core overlay 或高于内置版本的插件不会被盲目覆盖/降级。

## 2. 宝塔 / aaPanel 原生 PHP-FPM

以 Root 登录**面板服务器**。从[本次 Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.25.1)下载 `xboard.tar.gz` 和 `SHA256SUMS` 到新的 Root 私有目录，核对 Release 给出的 SHA-256：

```bash
install -d -m 700 /root/xboard-upgrade-20261025 &&
cd /root/xboard-upgrade-20261025 &&
curl -fL -o xboard.tar.gz https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.25.1/xboard.tar.gz &&
curl -fL -o SHA256SUMS https://github.com/ksr-v/Xboard-Custom-Source/releases/download/v2026.10.25.1/SHA256SUMS &&
test -s xboard.tar.gz &&
  grep -Eq '^[a-f0-9]{64}  xboard\.tar\.gz$' SHA256SUMS &&
  sha256sum --ignore-missing -c SHA256SUMS &&
mkdir -m 700 source &&
tar -xzf xboard.tar.gz -C source
```

替换真实网站、PHP、Composer 和数据库备份路径。数据库备份必须是已完成的非空普通文件且权限为 `0600`，升级器不会替你生成数据库备份。

```bash
chmod 600 /root/实际数据库备份.sql
python3 -I /root/xboard-upgrade-20261025/source/scripts/deploy-release.py \
  --archive /root/xboard-upgrade-20261025/xboard.tar.gz \
  --sha256 "$(awk '$2 == "xboard.tar.gz" { print $1 }' /root/xboard-upgrade-20261025/SHA256SUMS)" \
  --app /www/wwwroot/实际面板目录 --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --database-backup /root/实际数据库备份.sql
```

工具校验归档，进入维护模式，备份将被替换的源码，然后以 `www` 执行锁定依赖安装、数据库迁移和插件同步。它保留 `.env`、运行目录、数据库内容、公共 CA、已有插件配置和启停选择；权限按已知目录准备，不使用 `777`，Web 不获得 sudo。原 NodeEndpoint 入口只核对并补防火墙/公共 CA，不重建 CA 或改变地址。仅使用域名且有意暂不配置独立入口时，才追加 `--skip-endpoint`。

成功后在宝塔重启这个面板实际配置的 WebSocket / Octane 长驻任务，恢复队列和唯一调度任务；原生 FPM 根据本机 OPcache 设置在宝塔重载对应 PHP 版本。`xboard:update` 会通知 Horizon 退出，Supervisor 的自动拉起必须仍启用。不要套用其他网站的任务名，也不要只重载 Nginx 代替重启 PHP 长驻进程。

若只剩 Root 入口、防火墙或 CA 步骤失败，保留原状态并按具体错误定向恢复，不重新安装面板。普通代码升级无需重签证书；以前遗漏 FlowScope IPv4 路径的入口仍按[入口刷新说明](bundled-native.zh-CN.md)单独处理。

## 3. 宝塔 / aaPanel + Docker

本节适用于本仓库标准单 `xboard` 服务、应用位于 `/www`、持久化 `.env`、`storage/app`、插件和数据库目录的桥接部署。完整自动化升级流程本轮没有进行生产环境验证，应先在备份副本演练。分离服务、host-network、自定义服务名或不同 UID 的部署需要按自己的 Compose 调整，不能直接套用。

原来由 `docker-install.sh` 生成的文件为 `compose.node-endpoint.yaml`，其项目名形如 `xboard-ne-…`；手工安装可能使用 `compose.yaml`。先在宝塔 Docker 界面或容器标签核对这两个实际值，以及数据库和所有挂载确实存在。旧安装缺少 `storage/app` 持久化时，应先停止并保留旧容器、导出其中 `/www/storage/app/.`，再准备已填充的挂载目录；新增空目录会遮住旧公共 CA 和插件数据。

下面把新源码用于构建新镜像，继续使用原 Compose 与原运行卷。替换三项实际值；已有外部 Compose override 也须按原顺序保留。

```bash
PANEL_DIR=/www/wwwroot/实际面板目录
COMPOSE_FILE="$PANEL_DIR/compose.node-endpoint.yaml"
PROJECT=实际原项目名
RELEASE_DIR=/opt/xboard-build/v2026.10.25.1

install -d -m 755 /opt/xboard-build
git clone --branch v2026.10.25.1 --single-branch \
  https://github.com/ksr-v/Xboard-Custom-Source.git "$RELEASE_DIR"

docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" \
  -f "$COMPOSE_FILE" ps
```

从 `ps` 确认是目标面板后，保存旧镜像恢复标签，再创建只覆盖构建来源和镜像名的文件：

```bash
OLD_CONTAINER=$(docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" -f "$COMPOSE_FILE" ps -q xboard)
test -n "$OLD_CONTAINER" || exit 1
OLD_IMAGE=$(docker inspect --format '{{.Image}}' "$OLD_CONTAINER")
docker image tag "$OLD_IMAGE" "xboard-local-backup:${PROJECT}-20261025"

install -d -m 700 /root/xboard-upgrade-20261025-docker
cat > /root/xboard-upgrade-20261025-docker/release.compose.yaml <<EOF
services:
  xboard:
    image: xboard-independent:${PROJECT}-v2026.10.25.1
    build:
      context: $RELEASE_DIR
      dockerfile: Dockerfile
EOF
chmod 600 /root/xboard-upgrade-20261025-docker/release.compose.yaml
```

先构建成功，再停旧服务、完成一致性数据库/运行卷备份，最后重建同一个服务：

```bash
OVERRIDE=/root/xboard-upgrade-20261025-docker/release.compose.yaml
docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" \
  -f "$COMPOSE_FILE" -f "$OVERRIDE" build --pull=false xboard &&

docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" \
  -f "$COMPOSE_FILE" stop xboard
```

此时完成并核对数据库及所有运行卷备份，再执行：

```bash
docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" \
  -f "$COMPOSE_FILE" -f "$OVERRIDE" up -d --no-deps --no-build --force-recreate xboard &&

docker compose --project-name "$PROJECT" --project-directory "$PANEL_DIR" \
  -f "$COMPOSE_FILE" -f "$OVERRIDE" ps
```

新镜像的入口会在启动 Octane、Horizon 和 WebSocket 服务之前，以容器内 `www` 自动执行 `xboard:update`，迁移失败时不启动服务；无需另跑初始化。Redis 尚未启动时入口临时使用 `array` / `sync` 驱动完成这次更新，不修改 `.env`。只执行 `docker compose pull` 不会重建本地源码镜像。

确认新容器运行正常后，以宿主 Root 重新核对独立入口并绑定新的完整容器 ID：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | \
  bash -s -- --docker --app-dir "$PANEL_DIR"
```

核对计划后确认，保留原 CA / 口令 / Token。原加密 CA 私钥已经离线时，按工具提示使用 `--ca-key` 指向 Root 私有原文件。页面保存或容器替换本身不会授权新的 Root 入口。云安全组仍单独核对。

后续管理该新版容器时，始终带原项目名、原 Compose 和上述 Release override；不带 override 可能重新使用旧镜像。本流程更新容器中的应用，原宿主目录旧源码不会自动变为新版，不要对它再执行旧 `docker-install.sh`。需要统一宿主源码或迁移 Compose 归属时另做受控迁移。

## 4. 更新节点并验证

接着以 Root 登录**节点服务器**，按[独立 Node 的升级说明](https://github.com/ksr-v/Xboard-Node--Custom-Source/blob/v1.13-orphan.5/UPGRADE-v1.13-orphan.5.zh-CN.md)将测试节点升级到 `v1.13-orphan.5`：

```bash
node_script=$(curl -fsS --proto '=https' --max-time 30 --max-filesize 65536 https://raw.githubusercontent.com/ksr-v/Xboard-Node--Custom-Source/v1.13-orphan.5/install.sh) &&
  [ -n "$node_script" ] && bash -c "$node_script" -- upgrade --version v1.13-orphan.5
```

升级入口暂存并校验新二进制后替换、重启，保留原 `/etc/xboard-node/`、机器 ID、Token、公共 CA、信任 bundle、实例配置及本机 health 端口；失败时尝试从 `/etc/xboard-node/backups/recovery-*` 恢复。不要用新机器安装命令替换已有绑定，也不要确认 `REPLACE`。首次升级需要一次服务重启并中断本机连接，确认测试节点正常后再逐台更新其余节点。

在后台核对四插件版本、原插件配置、公共 CA 指纹及节点通讯地址保持正确，节点在线且 CPU / 内存状态持续更新。使用受控用户和目标地址验证：

1. 连续传输期间将用户限速设为正数、另一个正数、再设为 `0`，观察同一连接的实际吞吐变化。初始令牌突发和采样窗口会影响短时读数。
2. 修改路由或自定义出站并保存，确认旧连接结束、新连接命中新目标；调整规则顺序并验证先匹配规则优先。不要在业务窗口随意以封禁规则作验证。
3. 检查隐藏但启用的节点也收到更新；修改套餐限速时，只有选择“强制更新”才改变并同步现有用户。

WebSocket 在线节点保存后立即推送，HTTP 轮询或断线节点受轮询/重连周期影响，页面保存成功不是节点应用确认。本轮固定候选环境的 80 项 TCP 联测检查（含配置载荷核对），以及最终源码另行补测的 Linux 并发检查，见[修复报告](https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/blob/main/docs/RATE_ROUTE_FIX_REPORT.md)；不据此承诺所有公网环境和协议的固定生效时延。规则格式及事务边界见[限速与路由说明](../operations/rate-route-updates.zh-CN.md)。

## 5. 失败与回滚

原生升级器在 `/var/backups/xboard-upgrades/` 保存 Root 私有恢复记录、被替换源码和数据库备份副本。开始应用迁移/插件生命周期之前的源码复制失败会尝试恢复；开始之后失败保留维护模式及证据，不自动把新数据库配回旧代码。

Docker 启动失败先保留新旧镜像、容器诊断和所有运行卷，不执行 `down -v`。需要回退时，将 override 的 `image` 改为保存的 `xboard-local-backup:实际原项目名-20261025`，并在停服务后恢复与该镜像配套的数据库、插件和运行数据，再按同一项目重建；不要仅退镜像而沿用未经核对的新数据库。恢复后再次执行宿主 Root `--docker` 验证容器身份。

节点回退也应恢复原二进制与对应配置备份并重启该节点服务。优先保留测试节点排查；出现错误先查明确切阶段，避免重新初始化、换 APP_KEY 或重建 CA。
