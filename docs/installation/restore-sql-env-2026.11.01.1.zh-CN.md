# 已完成 init.sh：只导入旧 SQL 和 .env 后恢复上线

适用 `v2026.11.01.1` 同版、插件版本匹配的 PHP-FPM 站点：新服务器已初始化，cron 和 Supervisor / Horizon 已运行，随后直接导入旧数据库 SQL，并将旧 `.env` 放回与 `artisan` 同级。

**保持 cron 和 Supervisor 运行，使用下面的命令恢复。旧 `.env` 原样保留，不修改 Redis 前缀，不手工修改其他文件或配置，也不重新初始化。** 命令会自动清缓存、同步插件运行文件并让 Horizon 加载恢复后的配置。

## 1. 确认前提，填写本机路径

新服务器的数据库名、账号密码、数据库 / Redis 地址必须已经与旧 `.env` 匹配，SQL 已完整替换新装数据；网站继续使用原域名和原 `APP_KEY`。若连接本身不匹配，仅执行清缓存命令不能解决连接问题。

原来的分钟任务继续运行；本站 Horizon 已由 Supervisor 守护，`autorestart=true`。未配置这两项的，先按[新装教程第 5 节](install-2026.11.01.1.zh-CN.md#5-配置计划任务与-horizon-守护)配置。本流程沿用新站已有的网站、PHP-FPM、cron 和 Supervisor 配置。

用 Root SSH 登录，在 **Bash** 中填写以下变量。PHP、Composer 和网站用户沿用初始化时的值；数据库变量填写旧 `.env` 的对应值。下面只设置当前 Shell 变量，不写入配置文件。

```bash
set -e
cd /www/wwwroot/你的域名
restore_user='www'
restore_php='/www/server/php/83/bin/php'
restore_composer='/opt/xboard-tools/composer-2.10.3.phar'
restore_db_host='127.0.0.1'
restore_db_port='3306'
restore_db='旧.env中的DB_DATABASE'
restore_db_user='旧.env中的DB_USERNAME'

# 沿用本机已有的 Supervisor 客户端、主配置和本站 Horizon 任务名
restore_ctl='/www/server/panel/pyenv/bin/supervisorctl'
restore_supervisor_conf='/etc/supervisor/supervisord.conf'
restore_program='xboard-horizon'

"$restore_ctl" -c "$restore_supervisor_conf" status "$restore_program"
restore_old_pid=$("$restore_ctl" -c "$restore_supervisor_conf" pid "$restore_program")
[[ "$restore_old_pid" =~ ^[1-9][0-9]*$ ]]
```

上面的 Supervisor 路径和任务名是示例，应使用本机现有值。可先执行 `"$restore_ctl" -c "$restore_supervisor_conf" status` 列出任务，取属于本站的完整第一列，可能包含 `组名:任务名`。[aaPanel 安装的客户端可能不在 PATH 中](https://www.aapanel.com/forum/d/19014-can-not-found-the-supervisor-after-install-the-supervisor30-via-portal)，`-c` 用来指定现有配置，参见 [Supervisor 文档](https://supervisord.org/running.html#supervisorctl-command-line-options)。这一步只读取状态，不重新安装或重载 Supervisor。

初始化脚本应使用 `bash init.sh`；Ubuntu 上直接 `sh init.sh` 实测会因 dash 不支持 `pipefail` 而退出。**已经初始化完成的站点从下面继续，不再执行 init.sh。**

## 2. 执行恢复命令

在同一终端继续执行。MySQL 提示时输入旧 `.env` 的数据库密码；`mysql` 使用本机已有客户端，若不在 PATH 中，将它换成客户端的绝对路径。若旧 `.env` 使用连接 URL 或 Unix socket，查询参数也按实际连接填写，确保查询的就是面板还原库。

```bash
# 修复导入 .env 后的权限，只做准备
bash update.sh --deployed-code --skip-endpoint --prepare-only \
  --app-user "$restore_user" --php "$restore_php" --composer "$restore_composer"

# 清掉新装时的编译配置和站点设置缓存
runuser -u "$restore_user" -- "$restore_php" artisan config:clear
runuser -u "$restore_user" -- "$restore_php" artisan cache:forget admin_settings redis

# 只查询旧库中已禁用的插件，清除它们的新装桥接残留
if ! restore_disabled=$(mysql --host="$restore_db_host" --port="$restore_db_port" \
  --user="$restore_db_user" -p --database="$restore_db" --batch --skip-column-names \
  --execute="SELECT code FROM v2_plugins WHERE is_enabled=0 AND code IN ('smart_expiry','qrcodeextend','subinfo_customizer') ORDER BY code"); then
  echo '读取旧插件状态失败，已停止恢复。' >&2
  exit 1
fi
for restore_code in $restore_disabled; do
  case "$restore_code" in
    smart_expiry|qrcodeextend|subinfo_customizer) ;;
    *) echo '插件状态输出异常，已停止恢复。' >&2; exit 1 ;;
  esac
  runuser -u "$restore_user" -- "$restore_php" artisan \
    xboard:plugin-online-update "$restore_code" --deactivate --no-interaction
done

# 按旧库的配置和启停选择同步插件、依赖及数据库，自动清理运行缓存
bash update.sh --deployed-code --skip-endpoint \
  --app-user "$restore_user" --php "$restore_php" --composer "$restore_composer"

# 通知 Horizon 优雅退出，由现有 Supervisor 自动拉起
runuser -u "$restore_user" -- "$restore_php" artisan horizon:terminate
```

查询结果为空时，无需清理禁用插件，直接继续。`admin_settings` 固定存储在 Redis 中，因此显式指定 `redis`；只清默认缓存不足以清掉新装站点设置。插件清理使用发行版现有命令，保留旧库中的插件配置和启停选择。

## 3. 确认 Horizon 换代后上线

完成上面的更新和退出通知后，由 Supervisor 自动拉起 Horizon；**Supervisor 主服务和 cron 一直运行**。等待当前队列任务结束后，在同一终端检查本站 Horizon：

```bash
restore_new_pid=$("$restore_ctl" -c "$restore_supervisor_conf" pid "$restore_program") || restore_new_pid=0
printf 'Horizon PID：%s → %s\n' "$restore_old_pid" "$restore_new_pid"
"$restore_ctl" -c "$restore_supervisor_conf" status "$restore_program" || true
runuser -u "$restore_user" -- "$restore_php" artisan horizon:status || true
```

应看到 **PID 已变化、Supervisor 显示 `RUNNING`、Horizon 显示运行中**。如果仍是旧 PID，先等待正在执行的任务结束，再检查；若仍未换代，可精确重启本站 Horizon 子任务后重新执行上面的检查：

```bash
"$restore_ctl" -c "$restore_supervisor_conf" restart "$restore_program"
```

该命令只重启指定的 Horizon 子任务，Supervisor 主服务及 cron 继续运行。确认换代后，再清一次可能被旧进程回填的设置缓存并解除网站维护状态：

```bash
restore_new_pid=$("$restore_ctl" -c "$restore_supervisor_conf" pid "$restore_program")
[[ "$restore_new_pid" =~ ^[1-9][0-9]*$ ]]
[[ "$restore_new_pid" != "$restore_old_pid" ]]
"$restore_ctl" -c "$restore_supervisor_conf" status "$restore_program"
runuser -u "$restore_user" -- "$restore_php" artisan horizon:status
runuser -u "$restore_user" -- "$restore_php" artisan cache:forget admin_settings redis
runuser -u "$restore_user" -- "$restore_php" artisan up
```

用旧管理员密码登录，核对用户、套餐、订单、余额流量和插件启停；用旧订阅链接获取节点。等待下一分钟的自动任务记录正常，再把原域名解析切到新服务器，检查网站 HTTPS，旧站保持停止写入。

本轮真实测试保留新装配置缓存、插件桥接和正在运行的 cron / Horizon，直接导入旧 SQL 与逐字节相同的旧 `.env`，随后只执行现有恢复命令，**70 / 70 项通过**（主验收 60 项、备用步骤 10 项）。cron PID `162`、Supervisor 主 PID `59` 始终不变；Horizon 从 `163` 自动换为 `574`，额外精确重启子任务后变为 `900`，实际队列任务均读取到原密钥、旧设置和 4 个用户。配置文件内容未变，旧账号、订阅、插件和自动调度的详细结果见[保持任务运行的恢复实测记录](../verification/live-command-restore-20261101.json)。

本次使用隔离 Linux PHP-FPM 测试站，未操作 aaPanel 界面或切换生产 DNS；保持任务运行的 SQL 导入不代表生产业务零中断。两份备份不包含旧上传文件和二维码私有图片。NodeEndpoint 私有 CA 不在本教程范围内，命令使用 `--skip-endpoint` 跳过其配置。
