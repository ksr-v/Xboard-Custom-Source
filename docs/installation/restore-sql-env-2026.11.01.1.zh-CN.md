# 新服务器已完成 init.sh：导入 SQL 和 .env 后恢复上线

适用情况：新面板已经运行 `init.sh`，现在已导入旧 SQL、覆盖原 `.env`，需要恢复旧网站的业务数据、插件状态并上线。下面针对 `v2026.11.01.1` 同版、插件版本匹配的站点。

**从导入完成这一刻继续即可，不需要回到导入前清理，也不再运行 init.sh。**

## 1. 暂停新站任务，检查恢复后的 .env

如果新站已经配置了计划任务、Horizon / WS，先暂停它们，暂不切换域名到新站。

旧 SQL 应在专门的还原库中；不要与 fresh 数据混合。已经导入完成的，核对网站根目录的 `.env`：

- `DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD` 指向新服务器已导入旧 SQL 的数据库。
- `REDIS_HOST / REDIS_PORT / REDIS_PASSWORD` 使用新服务器 Redis。
- **保留旧站原 APP_KEY**，确认 `INSTALLED=true`、`APP_ENV=production`、`APP_DEBUG=false`。
- `APP_URL` 填实际网站地址；若有 `DATABASE_URL`、`DB_SOCKET`、`REDIS_URL`，一并核对目标连接。

为避免读取新装阶段的缓存、接着处理 fresh 队列，在 `.env` 中使用本站独有的新前缀，例如：

```ini
REDIS_PREFIX=restored_panel_
CACHE_PREFIX=restored_panel_cache_
HORIZON_PREFIX=restored_panel_horizon:
```

## 2. 执行导入后的恢复命令

以 Root SSH 登录，修改下面的网站目录、网站用户、PHP 和 Composer 路径，再**整段执行**。宝塔常见用户是 `www`；PHP / Composer 路径沿用本次 `init.sh` 使用的值。

```bash
set -e
cd /www/wwwroot/你的域名
restore_user='www'
restore_php='/www/server/php/83/bin/php'
restore_composer='/opt/xboard-tools/composer-2.10.3.phar'

# 先修复上传 .env 后的权限，只做准备
bash update.sh --deployed-code --skip-endpoint --prepare-only \
  --app-user "$restore_user" --php "$restore_php" --composer "$restore_composer"

# 移除新装时的编译配置，让后续读取旧 .env 中恢复的连接和密钥
rm -f -- bootstrap/cache/config.php

# 按旧数据库的启停选择，清理其中已禁用插件的新装残留
runuser -u "$restore_user" -- "$restore_php" <<'PHP'
<?php
$_SERVER['argv'] = ['artisan', 'xboard:plugin-online-update'];
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Plugin\BundledPluginInstaller::assertApplicationUser();
$codes = ['smart_expiry', 'qrcodeextend', 'subinfo_customizer'];
$read = fn () => App\Models\Plugin::whereIn('code', $codes)->orderBy('code')
    ->get(['code', 'is_enabled', 'config'])->map(fn ($row) => $row->getAttributes())->all();
$before = $read();
$manager = app(App\Services\Plugin\PluginManager::class);
foreach ($before as $row) {
    if (!(bool) $row['is_enabled']) {
        $manager->disableForUpdate($row['code']);
    }
}
if ($read() !== $before) {
    throw new RuntimeException('插件启停或配置发生变化，停止恢复。');
}
echo "旧插件选择已保留，新装残留已清理。\n";
PHP

# 完成依赖、数据库迁移、插件同步及缓存清理
bash update.sh --deployed-code --skip-endpoint \
  --app-user "$restore_user" --php "$restore_php" --composer "$restore_composer"

runuser -u "$restore_user" -- "$restore_php" artisan up
```

第一段准备权限，随后清除 fresh 编译配置。PHP 部分只清理旧库中已禁用插件的残留，保留原配置和启停选择；更新命令再重建应启用的功能。

`--skip-endpoint` 表示先恢复网站，不在这一步改动独立 IP 私有 CA 入口。网站域名 HTTPS 使用 aaPanel / 宝塔网站证书。

命令报错会停止。保留错误信息处理，不删除原 `.env`、不生成新 APP_KEY，也不重新初始化。

## 3. 恢复队列和每分钟调度

新站原来已经配置这些任务的，恢复 / 重启原任务，让进程读取恢复后的配置；没有配置的，各建立一套：

| 项目 | 设置 |
| --- | --- |
| Supervisor / 进程守护 | 用户 `www`，工作目录为网站根目录，进程数 `1`，自动启动，`autorestart=true` |
| 计划任务 | 每 1 分钟运行一次下方调度命令 |

Supervisor 启动命令：

```bash
/www/server/php/83/bin/php /www/wwwroot/你的域名/artisan horizon
```

宝塔计划任务脚本，Root 执行后降权：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/你的域名/artisan schedule:run
```

确认 Horizon 持续运行，并等待下一分钟出现自动调度记录。界面设置可参考[新装教程第 5 节](install-2026.11.01.1.zh-CN.md#5-配置计划任务与-horizon-守护)。

## 4. 检查后上线

- 用**旧管理员账号和密码**登录，核对用户、套餐、余额流量、订单和插件原启停选择。
- 用旧用户的订阅链接取订阅，确认节点和用户身份保留。
- 网站域名改变时，同步修改后台站点 / 订阅地址及已有节点的面板地址，保留机器身份和 Token。
- 确认网站 HTTPS、Horizon、分钟任务正常，再切换域名；旧站保持停止写入。

本次严格按“真实 init.sh 完成 → 保留 fresh 配置缓存和桥接 → 直接导入 SQL 与旧 .env → 执行后续恢复”的顺序测试，**39 / 39 项通过**，包括实际 Horizon 和下一分钟调度。详见[导入后恢复实测记录](../verification/post-import-restore-20261101.json)。

恢复范围：SQL 和 `.env` 恢复数据库业务与设置，不包含旧上传、二维码私有图片或原私有 CA / 私钥；新装 CA 也不等于原 CA 已恢复。固定信任原私有 CA 的节点需要另行配置入口及信任。

其他情况：如果新服务器尚未运行 `init.sh`，干净同版源码导入 SQL 和原 `.env` 后，可直接运行普通 `update.sh --deployed-code --skip-endpoint`，再配置队列和调度；该路径的[此前实测记录](../verification/sql-env-only-restore-20261101.json)仍保留。
