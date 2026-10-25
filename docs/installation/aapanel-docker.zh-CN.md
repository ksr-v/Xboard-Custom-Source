# 宝塔 / aaPanel + Docker 安装（内置四插件）

本教程配套独立后端 `v2026.10.25.2`，内置 NodeEndpoint 1.0.9、SmartExpiry 1.2.0、二维码审计副本 1.2.0 和 FlowScope 2.4.0。新装通过 `docker-install.sh` 合并 Xboard 安装与宿主 Root 节点入口配置；已有面板使用下方保留数据流程。

配套 Node `v1.13-orphan.6` 支持 Hysteria2 自动 UDP 范围转发及 `node` 管理菜单；先更新后端再更新节点，保留原身份、Token 与 CA，无需卸载插件。新机器安装仍按本文流程，已有面板按[本次更新教程](upgrade-2026.10.25.2.zh-CN.md)。能力与防火墙边界见[节点操作说明](../operations/node-management-hy2.zh-CN.md)。

## 1. 准备环境和宝塔网站

准备宝塔 Nginx、Docker Engine 与 Compose 插件、Git、Python 3.10+、curl、OpenSSL、`ss` 和 `runuser`。宿主机不需要 PHP 或 Redis。按 [Docker 官方文档](https://docs.docker.com/engine/install/)安装环境后检查：

```bash
docker version
docker compose version
```

宝塔添加新的纯静态网站，使用实际网页域名，并在宝塔配置其普通 HTTPS 证书。先将该网站反代到 `http://127.0.0.1:7001`；规则应包含：

```nginx
location / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 60s;
    proxy_buffering off;
}
```

修改原有根 `location`，不要重复添加。这是反向代理，不是 Laravel URL Rewrite。检查并重载 Nginx；容器安装前出现 502 可以等待后续步骤完成。安装器要求唯一匹配到该后端的宝塔站点，不会代改原网站配置。

## 2. 拉取固定版本并一键安装

以宿主机 Root 登录 SSH，在新的空站点目录执行。示例目录换成实际值，不删除已有站点数据：

```bash
cd /www/wwwroot/panel.example.com
git clone --branch v2026.10.25.2 --single-branch \
  https://github.com/ksr-v/Xboard-Custom-Source.git .
bash docker-install.sh
```

按提示填写实际通讯 IP / 域名和管理员邮箱，核对计划后输入一次 `YES`。IP 支持接受自动检测到的公网 IPv4，也可手工覆盖；检测失败必须手填。NAT / 多 IP 服务器应填写节点实际可访问的地址，不能填写文档示例 IP。

默认宝塔 MySQL / MariaDB、内置 Redis。先在宝塔创建空数据库和独立账号；安装时填写容器可访问的数据库地址、端口、库名、账号和隐藏密码。安装器依次准备运行目录权限、构建独立镜像、以容器内 `www` 完成面板与四插件安装，再配置：

- 宿主 Nginx 独立 HTTPS 节点入口，默认 TCP 8443；
- 随机 CA 口令，并用面板原 `APP_KEY` 加密保存；
- 公共 CA 自动导入，以及该入口的本机防火墙规则。

容器网页后端只绑定 `127.0.0.1:7001`。安装器创建带归属记录的 `compose.node-endpoint.yaml`，不执行目录里来源不明的 Compose 文件，不覆盖已有其它项目或占用该端口的服务。

脚本最后打印 Root 私有日志目录；以 Root 查看其中的 `*-application-install.out`，取得首次管理员密码和后台路径并妥善保存。随后用网站域名登录，核对四插件版本、启用状态和 `/node-endpoint` 的入口验证。不要公开管理员日志或完整私有诊断。

默认命令已使用 MySQL / MariaDB；如需 SQLite，可显式选择：

```bash
bash docker-install.sh --database sqlite
```

MySQL 地址默认 `host.docker.internal`，安装器将其映射到宿主 Docker 网关；回车接受或填写实际私网数据库地址。桥接容器内的 `127.0.0.1` 不是宿主机。请在宝塔确保数据库监听容器可达的宿主地址，并允许对应 Docker 网段以独立账号访问。工具不会修改数据库监听、创建账号或清空已有数据；已有面板始终保留原数据库。

新装入口只支持新目录和本工具已认领的同版本重试。已完成的同版本重试保留现有面板，不重新初始化数据库或更换 `APP_KEY`。**已有原版 Docker 面板不要运行新装脚本。**

## 3. 已有标准 Docker 面板补齐私有入口

备份数据库、原 `.env` / `APP_KEY`、`storage/app`、`.docker/.data`、`plugins`、主题及其它运行卷。安装或升级并启用本次 NodeEndpoint 插件，然后在宿主机 Root SSH 执行：

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --docker
```

核对检测结果并确认。多项目时可追加 `--app-dir /实际/Compose项目目录`。默认向导在没有 FPM 站点时也会检测标准 Docker；使用 `--docker` 明确选择部署类型。

支持的布局为本机桥接 Compose 的 `xboard` 服务，镜像内应用为 `/www`，持久化 `.env`、`storage/app`、`.docker/.data` 和 `plugins`，应用使用非 Root 用户。无需整套源码 bind，但后端必须只发布到回环，宝塔站点必须反代到该实际后端并包含 WebSocket 请求头。NodeEndpoint 仅启用插件不会创建宿主 HTTPS 监听。

工具核对项目、完整容器 ID、镜像、持久化来源与应用身份后，通过非 Root PHP 管道保存通讯设置、托管 CA 口令和导入公共 CA。Web 进程不会获得 Docker socket、sudo 或宿主 Root 权限；Root CA 私钥不挂载到容器。

修改 IP 时，在插件页面保存后复制新版 Root 命令，或在上述命令追加 `--host` 明确给出已保存的地址。现有入口沿用原 CA，地址变化时重签服务器证书；网页保存本身不改变宿主入口。

## 4. 保留旧数据、重建容器和升级

本次自动转发／管理菜单更新的固定版本、保留卷构建/替换命令与节点更新见 [v2026.10.25.2 更新教程](upgrade-2026.10.25.2.zh-CN.md)。`docker-install.sh` 仅用于新装或同源码重试，不是跨版本升级入口。

所有升级前保留数据库、原 `APP_KEY`、公共 CA、运行卷、原镜像和宿主 Root 私有状态。不要运行初始化、删除 `.env` 或执行 `down -v`。

**旧安装缺少 `storage/app` 挂载时：**先停止但保留旧容器，导出其 `/www/storage/app/.` 到新的受保护宿主目录，核对并备份公共 CA 和插件记录，再把该已填充目录作为挂载源。新增空目录会遮住旧容器层内的数据。导出前不要删除或重新创建旧容器；数据库及其它卷也必须保持。此迁移与新装后的容器替换是不同场景。

容器替换后，在宿主机重复第 3 节的 `--docker` 命令并显式确认。工具通过原凭据与原 CA 私钥的本机匹配验证后重新绑定完整容器 ID，保留 CA、口令和节点信任；不会只按名字默默认领。若原 CA 私钥已离线移走，追加 `--ca-key /Root私有目录/ca.key.pem` 提供原加密私钥；不要上传到面板或挂载到容器。

升级目标源码需核对发行版说明并重新构建本地镜像，使用原 Compose、项目名和运行卷替换服务；仅 `docker compose pull` 不会更新本地构建源码。`docker-install.sh` 不用于不同源码版本之间的升级。该脚本生成的配置为 `compose.node-endpoint.yaml`，管理时保留其原项目名，不要改用目录默认项目名启动第二个项目。若需要回滚，应恢复已核对的镜像 / 源码及配套数据库、配置和运行数据备份，而不是只切换镜像标签。

## 5. 节点接入和计划任务

Root 入口验证成功后，在原生机器管理选择“IP + 私有证书”并复制新节点安装命令。节点命令会携带公共 CA，不必在每台新节点上重跑面板 Root 工具。已有业务节点按保留配置的迁移流程处理。

默认 Octane 提供周期调度。先检查后台计划任务状态是否自动更新；已有调度正常时不要再添加 cron。只有确认自动调度未运行并完成排查后，才设置唯一外部 `schedule:run` 调度；使用实际容器和非 Root 应用用户，避免重复执行。

宿主防火墙处理不代替云安全组或供应商防火墙；节点必须能访问实际通讯 TCP 端口。网站域名证书与节点私有 CA 是两套用途不同的证书。

## 支持与验证边界

自动流程首版不支持 host-network、分离服务、远程 Docker、任意服务名、公开 HTTP 后端或未认领的面板重装。未知或变化的上下文会停止，原版已有面板不能用新装脚本绕过检查。原生部署仍使用[PHP-FPM 教程](./aapanel.zh-CN.md)。

本版结果见 [自动部署与 Root 验证记录](./aapanel-docker-auto-validation.zh-CN.md)。[此前 Docker 基础与手工入口验证](./aapanel-docker-validation.zh-CN.md)保留其历史环境和边界，分别记录，不混算测试范围。
