# Hysteria2 自动转发与节点管理

配套版本：后端 `v2026.10.25.2`、NodeEndpoint `1.0.9` 和独立 Node `v1.13-orphan.6`。已有安装先按[更新教程](../installation/upgrade-2026.10.25.2.zh-CN.md)更新后端，再升级节点；无需卸载其他插件，也不重建 CA。

## Hysteria2 端口跳跃

在面板 Hysteria 节点选择版本 `2`，连接端口填单个升序范围，例如 `42450-42453`，服务监听端口填单个 `42443`，跳跃间隔例如 `5` 秒。面板通过 HTTP 与 NodeWorker 下发一致的范围；Node 自动维护范围到监听端口的 UDP 转发。客户端仍需支持端口跳跃并取得更新的订阅配置。

更改范围会替换自身转发；改成单端口会撤销自身转发。正常退出、重启和卸载清理自身规则；异常断电或 SIGKILL 无法立即清理，同一实例重新启动后核对并接管自身遗留规则。范围更新可能关闭已有代理连接，客户端需要重连。

Node 仅操作带完整 owner 标记的自身 nftables 表，不修改全局 INPUT 放行策略、不清空其他规则、不覆盖系统 nftables 保存文件。云安全组应允许广告 UDP 范围，宿主 INPUT 应允许转发后的实际 UDP 监听口。UDP 服务占用、其他节点范围重叠、外部 NAT 冲突、权限不足或无法可靠判断的复杂规则会停止应用新配置并保留当前内核，按正常同步周期重试。

环境要求为 Linux NAT、nftables、`CAP_NET_ADMIN` 和可写 `/run`。新安装器补装 nftables，并在替换 Node 前检查工具可执行；不会关闭 `NoNewPrivileges`。Docker 内的规则只作用于容器所在网络命名空间，容器权限、宿主端口发布和云安全组需单独配置，本版未验证新的 Node Docker 镜像。

旧面板不下发范围，旧 Node 忽略新字段。只更新 NodeEndpoint 插件不能替换后端或节点二进制，也不能使旧 Node 自动转发。

## 输入 node 管理

标准 Linux/systemd 节点升级后，在 SSH 终端输入小写：

```bash
node
```

```text
1) 在线更新到最新正式版本（保留绑定）
2) 重启节点
3) 卸载
4) 修改对接面板地址 / Token
5) 查看最近日志
6) 查看状态
0) 退出
```

每次执行一项操作后退出，再次输入 `node` 返回。非 Root 用户使用 sudo。原菜单 1、2、3 编号保留；已有 Node.js 的 `node` 命令时安装器拒绝覆盖或遮蔽，应先处理命令归属冲突。

选择 `4` 后，多个绑定先选择目标。面板地址或 Token 单项留空保留原值，全部留空取消。Token 隐藏输入，经 stdin 写入私有临时文件，不放进外部命令参数。先校验新地址的 TLS 与实际面板认证，不跟随重定向；通过后才备份、停服、替换并恢复原运行状态。原来停止的节点保存后仍停止；重启或健康检查失败尝试恢复旧配置。

只修改选中绑定，保留 machine/node ID、实例身份、其他绑定、数据目录与 CA。共用凭据的绑定更换 Token 时使用独立引用。迁移到另一台面板仍要求该面板具有对应身份及可信证书；沿用本机 CA bundle 不表示信任任意新证书，不能关闭证书校验。自定义 systemd drop-in、启动参数或不安全 YAML 会拒绝自动编辑。

配置与凭据权限 `0600`，私有备份目录 `0700`，旧凭据行和备份不自动销毁。选择 `5` 显示 `xboard-node.service` 最近 100 行日志；对外分享前须自行脱敏。

## 后续在线更新

首次升级到本版需要执行一次[固定版本升级命令](../installation/upgrade-2026.10.25.2.zh-CN.md#4-首次升级-node随后直接用菜单更新)。之后选择 `1`，从固定自维护仓库 `ksr-v/Xboard-Node--Custom-Source` 的最新正式 Release 取得同 tag 的 `install.sh` 和 `SHA256SUMS`，核对唯一摘要、资产来源和可用的 API digest，检查 Bash 语法后执行该安装器的 `upgrade`。Node 和 xbctl 继续使用安装器固定的二进制 SHA-256。

网络失败、部分下载、摘要异常或 API 限流会停止；不回退执行 dev 脚本。升级保留绑定、配置、CA 与健康端口，但会重启节点并中断已有连接；重复更新同一正式版同样会重启。后续每次正式发行须同时提供配套安装器、摘要清单和两个架构的 Node／xbctl。

## 验证范围

Hysteria2 自动转发完成 47 项专用 VM 检查，覆盖 sing-box／Xray、真实面板保存与 NodeWorker、连续 TCP／UDP、范围变更／关闭、冲突、权限拒绝和退出清理。管理菜单完成 13 项真实 PTY／systemd／Node／xbctl 联测，涵盖编辑成功与失败、回滚、停止状态保留、受控在线更新及篡改拒绝；Linux 竞态检查也通过。

完整证据见[自动转发报告](https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/blob/main/docs/HYSTERIA2_AUTO_FORWARDING_REPORT.md)和[管理菜单报告](https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/blob/main/docs/NODE_MANAGEMENT_TEST_REPORT.md)。实验使用独立测试环境，未访问生产节点或读取 Root 私有运行日志。公网更新、实际 ARM64运行、IPv6、商业客户端、长期压力、新 Node Docker 镜像和生产升级仍需在对应环境验证；开发联测不代表完整生产或灾难恢复验收。
