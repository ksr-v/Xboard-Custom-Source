# 只用 SQL 和 .env 恢复上线：v2026.11.01.1

适用场景：旧站只保存了数据库 SQL 和原 `.env`，希望在新服务器的 aaPanel / 宝塔、PHP 8.3 环境恢复用户、套餐、订单、余额流量、机器身份和插件设置。

主流程是：**部署干净同版源码 → 导入 SQL → 放回原 .env → 执行更新 → 启动服务**。这是恢复已有网站，不运行全新安装向导。

如果新面板已经执行过 `init.sh`，先按[文末的兼容步骤](#已经在新服务器运行过-initsh)清理新装集成，再从第 3 步继续。

两份备份可恢复数据库中的业务与设置，原 `APP_KEY` 必须保留。

## 1. 旧站只保存两份备份

先暂停旧站计划任务并阻止新增写入，等队列处理完，再停 Horizon / WS 并进入维护。随后：

1. 在 aaPanel / 宝塔的「数据库」中导出本站完整 SQL，下载到自己的电脑。
2. 在旧网站根目录找到隐藏文件 `.env`，下载原文件。

保存好这两个文件。SQL 和 `.env` 含业务及连接信息，不放进网站公开下载目录。确认还原成功前保留旧站，并让旧站继续停止写入。

## 2. 新服务器准备环境和干净源码

按[全新安装教程](install-2026.11.01.1.zh-CN.md)完成**第 1～3 节的环境、源码和网站配置**，停在运行 `init.sh` 之前：

- 安装 Nginx、PHP 8.3、Redis、MySQL / MariaDB；PHP 扩展、函数和 Composer 按该教程准备。
- 新建网站，把经过校验的 `v2026.11.01.1` 正式源码解压到该网站目录。
- 网站运行目录设为 `public/`，PHP 选 8.3，设置 Laravel 伪静态，并为实际网站域名配置 HTTPS。

**这里不要执行新装教程第 4 节的 `init.sh`，也不要生成新的 APP_KEY。**

先用与旧站相同的面板版本恢复。若旧站单独升级过插件，新服务器还需准备匹配的插件版本；不要用更旧插件源码强行加载较新的数据库记录。

以下命令把 `/www/wwwroot/你的域名` 换成实际网站根目录；`www` 和 PHP 路径按本站真实配置替换。

## 3. 导入 SQL，上传并修改原 .env

在新 aaPanel / 宝塔中创建一个**专门的空数据库**及账号，在该数据库的「导入」中选择旧 SQL。不要导入到其他业务库。

把旧 `.env` 上传到新网站根目录，即与 `artisan` 同一目录，文件名保持为 `.env`。

编辑这份 `.env`，只调整新服务器的连接和地址。下面是需要核对的项目，不要用示例值整份覆盖原文件：

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=新服务器已导入SQL的数据库名
DB_USERNAME=该数据库账号
DB_PASSWORD="该数据库密码"

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

APP_URL=https://实际网站域名
APP_ENV=production
```

**保留原 `APP_KEY`，并确认原文件中 `INSTALLED=true`。** 不使用新站生成的 key，不运行 `key:generate`。

Redis 也使用新服务器自己的服务；设有密码时，把上面的 `null` 改为实际密码。原文件若含 `DB_URL`、`REDIS_URL` 等完整连接串，一并改到目标，避免它们覆盖分项设置。密码含特殊字符时按 `.env` 格式保留正确引号。

## 4. 一条更新命令完成恢复

以 Root 登录新服务器 SSH，在网站根目录执行：

```bash
cd /www/wwwroot/你的域名
bash update.sh --deployed-code --skip-endpoint \
  --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar
```

这会准备站点用户权限、安装锁定依赖、执行数据库迁移、恢复默认插件的原启停选择并清理编译缓存。应用命令由站点用户运行，无需另以 Root 执行 `composer install`。

看到完成提示后执行：

```bash
runuser -u www -- /www/server/php/83/bin/php artisan storage:link
runuser -u www -- /www/server/php/83/bin/php artisan up
```

`--skip-endpoint` 表示本次先恢复网站，不自动配置独立 IP 私有 CA 入口。网站域名 HTTPS 仍由 aaPanel / 宝塔的网站证书提供。

如果命令报错，先保留现场并处理具体错误，不重跑 `init.sh`，不删除 `.env`，也不执行 `composer update`。

## 5. 启动调度和队列，检查后上线

在 aaPanel / 宝塔恢复本站的两项任务，每项只配置一套：

| 项目 | 设置 |
| --- | --- |
| 计划任务 | 每 1 分钟执行；下方脚本以 Root 启动并降权到 `www` |
| Supervisor / 进程守护 | 用户 `www`，工作目录为网站根目录，进程数 `1`，自动启动启用、`autorestart=true` |

计划任务脚本：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/你的域名/artisan schedule:run
```

Supervisor 启动命令：

```bash
/www/server/php/83/bin/php /www/wwwroot/你的域名/artisan horizon
```

先手动运行计划任务，再确认下一分钟出现自动执行记录；确认 Horizon 持续运行。详细界面设置见[新装教程第 5 节](install-2026.11.01.1.zh-CN.md#5-配置计划任务与-horizon-守护)。

切换域名解析前检查：

- 用旧管理员账号和密码登录，核对用户、套餐、余额流量、订单和插件启停选择。
- 用旧用户的订阅链接获取订阅，确认节点及用户身份保留。
- 网站地址或域名改变时，在后台同步修改站点 / 订阅地址，并调整已有节点的面板地址；保留原机器身份和 Token。

检查正常后把域名切到新服务器，确认网站 HTTPS、订阅、队列和调度正常。旧站继续停止写入，避免两边同时处理同一业务。

## 已经在新服务器运行过 init.sh

如果新面板已经全新安装完成，先暂停新站调度和队列。在**新站仍使用其 fresh 数据库和新 .env 时**，进入网站根目录清理新装插件的桥接：

```bash
cd /www/wwwroot/你的域名
runuser -u www -- /www/server/php/83/bin/php artisan xboard:plugin-online-update smart_expiry --deactivate
runuser -u www -- /www/server/php/83/bin/php artisan xboard:plugin-online-update qrcodeextend --deactivate
runuser -u www -- /www/server/php/83/bin/php artisan xboard:plugin-online-update subinfo_customizer --deactivate
```

这三条只清理 fresh 集成，不下载插件。然后从本页第 3 步继续：旧 SQL 导入另建的空库，上传旧 `.env`，再执行更新命令。旧数据库中的插件启停选择会恢复，避免禁用插件残留新装桥接。

若新站已创建独立 IP 私有 CA，导入旧 SQL 和原 `.env` 后，不把这套 fresh CA 当成已经恢复可管理的原 CA。本页先验收网站域名上线；私有入口和节点信任另行配置。

本轮只用 SQL 和 `.env` 重测通过：主流程 29 项、已初始化新站的兼容流程 27 项检查全部通过。旧账号、订阅、余额套餐、订单、机器身份和插件状态已恢复，主流程还验证了 Horizon 与实际分钟调度。未复制旧网站目录、上传文件或 CA，未进行生产 DNS 切换或真实节点转发。详见[脱敏验证记录](../verification/sql-env-only-restore-20261101.json)。

恢复范围：旧上传、二维码私有图片及原私有 CA / 私钥不在这两份备份中。普通网站域名 HTTPS 可按新站配置上线；固定信任原私有 CA 的节点需要另行配置入口及信任。
