# 宝塔 / aaPanel 安装教程（内置插件版）

本教程沿用原版的“安装环境 → 创建网站 → 拉取源码 → 配置网站 → 执行安装 → 守护进程”流程，适用于独立 **v2026.10.19.2** 的原生 PHP-FPM 部署。

核心命令仍是 `git clone` 和 `bash init.sh`。新版需要**先配置实际运行的 Nginx / PHP-FPM 网站，再执行安装器**。安装器会统一处理权限及 NodeEndpoint 入口，无需再逐个上传插件、单独运行 Root 安装脚本或手动上传公共 CA。

全新安装默认安装并启用：

| 插件 | 版本 |
| --- | --- |
| NodeEndpoint | 1.0.6 |
| SmartExpiry | 1.2.0 |
| QRCode Extend | **1.2.0，审计副本** |
| FlowScope | 2.4.0 |

本页的新装步骤只用于新建空站。**已有面板请跳到“版本更新”，保留原 `.env` / APP_KEY 和数据库。**

## 环境要求

沿用原教程的建议配置：1 核 CPU、2GB 内存、10GB 以上空间，另预留备份空间。

需要准备：

- Linux 和宝塔 / aaPanel，能够以 Root 登录 SSH。
- Nginx / OpenResty、PHP **8.3**，网站使用 PHP-FPM。
- Redis；使用 MySQL 时，预先准备 MySQL / MariaDB 的空数据库和独立账号。
- Python **3.10+**、可信 Composer **2.2 及以上的 2.x 版本**、Git、OpenSSL、`ss`、`runuser`；本教程固定使用 Composer **2.10.3**。

安装器不代装宝塔、升级系统或 Composer，也不自动创建数据库账号。PHP CLI 和网站 FPM 应使用同一版本。

## 快速部署

### 1. 安装宝塔 / aaPanel

已经安装的直接跳过。未安装时，按[宝塔官网](https://www.bt.cn/)或 [aaPanel 官网](https://www.aapanel.com/)提供的安装说明操作。

### 2. 基础环境设置

#### 2.1 安装 LNMP 环境

在宝塔软件商店安装并启动 Nginx、PHP **8.3**、Redis；使用 MySQL 时安装并启动 MySQL / MariaDB。

在 Root SSH 中检查：

```bash
python3 --version
```

Python 必须为 3.10 或更新版本。锁定依赖要求 `composer-runtime-api ^2.2`，Composer 2.0 / 2.1 不满足要求。为避免误用宝塔或系统中的旧 Composer，以 Root 下载[Composer 官网](https://getcomposer.org/download/)的固定 **2.10.3** PHAR 并校验：

```bash
install -d -o root -g root -m 755 /opt/xboard-tools
curl -fsSL --proto '=https' --tlsv1.2 \
  https://getcomposer.org/download/2.10.3/composer.phar \
  -o /opt/xboard-tools/composer-2.10.3.phar
printf '%s\n' '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /opt/xboard-tools/composer-2.10.3.phar' | sha256sum -c -
chown root:root /opt/xboard-tools/composer-2.10.3.phar
chmod 0644 /opt/xboard-tools/composer-2.10.3.phar
```

必须看到摘要校验 `OK` 才继续。以上操作只下载固定 PHAR，不执行远程安装脚本，不替换系统 Composer。下文通过 `--composer` 显式指定它。安装器会以网站用户运行 Composer，不需要另以 Root 执行 `composer install`；也不要执行 `composer update` 或添加 `--ignore-platform-reqs` 绕过要求。

#### 2.2 安装 PHP 扩展

在 PHP 8.3 中启用锁定依赖需要的扩展，包括 `redis`、`fileinfo`、`mbstring`、`curl`、`dom` / XML、`openssl`、`pcntl`、`posix`，以及数据库驱动（MySQL 使用 `pdo_mysql`）和 PHP 基础扩展。安装 PHP `zip` 扩展或系统 `unzip` 以便 Composer 解压依赖。

Composer 安装时会检查完整依赖要求，缺少的扩展应先补齐。原生 FPM 路线不要求先安装 Swoole 或 Event。

#### 2.3 检查 PHP 禁用函数

在 PHP 8.3 设置中确认 `putenv`、`proc_open`、`pcntl_alarm`、`pcntl_signal` 和 **`symlink`** 等安装及队列需要的函数可用；相应扩展也须启用。Laravel 创建公共存储链接需要 `symlink`，这个函数可用时，该步骤不需要开放 `exec`。修改后按需要重启 PHP-FPM，并确认命令行 PHP 使用正确配置。

新版新装流程会在 Composer 和数据库初始化之前，检查所选 PHP CLI 是否提供 `symlink`。若预检停止，先修正对应 PHP CLI 的设置再重试；安装器不会自动修改 `php.ini` 或开放 `exec`。

### 3. 网站配置

#### 3.1 创建网站

1. 打开 **宝塔 → 网站 → 添加站点**。
2. 填写面板域名，PHP 选择 **8.3**。使用 MySQL 时创建空数据库并保存数据库名、用户名和密码。
3. 使用新建专用目录，例如 `/www/wwwroot/your-domain`。
4. 在宝塔文件管理中，确认该目录是刚创建的新站，只包含默认占位文件，再清理这些占位文件，使目录为空。常见文件包括 `index.html`、`404.html`、`502.html`、`.htaccess`、`.user.ini`；同时查看隐藏文件。

如果宝塔保护了新站的 `.user.ini`，先通过该站点的设置解除保护再移除。不要把已有业务站点当成空站清理。Git 提示目录非空时先检查剩余文件，不使用递归删除命令清空目录。

<a id="32-deploy-xboard"></a>

#### 3.2 部署 Xboard 源码

以 Root 登录 SSH，将 `your-domain` 替换为实际网站目录：

```bash
cd /www/wwwroot/your-domain

git clone --branch v2026.10.19.2 --single-branch \
  https://github.com/ksr-v/Xboard-Custom-Source.git ./
```

这里固定到已发布的带插件版本。检出标签时出现 `detached HEAD` 提示属于正常现象。

**先完成下一节的网站配置，再运行 `init.sh`。** 源码已经包含四插件及公共 Root 工具资产，不需要另外下载插件 ZIP。

也可以使用 [Release](https://github.com/ksr-v/Xboard-Custom-Source/releases/tag/v2026.10.19.2) 中的固定附件 `xboard.tar.gz`，独立核对 `SHA256SUMS` 后解压到该空目录，替代上述 Git 克隆。两种取源码方式选一种即可，随后均继续 3.3。

#### 3.3 配置网站并执行安装

先在宝塔的网站设置中完成：

1. 运行目录设为 **`/public`**。
2. PHP 版本选 **8.3**，保留宝塔对应的 PHP-FPM 配置。
3. 设置 Laravel 伪静态。最小规则为：

   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$query_string;
   }
   ```

4. 保存并检查 Nginx 配置，启动 / 重载 Nginx 和 PHP 8.3 FPM。此时依赖和数据库尚未安装，网页暂时不能正常显示不代表失败；真实站点和 FPM 后端需要已经运行。

然后在 Root SSH 中执行：

```bash
cd /www/wwwroot/your-domain

bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

执行前替换：

- `your-domain`：实际面板网站目录。
- `www` 和 PHP 路径：本机实际的网站用户和 PHP 8.3 CLI 路径，宝塔通常使用以上值。

首次配置节点通讯地址时，安装器提供交互选项：

```text
1. 自动检测本机公网 IPv4（默认，回车选择）
2. 手工输入 IP / 域名
```

选择 `1` 后检测**这台面板服务器的公网 IPv4**并显示候选，回车接受或输入其他实际 IP / 域名；检测失败必须手工输入。选择 `2` 直接输入实际地址，不请求公网检测服务。这里不传 `--host`，避免把教程示例地址写入配置。`203.0.113.10` 属于文档示例网段，不能用于实际通讯。已有真实 Root 入口或显式指定的 `--host` 跳过该菜单，地址不会被自动检测结果覆盖。

保留 `--composer`，指向 2.1 节已经校验的 PHAR，避免自动选中旧版 Composer。无需改成 Root 运行应用命令，也不要给整个站点设置 777。

安装过程会依次完成：

1. Root 检查网站、已有安装状态、站点用户和实际 FPM 后端，准备应用、插件及 Admin 补丁所需权限。
2. 降权为 `www` 运行 Composer、Xboard 安装、数据库迁移和四插件的原生安装启用。
3. 按提示输入数据库、Redis 和管理员信息。MySQL 使用宝塔创建的空数据库及其独立账号；保存安装结束时显示的管理员初始信息。
4. 配置 NodeEndpoint 的 IP 私有 CA HTTPS 入口、保存本机 TCP 端口规则，并自动导入公共 CA。

Root 入口确认时，先核对显示的计划，再输入提示中的**完整确认内容**。确认格式如下，其中地址和端口必须使用本机计划中的实际值：

```text
INSTALL <实际通讯地址>:<端口>
```

这是格式说明，**不是另行执行的 Shell 命令**，不要原样输入尖括号和占位文字。照抄本机提示要求的短语：`INSTALL` 后有一个空格，还要带上实际地址和端口；只输入 `INSTALL` 不能确认该计划。新版交互输错时会说明原因并再次询问；输入 `CANCEL`、发送 EOF 或按 Ctrl+C 可取消。自动化提供的确认内容仍必须与完整短语精确一致。

新建 CA 的口令由新版插件自动随机生成，先使用面板原 `APP_KEY` 加密保存在独立凭据表，再签发证书；不再要求输入或重复输入 CA 口令。管理员可在 HTTPS `/node-endpoint` 页面的“证书与信任”区显式查看和复制。普通设置和节点安装命令不会携带口令。

请将数据库与原 `.env` / `APP_KEY` 一起备份，并按 NodeEndpoint 工具说明导出、验证和离线保管加密 CA。CA 私钥始终位于网站之外的 Root 私有目录或离线介质，不上传面板。旧版已签发的 CA 保持原样；未托管的旧 CA 在维护时仍需要原口令，升级不会自动改口令或更换证书。

完成后按安装输出的管理地址登录，刷新插件页面检查四插件及公共 CA。无需再次下载和运行独立的 Root 安装器，也不对已安装网站重复运行 `init.sh`。

日后更改通讯 IP 时，在“节点通讯设置”填写新地址并保存，在“SSH 配置入口”点击“一键复制 Root 工具命令”。以 Root 通过 SSH 登录这台面板服务器并粘贴执行，核对命令中的已保存地址、向导端口和 WS 模式，再确认修改。完成后回到插件页点击“验证入口”；只有保存网页设置还不会修改 Root 管理的监听和服务器证书。

本机防火墙支持运行中的 firewalld 和 UFW；UFW 原本未启用时只保存规则，不启用整个防火墙。默认规则允许所有来源访问所选 TCP 通讯端口。服务商防火墙 / 云安全组仍需在控制台放行 **8443/TCP**；保存本机规则不等于已经验证公网可达。

未配置 WS 后端时使用 REST 同步，可先完成正常对接。FlowScope 面板插件会启用，但每台节点的采集器仍在对应节点安装。如果明确只使用域名模式或延期配置 IP 入口，可给安装命令加 `--skip-endpoint`；四插件仍安装，但 IP 私有 CA 入口尚未就绪。

## 详细配置

### 1. 配置守护进程

安装成功后，在宝塔安装 Supervisor 并添加队列任务：

| 项目 | 值 |
| --- | --- |
| 名称 | `Xboard` |
| 运行用户 | **`www`** |
| 工作目录 | `/www/wwwroot/your-domain` |
| 启动命令 | `/www/server/php/83/bin/php artisan horizon` |
| 进程数 | `1` |

替换实际网站目录，启用自动启动和异常重启。应用队列不用 Root 用户运行。

### 2. 配置计划任务

在宝塔计划任务中创建 Shell 任务，**运行用户选 `www`**，每分钟执行一次：

```bash
/www/server/php/83/bin/php /www/wwwroot/your-domain/artisan schedule:run
```

如果计划任务界面只能以 Root 执行，则使用降权形式：

```bash
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/your-domain/artisan schedule:run
```

两种方式选一种，目录和 PHP 路径与安装时保持一致。

### 3. Octane 配置（可选）

本教程以原生 PHP-FPM 完成安装和 Root 入口集成。Octane 的完整部署及其不同的代理 / Root 入口配置未在本轮验证；本页先保留 FPM，不直接套用旧教程的 Octane 反代替换。需要切换时另行配置和验证。

## 维护指南

### 版本更新

已有面板按 [Root 归档升级说明](bundled-native.zh-CN.md#已有面板升级) 操作：先备份数据库、`.env` / APP_KEY、源码、插件及 Root 状态，再核对固定发行包摘要，通过升级器部署。

无需先卸载插件，不清空数据库，不重建 APP_KEY。原版面板依赖清单与本包不一致时需要单独准备升级；修改过的未知插件源码、旧二维码 core overlay 和不一致的 Root 入口会停止并保留，不强行覆盖。

不再使用旧教程的 Git 强制重置或原作者子模块修复命令。应用升级后按需要重启 Supervisor 中的网站任务。

### 日常维护

查看应用、Nginx、PHP-FPM 和 Supervisor 日志；定期备份数据库、`.env` / APP_KEY、插件配置和 Root 入口状态，并验证恢复途径。CA 私钥的加密备份离线保存。

## 常见问题

**私有证书对接正常，但服务器详情 IPv4 显示“待获取”：** 旧独立 Nginx 入口遗漏 FlowScope 使用的精确 `POST /api/v2/flowscope/node-address`，机器状态接口正常不能证明此上报成功。v2026.10.19.2 新装通过 root-installer-v1.0.8 / tools 1.0.5 生成包含该路径的计划。已有面板只升级代码或插件不会自动刷新原 Nginx；在面板服务器以 Root 执行一次：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.8/install.sh | bash -s -- --refresh-entry
```

工具读取已有安装记录中的地址、端口、站点、PHP 和 WS 模式，核对计划后输入完整确认短语。支持原生 HTTPS FPM / Octane，Docker 不在自动刷新范围；原 CA、服务器证书、托管口令、Token 和数据库保留，不写插件设置或防火墙。确认 FlowScope 2.4.0 启用、节点使用独立 Node v1.13-orphan.4，并能访问 `https://api-ipv4.ip.sb/ip`。IPv4 自动报告不要求 Python 采集器或周期流量；检测成功后等待旧 404 最多 10 分钟负缓存自动重试，再刷新详情。无需重装节点、改 Token 或强制重启。`--sync-ca` / `--configure-firewall` 不会刷新路径；修改通讯 IP 仍使用普通 Root 向导。

**旧版后台可以登录，但安装停在 `Filesystem.php` / undefined `exec`：** 主程序和数据库可能已完成安装，失败的是后面的 `storage:link`。独立 IP HTTPS 入口、防火墙及公共 CA 配置排在它之后，尚不能据此认为全部安装完成。新版新装流程已将 PHP CLI 的 `symlink` 可用性检查前置到 Composer 和数据库初始化之前。

在宝塔 PHP 8.3 的禁用函数中移除 `symlink`，保存后确认命令行 PHP 配置已生效；需要时重启 PHP-FPM。然后以 Root 在已部署本版代码的安装目录执行下面的恢复步骤，替换实际目录：

```bash
cd /www/wwwroot/your-domain &&
runuser -u www -- /www/server/php/83/bin/php artisan storage:link &&
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

此时使用已有安装的继续流程；尚未创建入口时选择自动检测公网 IPv4 或手工输入地址，已有真实入口保留原值。不重跑全新安装，不删除 `.env`，不重建 APP_KEY。

**v2026.10.18.1 / v2026.10.18.2 因只输入 `INSTALL` 而中止：** 如果主程序已经安装，保留数据库和 `.env`，部署本版代码后，在该站点目录以 Root 继续完成后续配置：

```bash
cd /www/wwwroot/your-domain &&
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

替换实际站点目录，核对安装器给出的通讯地址，并按 3.3 节说明填写计划中的完整确认短语。不要重跑 `init.sh`、删除 `.env` 或重建 APP_KEY；Root 确认中止不会回滚已完成的主程序安装。

**节点通讯地址已经保存为 `203.0.113.10`：** 这是旧教程中的示例 IP，并非本机公网 IP。若独立入口也已创建，在同一台独立面板服务器以 Root 执行：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.8/install.sh | bash -s -- --repair-address
```

先选择自动检测公网 IPv4 或手工输入地址。自动模式核对候选，回车接受或输入真实通讯 IP / 域名；手工模式不请求公网检测服务。审阅并确认修改计划后，工具沿用现有 CA 和口令，为新地址重签服务器证书，以站点用户保存设置并同步公共 CA；只在插件页面改 IP 不能修复服务器证书。此命令仅适用于提供 `xboard:configure-endpoint` 的独立后端。若入口尚未创建，使用上方已有安装的继续流程并核对真实地址。完成后刷新插件页面，重新复制节点安装命令；不重装主程序或重建 CA。

- **没有发现 Nginx / PHP-FPM 站点**：先核对 `/public`、PHP 8.3、实际 FPM 后端、伪静态及运行中的 Nginx，再处理失败步骤，不猜测 socket。
- **Composer 或 PHP 报错**：核对 PHP CLI / FPM 版本和缺失扩展。提示 `composer-runtime-api ^2.2` 时是选中的 Composer 过旧，按 2.1 节准备固定 PHAR，并显式传入对应的 `--composer` 路径；不修改 `composer.lock` 或绕过平台检查。`mbstring` 重复加载警告与本次致命依赖错误不同，可另查对应 PHP 配置中重复的扩展条目。
- **数据库连接失败**：核对服务、空数据库和独立账号；不要删除已安装面板的 `.env` 重试。
- **IP 入口超时**：核对监听端口、本机防火墙及云安全组，不能通过关闭证书校验解决 TCP 超时。
- **只剩防火墙或 CA 后续步骤失败**：保留安装输出，使用对应步骤的定向恢复；不要重装面板、清空配置或重建 CA。
- **后台空白**：检查浏览器错误、本站 Admin 资源及应用日志，必要时从已验证的独立发行包恢复，不拉取原作者子模块。

如果 **v2026.10.18.1** 只在 Composer 安装依赖阶段失败，尚未初始化应用 / 生成 APP_KEY，旧版的缓存目录权限告警可先以 Root 修复：

```bash
install -d -o root -g root -m 755 /var/cache/xboard-composer
```

然后重跑原 `init.sh` 安装命令，并加入已经校验的 `--composer /opt/xboard-tools/composer-2.10.3.phar` 参数。v2026.10.18.2 会自行处理缓存父目录权限。**保留 `.env`，不要通过删除它强行重试。** 若已经开始应用初始化或面板已安装，先保留状态并确认失败阶段，再决定后续操作。

## WebSocket 实时同步（可选）

先按上文完成 REST 对接即可。后续添加 WS 时，在 Supervisor 新增 `Xboard-WS`，工作目录为站点目录，用户为 `www`，启动命令为：

```bash
/www/server/php/83/bin/php artisan ws-server start
```

核对真实监听地址后配置域名站点的 `/ws/` 代理。若实际监听为 `127.0.0.1:8076`，可使用：

```nginx
location /ws/ {
    proxy_pass http://127.0.0.1:8076;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 60s;
}
```

检查并重载 Nginx，验证域名 WS 路由。若独立 IP HTTPS 入口此前按 REST 模式安装，**仅修改域名站点不会修改这个独立入口**；还需通过 NodeEndpoint Root 工具的修改流程配置对应 WS 后端及插件 WS 模式，保留原 CA 和证书。两处验证完成前继续使用 REST。

完整安装验证使用隔离 FPM / SQLite 测试站点；Docker / Octane、MySQL / PostgreSQL 完整安装及客户端业务流量不在本轮通过范围。详见 [验证报告](bundled-validation.zh-CN.md) 和 [完整安装 / 升级说明](bundled-native.zh-CN.md)。
