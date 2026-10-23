# aaPanel + Docker 隔离部署验证

日期：2026-10-23。基础源码：`705e71fe46885db76e2943be876b5304f55f2616`（内置四插件版），另加本次 Docker 权限、持久化和换行修补。插件源文件及来源锁未修改。

本次 Docker 修补及教程为本地测试成果，尚未推送或发布新 Release；不能把先前发布的 Docker 流程视为已经修正。

## 测试环境

- 用户授权的专用虚拟机 `192.168.128.128`，Ubuntu 24.04.3、2 核、约 3.8 GiB 内存。
- 真实 aaPanel 目录及宝塔 Nginx 1.30.5；原有 MySQL / Redis 和网站保留。
- 新装 Docker Engine 29.9.2、Compose 5.6.1，使用独立项目、测试端口和数据目录。主案例采用桥接布局、`RESOURCE_PROFILE=minimal`、宿主 loopback 17001 → 容器 7001、网站 18081；这不是所有默认参数和资源配置的穷举。
- Docker Hub 直连在本环境失败。构建通过临时 SSH 回环转发访问桌面现有 HTTP 代理，仍从官方镜像库及通用依赖源获取资产，保持 HTTPS 校验；未连接原 Xboard 维护者的仓库或镜像。

## 已发现并修补

1. 原 Dockerfile 的 `/www` 父目录归 Root，真实基线构建在 `www` 运行 Composer 时因无法创建 `vendor` 失败。已显式更改该目录属主。原入口最终又以 Root 执行默认 one-shot 安装和自动更新，与应用非 Root 守卫冲突；修补后先准备卷权限，再以 `www` 执行 Artisan，Supervisor 仍管理自己的子进程。基线未形成可运行镜像，不能把旧版 one-shot 失败称为本次容器实测。
2. 原启动脚本吞掉自动更新失败并继续启动。修补后失败停止，避免假健康状态。
3. 原 Compose 未持久化 `storage/app`，容器替换可丢失公共 CA 与插件认领记录。四份样板已加入该挂载。
4. 原构建上下文会包含运行数据。`.dockerignore` 已排除运行存储、安装后的插件、日志和本地凭据；镜像保留固定来源的 `bundled-plugins`，由非 Root 生命周期恢复运行集成。
5. 原归档 Docker 入口存在 CRLF。Docker 相关文本固定 Linux 换行；原始树保留用于复现。
6. 教程原本缺少创建 `.env` 文件、本地镜像重新构建、宿主定时调度和 NodeEndpoint Docker 限制说明，现已补齐中英文步骤。

## 本地检查

VERIFIED：后端 Python 共 64 项，Windows 60 项通过，4 项 Linux 专属检查跳过。新增 6 项使用真实入口脚本及隔离 UID / 服务替身，覆盖降权及 argv、权限顺序、Supervisor 身份、Redis 两种分支、更新失败停止、已正确授权卷的非 Root one-shot 应用命令和 `.env` 目录拒绝。Supervisor 仍默认需要 Root，该检查不替代实际镜像运行。

## 真实部署结果

VERIFIED：修订 Dockerfile 从官方基础镜像构建成功，镜像 ID 为 `sha256:62c08e958063e92f90e0b72b47149618efdf2cd7c5f2944ef229782fbd2ea742`。实际 Root 入口先准备卷，再以 UID 1000 安装空 SQLite 和默认四插件；`.env` / 数据库为 UID 1000 / 0600。Caddy、Octane、Horizon、Redis、WS 五进程均运行，内置 Redis 返回 PONG。真实管理员登录和鉴权 API、九类插件资源 HTTP 200，Admin 页面同时存在 NodeEndpoint / FlowScope loader，实际 JS 同时保留二维码与 SmartExpiry v7 集成标记。这不是四插件完整浏览器业务验收。

VERIFIED：宿主宝塔 Nginx 独立站点 18081 反代到仅 loopback 发布的容器端口 17001。测试机防火墙只新增带本次归属注释的 `192.168.128.1 → TCP 18081` 规则；从 Windows 实际直连 `/`、`/node-endpoint`、FlowScope 公共状态均为 200，不能把 loopback 响应替代跨机可达验证。

VERIFIED：真实合成 active 机器凭据经 Nginx → Caddy → WS 返回 HTTP 101，校验 `Sec-WebSocket-Accept` 并收到 `auth.success`。测试机器及临时凭据已删除，没有发送用户代理流量。无凭据升级曾得到 502，源码确认在 HTTP 升级前主动关闭认证失败连接；有效凭据路径正常，未因此改动业务协议。

VERIFIED：自动调度连续三次观察，`schedule_last_runtime` 连续两次推进 60 秒，后台 schedule / Horizon 状态持续正常；没有重复添加 cron。另以 `docker compose exec -T --user www xboard php artisan schedule:run` 实际执行一次，退出 0，Redis 检查时间更新。这是短时观察及单次命令结果，不是长期运行保证。

VERIFIED：真实 `force-recreate` 删除旧容器并创建新容器；APP_KEY / `.env`、用户密码 hash、四插件版本 / 启用选择 / 配置、NodeEndpoint 设置及凭据行保持。`storage/app` 的普通文件 canary 连同 UID 1000 / 0600 保持，随后已移除；此项不是原生 CA 导入，真正导入 / TLS 见独立手工案例。合成 WS 机器已删除，新 worker 的 registry 为空。

VERIFIED：在已经安装并存在 `.env` / SQLite / 插件运行数据的同一宿主源码目录，实际执行 `FROM scratch` + `COPY . /context` 构建，再创建 / 导出临时容器检查 693 个条目。环境、数据库、`storage/app`、日志、已安装插件、公开插件运行文件、`auth.json` 及 `.runtime` 均未进入镜像，固定 `bundled-plugins/manifest.json` 保留。审计用容器 / 镜像和无密 canary 已移除。

VERIFIED：本次临时 Docker proxy drop-in 已撤销，Docker 重新启动后主案例恢复 HTTP 200；SSH 回环代理隧道关闭。从 Windows 再次直连三条页面 / 插件状态路径均为 200。原有 8 份 Nginx / vhost 配置摘要、Nginx master、Redis / MariaDB / 宝塔原有服务进程保持，Nginx 配置检查通过。

主案例保留供私网验收：`http://192.168.128.128:18081`，管理员邮箱、密码和后台路径仅在虚拟机 Root 0600 文件 `/opt/xboard-aapanel-docker-20261023/private/primary-admin-credentials.json`。此文件不在网站或本报告内。保留范围为本次专属容器、数据、vhost 和仅允许宿主来源访问 18081 的防火墙规则。可核对[主案例无密结果](../verification/aapanel-docker-primary.json)，SHA-256：`1fce9827a3c37fc773ddbe3137f7403a3e19c6f5637d2b84bb769426ca74fe94`。

VERIFIED：另建完整 `/www` 从已校验镜像导出再精确 bind 的独立容器，以容器 UID 1000 安装四插件；使用现有高级 `--deployment docker --manual-ca-passphrase` 和宿主真实宝塔 Nginx。手工隐藏口令输入、公共 CA 原生管理员 API 导入 200、实际私有 CA TLS 机器握手 200、FlowScope 合成 IPv4 上报 200、SQLite 持久化及原网站管理员地址查询均通过；返回 `source=node`、`stale=false`。错误 Token 为 401、错误方法为 405，其他六条采集 / 管理路径仍为 404。域名和 IP 安装命令均可生成。

VERIFIED：宿主 codex 及容器 UID 1000 不能读取 Root CA 私钥。重复高级 `modify` 返回 `changed:false`，CA、服务器证书、APP_KEY / 环境、插件设置和机器 Token 保持。未启用自动口令托管、未修改防火墙。实验容器、入口和独有 Nginx include 已清理，17003 / 18082 / 19843 端口释放；数据库、加密 PKI 和诊断留在 Root 私有目录。primary 站点及其另行登记的窄防火墙规则保留。无密证据为 `aapanel-docker-manual-ca-verification.json`，SHA-256：`624024711ecf51e0ff86b783b8860684c5f8e393672dc4377b8d40ff98748879`。

此私有入口结果只适用于上述独立的整应用绑定、手工 CA 布局，不代表默认 Compose 支持自动 Root 流程。使用合成 Node JSON 报文，未运行 Go reporter、外部 IPv4 检测、私有 WSS 或代理业务流量。可核对[无密机器可读结果](../verification/aapanel-docker-manual-ca.json)。

## 支持边界

默认 Compose 不是原生 PHP-FPM：NodeEndpoint 自动 Root 配置、随机 CA 口令自动托管、`--sync-ca`、`--refresh-entry` 和自动改址不支持该布局。高级 Docker 审计要求已发布的 loopback 后端端口和完整应用精确绑定，host-network 样板不满足端口映射检查。不能通过伪装 Octane / FPM 绕过。

本记录不声称生产部署、真实用户流量、全套浏览器业务验收、ARM64 实机、全部宝塔版本或所有数据库组合已验证。源码包含 Octane 每 30 秒的调度 tick；应先确认后台状态持续自动更新，异常时才排查或使用单个替代调度器，不能盲目重复添加 cron。单次 `schedule:run` 成功不证明长期周期执行。
