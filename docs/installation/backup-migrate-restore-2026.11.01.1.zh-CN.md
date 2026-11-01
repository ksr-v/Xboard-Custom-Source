# 备份、迁移与还原 v2026.11.01.1：宝塔 / aaPanel / PHP-FPM

本页说明如何把已有面板备份到新服务器：先在新服务器安装同版空面板，保留它作回退，再把旧数据还原到另一个空数据库，恢复业务文件、原 APP_KEY 和节点通讯信任，最后切换服务。

适用范围为 Linux、Nginx、PHP 8.3 原生 PHP-FPM、MySQL / MariaDB、Redis，以及本仓库 `v2026.11.01.1`。目标环境准备见[全新安装教程](install-2026.11.01.1.zh-CN.md)。跨版本迁移先在副本中**恢复到源站相同版本**，确认可用后再按[升级教程](upgrade-2026.11.01.1.zh-CN.md)更新；不要将旧版 `app/`、`public/` 或未知核心补丁直接覆盖到不同版本。

**SQL 只是备份的一部分。** 数据库保存用户、套餐、订单、机器、设置和插件记录；原 `.env` / APP_KEY、上传和插件私有文件、实际插件源码，以及网站外的 Root CA / 私钥也需要保留。新的 APP_KEY 不能解密旧数据库中的 CA 托管口令。

本次跨机演练的参数与结果见[脱敏验证记录](../verification/backup-migrate-restore-20261101.json)。最终通过项目以该记录为准；文末单列测试范围，不把手册步骤当作已完成的测试。

## 1. 记录两台机器和操作参数

先记录源站版本、数据库版本与字符集、PHP CLI / FPM、网站用户、应用目录、Nginx 配置、计划任务、Horizon / WS 守护命令、插件版本与启停选择，以及当前节点通讯地址和 CA 指纹。

下面变量在**各自服务器的 Root SSH** 中设置。宝塔常见值是 `www`、`/www/server/php/83/bin/php` 和 `/www/server/nginx/sbin/nginx`；以本站实际值为准。不要在两台机器间复制数据库或 SSH 密码到命令参数。

| 参数 | 源服务器 | 目标服务器 |
| --- | --- | --- |
| 应用根目录，包含 `artisan` | `xboard_app` | `xboard_app` |
| 网站 / FPM 用户 | `xboard_user` | `xboard_user` |
| PHP CLI | `xboard_php` | `xboard_php` |
| 实际 Nginx 二进制 | `xboard_nginx` | `xboard_nginx` |
| 源数据库 | `xboard_db` | 不连接源数据库 |
| 新装时建立的数据库 | 不适用 | `xboard_fresh_db`，保留作回退 |
| 接收旧数据的另一个空库 | 不适用 | `xboard_restore_db` |
| 私有工作目录 | `xboard_backup` | `xboard_restore` |

源服务器示例，先替换路径和数据库名：

```bash
set -euo pipefail
umask 077
xboard_app='/www/wwwroot/实际面板目录'
xboard_user='www'
xboard_php='/www/server/php/83/bin/php'
xboard_nginx='/www/server/nginx/sbin/nginx'
xboard_db='实际源数据库名'
xboard_backup='/root/xboard-migration-20261101-1'
test -f "$xboard_app/artisan"
test ! -e "$xboard_backup"
install -d -o root -g root -m 700 "$xboard_backup"
```

目标服务器稍后单独设置自己的变量。所有备份、解包目录和数据库客户端配置都放在网站之外；本页的 `/root/` 目录不作为网页下载地址。

本页 Root CA 恢复采用原布局：应用绝对路径、网站用户名、PHP 路径，以及 Root 状态、受管理 Nginx 配置的路径保持一致。UID / GID 以目标真实用户重新核对，助手授权在目标重建。不同 FPM socket、网站配置、include 目录或 Docker 布局应先单独调整并验证，不能靠修改 Root JSON 假装匹配。

## 2. 源站停止新增写入，取得一致性备份

### 2.1 暂停写入和调度，处理已接收的队列

安排维护窗口，保存实际服务名称和原运行状态，依次完成：

1. 在宝塔暂停本站唯一的每分钟 `schedule:run` 计划任务；若由 cron / `schedule:work` 管理，暂停对应本站任务。
2. 通过本站入口的受控隔离阻止新的业务写入，包括节点上报、回调和 WS 写入；保留应用运行以处理队列。按实际入口检查，不能只看浏览器维护页。
3. 保持 Horizon 处理已经接收的任务，确认本站待执行队列处理完成；处理不了的任务保留并单独记录，不把 Redis 队列当成已包含在 SQL 中。
4. 排空后再执行下方 `artisan down`，停止本站 Horizon 和 WS 的守护任务，防止 Supervisor 自动拉起；停服务后再导出最终数据库。
5. 确认当前 Root 管理任务已结束，再停止 `nodeendpoint-web-manager.service`，避免备份期间还有任务修改证书或入口。

进入维护的应用命令须以网站用户执行：

```bash
cd "$xboard_app"
runuser -u "$xboard_user" -- "$xboard_php" artisan down
```

在 Supervisor / 宝塔中停止**实际属于本站**的守护任务。不要把 `horizon:terminate` 当成永久停止：设置了 `autorestart=true` 时，它会重新启动。

默认队列 worker 在维护模式下会暂停领取新任务，不能先 `down` 再等默认 Horizon 排空。本次隔离演练在维护后用受控的 `queue:work --force --stop-when-empty` 处理了 7 个合成节点同步任务；正式站点优先按上面的“隔离新写入 → 排空 → 维护停服”顺序，不盲目强制执行通知或外部业务任务。

Redis 中的待执行任务、在线状态、会话和缓存不属于 SQL。正式切换以处理完待执行业务为前提；目标使用自己的 Redis，不清空源 Redis，不执行 `FLUSHALL`。在演练中，源 / 目标也不能共享同一 Horizon 命名空间，以免 `xboard:update` 的 `horizon:terminate` 影响另一台机器。

### 2.2 创建私有数据库客户端配置

选择与数据库相匹配的 `mariadb` / `mariadb-dump` 或 `mysql` / `mysqldump`。以有权导出本站表及相关对象的账号创建私有配置，密码写入文件，不使用 `-p密码`：

```bash
install -o root -g root -m 600 /dev/null "$xboard_backup/mysql-source.cnf"
vi "$xboard_backup/mysql-source.cnf"
```

文件内容示意，填写源站真实连接值；密码含引号或反斜杠时使用客户端配置格式正确转义：

```ini
[client]
host=127.0.0.1
port=3306
user=实际备份账号
password="实际数据库密码"
default-character-set=utf8mb4
```

使用 socket 的站点改填实际 `socket=`，不要同时猜测另一套数据库服务。

```bash
xboard_sql="$(command -v mariadb || command -v mysql)"
xboard_dump="$(command -v mariadb-dump || command -v mysqldump)"
"$xboard_sql" --defaults-extra-file="$xboard_backup/mysql-source.cnf" \
  --database="$xboard_db" --batch --skip-column-names \
  -e 'SELECT VERSION(); SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE(); SELECT ENGINE, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() GROUP BY ENGINE;' \
  > "$xboard_backup/database-environment.txt"
```

原生表以事务表为基线。若有非事务表、外部写入者、自定义存储过程 / 事件或不同版本数据库，先核对一致性、`DEFINER` 账号与兼容性。特别是自定义数据库事件，目标不得在数据验证前自动执行；不要用导出成功代替这项检查。

### 2.3 导出最终 SQL 并保存数据核对副本

```bash
xboard_dump_optional=()
if "$xboard_dump" --help 2>/dev/null | grep -F -- '--no-tablespaces' >/dev/null; then
  xboard_dump_optional+=(--no-tablespaces)
fi
if "$xboard_dump" --help 2>/dev/null | grep -F -- '--set-gtid-purged' >/dev/null; then
  xboard_dump_optional+=(--set-gtid-purged=OFF)
fi
"$xboard_dump" --defaults-extra-file="$xboard_backup/mysql-source.cnf" \
  "${xboard_dump_optional[@]}" --single-transaction --quick --hex-blob \
  --default-character-set=utf8mb4 --routines --triggers --events "$xboard_db" \
  | gzip -c > "$xboard_backup/database.sql.gz"
test -s "$xboard_backup/database.sql.gz"
gzip -t "$xboard_backup/database.sql.gz"
```

`--single-transaction` 配合已停止写入取得事务表的一致性快照。数据库客户端的权限或参数错误必须处理后重做，不能忽略退出码、跳过表或把空文件当成备份。

另存关键业务表的有序数据导出，用于目标导入后、首次应用更新前逐字节核对。源 / 目标使用相同版本的 dump 客户端及相同参数；文件包含敏感业务值，只保存在 Root 私有目录：

```bash
"$xboard_dump" --defaults-extra-file="$xboard_backup/mysql-source.cnf" \
  "${xboard_dump_optional[@]}" \
  --single-transaction --quick --hex-blob --no-create-info --skip-comments \
  --compact --skip-extended-insert --complete-insert --order-by-primary \
  "$xboard_db" v2_user v2_plan v2_order v2_server_machine v2_server v2_plugins \
  > "$xboard_backup/key-data.sql"
sha256sum "$xboard_backup/key-data.sql" > "$xboard_backup/key-data.sha256"
```

这份比较不能替代其他表的核验。记录全库表清单、各表行数，以及实际需要保留的设置、插件专用表与统计数据；本次演练的完整核对范围见验证记录。需要发布结果时只发布数量、摘要和布尔结果，不发布用户记录、密码或 Token。

## 3. 备份同版运行文件及 Root 信任

### 3.1 同版应用运行快照

除了 `.env`、插件和私有存储，本页保存同版 `app/`、`public/` 等实际运行资源。原因是插件启停会修改自己的 Admin / 订阅桥接；目标全新安装默认启用五插件，只恢复一条“禁用”数据库记录，不会自动清除目标 fresh 已存在的桥接。

下面列表对应本版原生布局；有其他上传目录、自定义主题资源或额外挂载时，另行列入备份清单并验证恢复。源站需要先确认版本和依赖锁与准备恢复的版本相同。

```bash
xboard_paths=(.env artisan composer.json composer.lock app bootstrap config database routes resources public plugins plugins-core theme storage/app)
if test -d "$xboard_app/storage/qrcodeextend"; then
  xboard_paths+=(storage/qrcodeextend)
fi
printf '%s\n' "${xboard_paths[@]}" > "$xboard_backup/app.paths"
tar --acls --xattrs --numeric-owner \
  --exclude='bootstrap/cache/*' --exclude='public/storage' \
  -czpf "$xboard_backup/app-runtime.tar.gz" -C "$xboard_app" "${xboard_paths[@]}"
```

该包保留源站原 APP_KEY、实际插件源码、插件所有权记录、公共 CA、二维码私有数据和源站同版桥接状态。它不包含 `vendor/`、`.git/`、会话、日志、已编译缓存或网站外私钥。`public/storage` 在目标重新创建。

不要为了迁移而禁用 / 卸载插件。特别是 QRCode Extend 的正常 cleanup 会删除 `storage/qrcodeextend`；停止队列和网站写入即可，原插件选择按数据库及对应运行快照保留。

### 3.2 保存完整 Root 状态和实际 Nginx 配置

已经配置独立 HTTPS 节点入口时，需要完整备份 `/var/lib/node-endpoint`；工具的 `backup/rollback` 只是入口事务快照，`export-ca` 也只是 CA 导出，二者都不能代替本节。

记录 Root 安装计划，并取得它实际拥有的配置路径。以下只读取 Root 记录，不输出密钥：

```bash
test -f /var/lib/node-endpoint/installation.json
python3 - "$xboard_backup" <<'PY'
import json, pathlib, sys
destination = pathlib.Path(sys.argv[1])
installed = json.loads(pathlib.Path('/var/lib/node-endpoint/installation.json').read_text())
plan = installed['plan']
required = ('app', 'app_user', 'php', 'host', 'port', 'deployment', 'backend', 'source_site', 'site_name', 'include_dir', 'config_path', 'main_config')
if any(key not in plan for key in required):
    raise SystemExit('Root installation plan is incomplete')
for key in ('config_path', 'source_site'):
    value = pathlib.Path(plan[key])
    if not value.is_absolute() or '..' in value.parts or not value.is_file():
        raise SystemExit('Root configuration path is invalid')
(destination / 'root-plan.json').write_text(json.dumps({key: plan[key] for key in required}, indent=2) + '\n')
(destination / 'root-config.paths').write_text(plan['config_path'] + '\n' + plan['source_site'] + '\n')
PY
install -d -o root -g root -m 700 "$xboard_backup/root-staging/var/lib"
cp -a /var/lib/node-endpoint "$xboard_backup/root-staging/var/lib/node-endpoint"
while IFS= read -r xboard_conf; do
  install -D -o root -g root -m 644 "$(realpath -- "$xboard_conf")" \
    "$xboard_backup/root-staging/${xboard_conf#/}"
done < "$xboard_backup/root-config.paths"
xboard_root_paths=(var/lib/node-endpoint)
while IFS= read -r xboard_conf; do
  xboard_root_paths+=("${xboard_conf#/}")
done < "$xboard_backup/root-config.paths"
tar --acls --xattrs --numeric-owner -czpf "$xboard_backup/root-state.tar.gz" \
  -C "$xboard_backup/root-staging" "${xboard_root_paths[@]}"
```

此处把选中的 Nginx 配置解引用保存为 Root 所有的普通文件，避免迁移一个在目标失效的 `sites-enabled` 符号链接。Root 包包含原 CA、加密 CA 私钥、服务器私钥、托管口令元数据、隐藏所有权标记及入口配置；全程保持私有。

网站配置如果引用其他 include、网站 SSL 证书 / 私钥或自定义文件，也要在清单中单独记录并私下备份，或在目标按原域名重新签发。不要归档或覆盖新机器整个 `/etc/nginx`。普通网站 HTTPS 与 NodeEndpoint 私有 CA 是两套信任关系。

助手的 `/etc/nodeendpoint-web-manager.json`、`/var/lib/nodeendpoint-web-manager` 任务记录和 `/run/nodeendpoint-web-manager/control.sock` 不从源站迁移。目标重新生成本机助手授权和 socket。

## 4. 核验并私下传输备份

```bash
cd "$xboard_backup"
sha256sum database.sql.gz app-runtime.tar.gz app.paths key-data.sql key-data.sha256 \
  database-environment.txt root-state.tar.gz root-plan.json root-config.paths > SHA256SUMS
sha256sum -c SHA256SUMS
```

先在目标创建新的 Root 私有接收目录，例如 `/root/xboard-restore-20261101-1/incoming`，再用已核对 SSH 主机指纹的 SCP / SFTP 传输上述文件和 `SHA256SUMS`。不传源数据库客户端密码文件，不使用网页公开下载：

```bash
scp database.sql.gz app-runtime.tar.gz app.paths key-data.sql key-data.sha256 \
  database-environment.txt root-state.tar.gz root-plan.json root-config.paths SHA256SUMS \
  root@实际新服务器:/root/xboard-restore-20261101-1/incoming/
```

在目标接收目录执行 `sha256sum -c SHA256SUMS`，所有项目必须通过。备份到达目标且摘要一致仍不代表已经可恢复；后面的导入和验收才是恢复验证。

## 5. 目标先全新安装，保留 fresh 回退环境

按[本版全新安装教程](install-2026.11.01.1.zh-CN.md)完成新空站、同版归档、PHP / OPcache、网站配置和 `init.sh`。在新装阶段使用独立的 fresh 数据库及目标本地 Redis，确认能登录；不要把安装器连到源站数据库。

新装完成后，先不建立目标 cron 或启动 Horizon / WS；已有守护任务则停止。目标也保持维护，停止 `nodeendpoint-web-manager.service`。记录 fresh 管理员初始信息和实际路径，保存 fresh 数据库、`.env`、原文件目录以及 fresh Root 状态作为回退。

在宝塔新建**另一个专门的空数据库**及账号用于恢复，字符集 / 排序规则与源库兼容。这个库必须没有表。原 fresh 数据库保持原样，不在其中混合导入旧 SQL，也不向源数据库写入。

目标参数示例，按目标实际值替换：

```bash
set -euo pipefail
umask 077
xboard_app='/www/wwwroot/实际面板目录'
xboard_user='www'
xboard_php='/www/server/php/83/bin/php'
xboard_nginx='/www/server/nginx/sbin/nginx'
xboard_fresh_db='实际fresh数据库名'
xboard_restore_db='实际另建的空还原库名'
xboard_restore='/root/xboard-restore-20261101-1'
test -f "$xboard_app/artisan"
test "$xboard_fresh_db" != "$xboard_restore_db"
test -d "$xboard_restore/incoming"
test ! -e "$xboard_restore/fresh-files"
install -d -o root -g root -m 700 "$xboard_restore/fresh-files"
cd "$xboard_restore/incoming"
sha256sum -c SHA256SUMS
xboard_sql="$(command -v mariadb || command -v mysql)"
xboard_dump="$(command -v mariadb-dump || command -v mysqldump)"
xboard_dump_optional=()
if "$xboard_dump" --help 2>/dev/null | grep -F -- '--no-tablespaces' >/dev/null; then
  xboard_dump_optional+=(--no-tablespaces)
fi
if "$xboard_dump" --help 2>/dev/null | grep -F -- '--set-gtid-purged' >/dev/null; then
  xboard_dump_optional+=(--set-gtid-purged=OFF)
fi
```

仿照源站，在 `$xboard_restore/mysql-fresh.cnf` 和 `$xboard_restore/mysql-restore.cnf` 分别保存目标 fresh 库与还原库的连接，权限 `0600`；也可使用有权访问这两个专用库的受控备份账号。这里的主机、用户和密码是目标值，不沿用源数据库连接。

先导出 fresh 库并保留目标原 `.env`：

```bash
test ! -e "$xboard_restore/fresh-database.sql.gz"
test ! -e "$xboard_restore/fresh.env"
"$xboard_dump" --defaults-extra-file="$xboard_restore/mysql-fresh.cnf" \
  "${xboard_dump_optional[@]}" \
  --single-transaction --quick --hex-blob "$xboard_fresh_db" \
  | gzip -c > "$xboard_restore/fresh-database.sql.gz"
gzip -t "$xboard_restore/fresh-database.sql.gz"
install -o root -g root -m 600 "$xboard_app/.env" "$xboard_restore/fresh.env"
```

fresh 数据库不删除；该 SQL 和原目录用于失败排查与回退。Root 入口的 fresh 回退另见第 8 节，在恢复前完成。

## 6. 导入到专用空库，先核对原始数据

```bash
xboard_table_count="$("$xboard_sql" --defaults-extra-file="$xboard_restore/mysql-restore.cnf" \
  --database="$xboard_restore_db" --batch --skip-column-names \
  -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();')"
test "$xboard_table_count" -eq 0
gzip -dc "$xboard_restore/incoming/database.sql.gz" \
  | "$xboard_sql" --defaults-extra-file="$xboard_restore/mysql-restore.cnf" \
      --database="$xboard_restore_db"
```

导入中断时停止后续步骤，保留日志；不要改向 fresh 库或源库重试。确认失败导入库状态后，另建新的专用空库重新导入，避免把半成品当成干净库。

首次应用更新之前，用与源站相同的 dump 客户端和参数导出关键表再比较：

```bash
"$xboard_dump" --defaults-extra-file="$xboard_restore/mysql-restore.cnf" \
  "${xboard_dump_optional[@]}" \
  --single-transaction --quick --hex-blob --no-create-info --skip-comments \
  --compact --skip-extended-insert --complete-insert --order-by-primary \
  "$xboard_restore_db" v2_user v2_plan v2_order v2_server_machine v2_server v2_plugins \
  > "$xboard_restore/restored-key-data.sql"
cmp "$xboard_restore/incoming/key-data.sql" "$xboard_restore/restored-key-data.sql"
```

还要按源站表清单核对所有表及实际业务指标。后续操作会有意更新站点入口设置、版本缓存或迁移记录，因此应保留**导入后、应用更新前**的比较结果，再单独记录这些预期变化。

## 7. 恢复同版文件与原 APP_KEY，再执行应用更新

将目标即将被替换的对应目录移到 Root 回退目录，再解开已经核验的同版运行包。原 fresh 内容保持可找回，避免新装资源与旧运行资源混杂：

```bash
while IFS= read -r xboard_relative; do
  case "$xboard_relative" in
    .env|artisan|composer.json|composer.lock|app|bootstrap|config|database|routes|resources|public|plugins|plugins-core|theme|storage/app|storage/qrcodeextend) ;;
    *) printf '%s\n' 'app.paths 出现本教程范围外路径，停止并审阅'; exit 1 ;;
  esac
  if test -e "$xboard_app/$xboard_relative" || test -L "$xboard_app/$xboard_relative"; then
    install -d -o root -g root -m 700 "$xboard_restore/fresh-files/$(dirname -- "$xboard_relative")"
    test ! -e "$xboard_restore/fresh-files/$xboard_relative"
    mv -- "$xboard_app/$xboard_relative" "$xboard_restore/fresh-files/$xboard_relative"
  fi
done < "$xboard_restore/incoming/app.paths"
tar --acls --xattrs --numeric-owner -xzpf "$xboard_restore/incoming/app-runtime.tar.gz" -C "$xboard_app"
```

编辑恢复后的 `.env`：

- **保留源站原 `APP_KEY`、`INSTALLED=true` 和业务配置。** 不使用 fresh 的新 APP_KEY。
- `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD` 改为目标专用还原库的连接。
- Redis 连接改为目标本地独立实例 / 数据库及正确前缀。也检查 `REDIS_URL`、`DB_URL` 等可能覆盖分项配置的连接串，不留下源站远程连接。
- `APP_URL`、会话域名等按实际目标网站调整；面板后台数据库中另存的网站地址，也需在登录后核对。
- 保持 `APP_ENV=production`，核对原有 Horizon 环境覆盖。

不要在 SQL 中全局替换旧 IP 或域名。机器 Token、用户 UUID、订阅 Token、加密凭据及 CA 元数据按原值保留，后面的入口命令会有针对性地保存新地址。

以 Root 在应用根目录调用本版已校验的更新协调器，它会恢复站点用户权限、安装锁定依赖、执行迁移并同步默认插件；此时显式延期 Root 入口处理：

```bash
cd "$xboard_app"
bash update.sh --deployed-code --skip-endpoint \
  --app-user "$xboard_user" --php "$xboard_php" \
  --composer /opt/xboard-tools/composer-2.10.3.phar
runuser -u "$xboard_user" -- "$xboard_php" artisan config:clear
runuser -u "$xboard_user" -- "$xboard_php" artisan route:clear
runuser -u "$xboard_user" -- "$xboard_php" artisan view:clear
runuser -u "$xboard_user" -- "$xboard_php" artisan storage:link
```

Composer PHAR 路径沿用目标安装时已核验的值。不要执行 `composer update`，不通过 `--enable-all` 改变源站原禁用选择。若插件数据库版本高于现有插件源码、来源未知或有不兼容核心修改，先恢复匹配版本并排查，不强行降级或绕过所有权检查。

`update.sh` 会处理文件权限；应用 CLI 仍由网站用户运行，Root PKI 保持网站外且由 Root 所有。不要对整台机器或 Root 私钥使用网站用户的递归 `chown`，也不设 `777`。

还原后不再运行 `init.sh`、`xboard:install` 或 `key:generate`。目标原来的 compiled configuration 不应继承；本页清理的是目标编译缓存，不清空 Redis 业务队列。

## 8. 恢复原 CA，为新通讯地址签发服务器证书

### 8.1 先保留目标 fresh Root 状态

在修改目标 Root 状态之前，保存其 fresh `/var/lib/node-endpoint`、实际 managed Nginx 配置、fresh 网站配置及 helper policy。路径从目标 fresh 的 `installation.json` 读取，不能猜为另一个站点。以下在目标已停止 helper、且确认只涉及本次新站时执行：

```bash
test ! -e "$xboard_restore/fresh-root-plan.json"
test ! -e "$xboard_restore/fresh-root-config.paths"
test ! -e "$xboard_restore/fresh-root.tar.gz"
python3 - "$xboard_restore" <<'PY'
import json, pathlib, sys
destination = pathlib.Path(sys.argv[1])
installed = json.loads(pathlib.Path('/var/lib/node-endpoint/installation.json').read_text())
plan = installed['plan']
(destination / 'fresh-root-plan.json').write_text(json.dumps(plan, indent=2) + '\n')
(destination / 'fresh-root-config.paths').write_text(installed['config_path'] + '\n' + plan['source_site'] + '\n')
PY
xboard_fresh_root_paths=(var/lib/node-endpoint)
while IFS= read -r xboard_conf; do
  test -f "$xboard_conf"
  xboard_fresh_root_paths+=("${xboard_conf#/}")
done < "$xboard_restore/fresh-root-config.paths"
for xboard_optional in /etc/nodeendpoint-web-manager.json /etc/systemd/system/nodeendpoint-web-manager.service /var/lib/nodeendpoint-web-manager; do
  if test -e "$xboard_optional"; then
    xboard_fresh_root_paths+=("${xboard_optional#/}")
  fi
done
tar --acls --xattrs --numeric-owner --dereference \
  -czpf "$xboard_restore/fresh-root.tar.gz" -C / "${xboard_fresh_root_paths[@]}"
tar -tzf "$xboard_restore/fresh-root.tar.gz" > "$xboard_restore/fresh-root.members"
```

这些归档保持私有，先完成下一节的兼容性核对再移动 fresh 文件。Nginx 保持运行，管理器需要识别实际 master 与 FPM 后端。

### 8.2 按源布局恢复 Root 状态及配置

核对接收包中的 `root-plan.json` 与目标实际环境：

- `app`、`app_user`、`php` 与目标实际目录、FPM 身份一致。
- `config_path` 和 `include_dir` 在目标仍是同一路径，且被 Nginx HTTP 层 include。
- `source_site`、`site_name`、FPM `backend` 对应目标实际可运行的网站。
- 网站 listen 不绑定已不存在的旧本机 IP；证书、include 和 FPM socket 等引用都能在目标解析。

目标 fresh 布局不同，可以在保存 fresh 配置后还原源布局及其所需文件，再使用正式入口修复。不要把旧 `installation.json` 的路径字段改成 fresh 值来绕过校验；管理器还会检查原 managed 配置的准确 hash 和 ownership。

满足以上条件，确认所有待移动配置只属于本次目标新站后，保留 Nginx 运行，先移动 fresh 状态及这两份配置，立即恢复已核验的源 Root 包。暂不在两者之间重载 Nginx：

```bash
test ! -e "$xboard_restore/fresh-root-state"
mv /var/lib/node-endpoint "$xboard_restore/fresh-root-state"
while IFS= read -r xboard_conf; do
  install -d -o root -g root -m 700 "$xboard_restore/fresh-root-configs/$(dirname -- "${xboard_conf#/}")"
  mv -- "$xboard_conf" "$xboard_restore/fresh-root-configs/${xboard_conf#/}"
done < "$xboard_restore/fresh-root-config.paths"
tar --acls --xattrs --numeric-owner -xzpf "$xboard_restore/incoming/root-state.tar.gz" -C /
"$xboard_nginx" -t
```

此包只包含第 3 节记录的明确 Root 状态与两份站点配置，不是整个系统目录。保持 Root 目录 `0700`、私钥 / 私有元数据 `0600`、选中的 Nginx 配置 `0644`。旧叶证书暂时仍包含旧地址；目标此时仍不对外切换。

如果 fresh 网站使用 `listen 新IP:8080`，恢复的网站改为 `listen 8080`，Nginx 热重载可能因同端口重新绑定而失败。`nginx -t` 和发送 reload 成功不代表新 worker 已接管；还要核对实际监听、worker 换代、错误日志和线上证书。出现 `bind() ... Address already in use` / `still could not bind()` 时，在目标维护窗口按实际服务方式重启 Nginx，再继续入口修复；多站服务器先核对影响范围。本次独立目标机正是在恢复原布局后，通过受控重启解决该问题。不要通过关闭 TLS 校验跳过旧进程仍在提供 fresh 证书的问题。

### 8.3 通过本版固定工具修复地址

入口管理器会用本机 API 请求探测应用，维护模式的 `503` 会导致重签事务失败。确认数据库、文件和配置检查通过后，**保持目标未切 DNS、限制目标只供本机 / 受控测试来源访问、调度与 Horizon / WS 仍停止**，先解除目标应用维护供探测；源站继续保持维护：

```bash
cd "$xboard_app"
runuser -u "$xboard_user" -- "$xboard_php" artisan up
```

本版 fresh 安装已建立 Root 所有的固定工具缓存。随后以 Root 在交互式 SSH 中执行，替换最后一行的**实际新节点通讯 IP / 域名**；`--nginx` 使用目标实际二进制：

```bash
python3 -I /opt/xboard-root-tools/c50868e3fa53c64143fa4529769fb4df05013b258622369d9d5b6df4f82907d1/quick_install.py \
  --package-file "$xboard_app/root-assets/node-endpoint-tools-1.0.6.zip" \
  --nginx "$xboard_nginx" \
  --repair-address --host 实际新通讯IP或域名
```

核对完整计划，按提示输入 `INSTALL 实际新地址:实际端口`。这会通过恢复的 SQL 与原 APP_KEY 取得旧托管口令，**沿用原 CA**，为新地址签发服务器证书、更新受管理入口、保存插件通讯设置并同步原公共 CA。本机端口规则与云安全组分别核对；明确保持已配置本机规则时可加 `--skip-firewall`。

`--repair-address` 沿用源计划中的网站路径、名称及后端。布局不同而未恢复源布局时，需使用同一官方 `manager.py modify` 显式审阅新站参数，再执行应用 `xboard:configure-endpoint` 和 `--sync-ca`；不在本页把未知布局当成自动兼容。

原 CA 私钥已离线时，另从受保护的离线备份恢复可用加密私钥并通过官方管理器的 `--ca-key` 明确提供。本页固定 bootstrap 的原生 FPM 地址修复分支不转发该选项，不能仅给上述命令增加参数就假定已覆盖离线密钥场景。

### 8.4 在目标重新安装助手授权

使用 fresh 初始化时已核验的 helper 缓存，在目标生成当前 UID / GID 和恢复后入口的授权：

```bash
python3 -I /opt/nodeendpoint-web-bootstrap/9afb5f8b774600ce1578bebf067a3b1d99e90ab3f7b5245523181ba29cdd0f2a/web_manager.py install \
  --app "$xboard_app" --app-user "$xboard_user" --php "$xboard_php" \
  --nginx "$xboard_nginx" --confirm 'ENABLE WEB MANAGEMENT'
```

也可使用前述固定 bootstrap 的 `--enable-web-manager`，在 SSH 中按提示输入 `ENABLE WEB MANAGEMENT`；该路线重新下载并核验固定 helper。

不要复制源 helper 的 policy、旧任务或 socket。目标存在未结束的 Root 管理任务时先核对，不覆盖正在执行的任务。安装器会重写 `/etc/nodeendpoint-web-manager.json` 和服务，socket 由新进程创建；既检查服务运行，也等待 `/run/nodeendpoint-web-manager/control.sock` 就绪并实际读取助手状态。

## 9. 验收通过后再切换与恢复唯一服务

先限制目标访问，只对目标地址或使用原域名的 `--resolve` 做检查；浏览器网站证书按实际域名配置。核对以下内容：

| 项目 | 需要确认的结果 |
| --- | --- |
| 数据库 | 导入后的表集合、关键表有序摘要、全库行数及业务数量符合源备份；预期迁移变化有记录 |
| 原管理员 | 原账号和密码可登录；不是目标 fresh 的新管理员 |
| 用户与账务 | 用户 UUID / Token、套餐、余额、流量、有效期、订单等保留；只在私有比较中使用真实值 |
| 机器与节点 | 机器 / 节点 ID、认证 Token 和绑定保持；用原身份对目标发起真实认证请求 |
| 插件 | 版本、配置和原启停选择符合备份；原禁用插件的 Admin / 核心桥接没有 fresh 残留 |
| 文件 | 上传、自定义资源、二维码私有文件和公共 CA 的摘要符合备份 |
| CA 与 TLS | 原 CA 指纹不变；旧密文仍能解密；新叶证书验证新通讯地址，实际 TLS 链路可信 |
| 助手 | 当前 UID / GID 授权正确，进程和新 socket 就绪，页面能读取状态 |
| 订阅 | 原用户实际获取订阅，节点、UUID、流量 / 到期信息正确，并验证实际连接 |
| 调度与队列 | 目标唯一每分钟调度和 Horizon 正常，日志无持续错误；源站不再并行写同一业务 |

不要为了核验而把原 Token、APP_KEY 或托管 CA 口令打印到日志。CA 凭据中原先绑定的 host 可能仍为源地址，它标识原 CA 凭据，不能全库替换成新 IP。

确认数据和入口后，按[新装教程](install-2026.11.01.1.zh-CN.md)为目标配置唯一计划任务与 Supervisor / Horizon。保留 `APP_ENV=production`，核对本版 `data-pipeline` 默认三个队列各一个 worker；业务和通知队列另有进程。网站、Horizon、WS 必须读取恢复后的配置，不能继续使用 fresh 进程中的旧配置。

确认目标应用保持可用，再以网站用户检查服务。若验证期间重新进入了维护，检查通过后解除：

```bash
cd "$xboard_app"
runuser -u "$xboard_user" -- "$xboard_php" artisan up
runuser -u "$xboard_user" -- "$xboard_php" artisan horizon:status
runuser -u "$xboard_user" -- "$xboard_php" artisan schedule:list
```

`schedule:list` 只证明调度表可加载，还要确认下一分钟确有自动执行记录。根据实际部署启动并验证 WS；未启用 WS 的站点可继续 REST。

最后再切换面板域名 DNS / 负载入口。独立 Node 若保存的是旧 IP，需通过已有实例的配置 / 迁移入口更换面板地址，保留原身份、Token 和 CA；不使用新机器安装命令或 `REPLACE` 去重建已运行节点。FlowScope 采集器有自己的面板地址和本地状态，也需单独核对，不会自动跟随 Node 的地址改变。

在确认目标运行及后续备份可恢复之前，保留源服务器、旧数据库和私有备份。正式切换后只保留一侧接受业务写入；不要让旧站调度与新站同时处理相同用户、回调或节点数据。

## 10. 失败回退和结果记录

切换前失败，保持目标维护并停其队列 / 调度，保留失败日志、SQL、文件包及 Root 事务恢复证据。源站只有在确定仍是唯一业务入口时，才按原服务记录恢复源 Horizon / WS、调度、helper 并执行 `artisan up`。

目标回到 fresh 环境时，恢复它保存的 fresh 文件和 `.env`，重新指向一直保留的 fresh 数据库；恢复整套 fresh Root 状态与配套 Nginx 配置，再重建对应助手策略。不要把 fresh 数据库与源 APP_KEY、或 fresh CA 与源 Root 元数据交叉配对。恢复后先 `nginx -t`，再按实际服务范围重载和核验。

目标已经接受新业务写入后，不能直接把 DNS 指回旧数据而宣称无损。先停止新写入，记录两边差异并按业务恢复计划处理新增订单、流量和用户修改；不通过删除备份、重跑安装或清空 Redis 解决。

### 本次跨机演练的记录位置

演练使用两台独立 Ubuntu 原生 PHP 8.3 机器，应用目录为 `/www/wwwroot/migration-test`，运行用户 `www-data`，PHP `/usr/bin/php8.3`，Nginx `/usr/sbin/nginx`；网站测试端口为 `8080`，独立 HTTPS 通讯端口为 `8443`。该参数是隔离测试布局，不是要求公网网站使用 HTTP。

2026-11-01，两台 VM 均从空系统准备原生环境，分别通过正式 `v2026.11.01.1` 归档、Composer 固定摘要和真实 `init.sh` 全新安装。源站加入合成用户、套餐、已完成订单、机器 / 节点、余额流量和私有文件，并保留 SmartExpiry、FlowScope 的禁用状态；之后排空 7 个节点同步任务，备份并传输到目标。

目标保留 fresh 数据库和文件，在新的 `migration_restored` 空库导入备份，恢复同版运行资源和原 APP_KEY，再执行应用更新与入口修复。结果为：

- 15 / 15 项数据和文件检查通过：用户、套餐、权限组、节点、机器、订单与设置的行数及值摘要一致；原 APP_KEY、插件配置及启停选择、Admin 资源、私有文件与 CA 凭据保留。fresh 库仍只有原新装管理员，未与旧数据混合。
- 13 / 13 项 HTTP 和恢复运行验收通过：旧管理员 / 用户密码登录、原用户 UUID / 余额 / 套餐、原订阅 Token、经原 CA 严格验证的机器 Token 认证、错误 Token 拒绝、源站维护状态、错误 APP_KEY 无法解密、目标 Horizon 和实际分钟调度等。
- 6 / 6 项最终运行检查通过：通过真实 PHP-FPM 和管理员认证读取助手状态，助手授权对应目标应用及用户，入口报告新 IP，运行服务全部 active，失败任务为 0，三个流量队列各有一个实际 worker。
- 原 CA 公共信任链未变，新叶证书包含目标 IP `192.168.128.129`，TLS 1.3 严格校验通过；目标重建了助手授权及 socket。

正文中的有序 SQL 导出和 `cmp` 命令另做了独立验证：从仍在维护的源库导出关键表，在目标另建 `migration_dump_validation` 空库导入原备份并按相同参数导出，两份 10,881 字节数据完全一致；源 / 目标使用 MariaDB 10.11.14 客户端。这个校验库没有替换正在运行的还原库。

本次还遇到了显式 IP 到通配监听的 Nginx 热重载绑定冲突。管理器的证书探测失败后自动回退；确认磁盘证书正确、线上仍是 fresh 旧证书后，在独立目标机受控重启 Nginx，再完成原 CA 下的新地址修复。该故障及恢复要求已写入第 8 节。

演练使用合成数据，源站保持维护、目标恢复服务；私有 SQL、密码、APP_KEY、Token 和 CA 私钥仅保留在远端 Root 私有备份中，不进入仓库。完整结果见[脱敏跨机验证记录](../verification/backup-migrate-restore-20261101.json)。生产 DNS 切换、真实 Node 数据转发、WSS、外部支付 / 邮件回调、采集器迁移和长期负载未执行，不以 API 检查代替这些验收。
