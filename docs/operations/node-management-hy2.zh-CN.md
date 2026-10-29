# Hysteria2 自动转发、UDP INPUT 与节点管理

配套版本：面板 `v2026.10.30.2`、NodeEndpoint `1.1.2` 和独立 Node `v1.13-orphan.9`。已有节点按[升级教程](../installation/upgrade-2026.10.30.2.zh-CN.md)保留配置升级，不执行新装 `REPLACE`。Root installer `v1.0.10` / Root tools `1.0.6` / helper `1.0.0` 不变，无需重建 CA。

ACME 混合大小写修复与 nftables 输出兼容的最终范围及结果见[本轮验证记录](../installation/release-2026.10.30.2-validation.zh-CN.md)；后者已确认旧 nft 0.9.0 的成功 null 空表输出；候选仅在独立空表检查成立时兼容，4.19 内核尚未启动验收。以下 INPUT / NAT 能力继承 v8，不将历史通过数作为 v9 重跑结果。

## Hysteria2 端口跳跃

在面板 Hysteria 节点选择版本 `2`，连接端口填单个升序范围，例如 `42450-42453`，服务监听端口填单个 `42443`，跳跃间隔例如 `5` 秒。面板 HTTP 与 NodeWorker 下发相同范围，Node 维护范围到监听口的 UDP NAT。客户端还需支持跳跃并导入更新后的范围；部分订阅格式只提供单端口。

更改范围更新自身 NAT，改为单端口撤销自身 NAT。正常停止清理自己的规则；SIGKILL / 断电后同一稳定实例重启时核对遗留所有权。冲突、权限不足或不支持的布局拒绝自动处理，不覆盖外部规则。变更范围可能中断现有代理连接。

## 实际 UDP 监听口自动 INPUT

Node v8 在标准安装器显式启用后，管理本实例实际 Hysteria2 UDP 监听口的 INPUT 放行，包含未启用跳跃的单端口 HY2。上例 INPUT 只开放 `42443/udp`，不开放整个广告范围或 TCP；指定监听 IP 时限定对应地址与 IP 版本，放行允许所有来源访问该监听口。

支持 Linux 主机 iptables-nft / iptables-legacy 的 IPv4 与 IPv6。额外原生 nftables INPUT basechain 和 firewalld 本次不自动修改，需人工放行；独立 nft `accept` 无法覆盖其他链的 `drop`。环境需要 nftables、相应 iptables 工具、`CAP_NET_ADMIN` 和可写 `/run`，不关闭 `NoNewPrivileges`。Docker 内仅能作用于所在网络命名空间，容器权限和宿主端口发布另行处理。

旧 nftables `0.9.0` 的自动 INPUT 仅支持显式 legacy 工具；已有 nft compat INPUT 或额外原生 INPUT 链时会拒绝自动管理，不切换系统 alternatives。

自动 INPUT 管理使用进程级独占租约，同一网络环境只允许一个启用该能力的 Node 进程；该进程内可以有多个节点实例。多个独立 Node 服务不同时争抢规则，应由管理员手动管理其他服务的 INPUT。

标准安装或升级的 unit 使用 `Environment=XBOARD_NODE_MANAGED_INPUT=1`。安装器 `--skip-firewall` 设置为 `0`，已有 `0` 在升级中保留。本地配置关闭优先：

```yaml
firewall:
  managed_input: false
```

顶层配置继承给实例，实例可本地明确覆盖；环境变量仍是授予权限的总开关。自定义 unit / 直接运行没有该启用环境变量时默认关闭，仅 YAML `true` 不能授予防火墙权限。

Node 在启动、配置变更和每 `30` 秒独立检查中核对自身 INPUT / NAT；是否连接 WebSocket 不影响该周期。只为实际应用成功、内核运行且有用户的配置恢复规则；暂时失败保留旧状态并重试。撤销、停用或正常停止只删除本实例 owned 规则，不删除其他实例、管理员或提供商规则。

UFW 的 iptables 兼容布局与 iptables-persistent / netfilter-persistent 重载可能移除运行时规则，后续周期可恢复，但存在连接中断窗口。Node 不修改 `/etc/iptables/rules.v4` / `rules.v6`、UFW 或提供商持久文件，不保存整个 ruleset，不改变 INPUT 默认策略。云安全组、上游 UDP 放行仍须允许广告范围，Node 无法处理主机外的拦截。

关闭跳跃后单端口仍不能连接时，先确认版本、实际地址、UDP 监听、TLS / SNI 与认证，再区分 INPUT 和上游拦截。只读检查：

```bash
/usr/local/bin/xboard-node -v
systemctl is-active xboard-node.service
ss -lun '( sport = :42443 )'
```

使用实际服务名和监听口。不要公开订阅 URL、Token、密码、私钥、完整规则集或原始业务日志。面板 Root 网页任务管理的是批准的面板 TCP 通讯入口，不能代替节点上的 HY2 UDP INPUT。

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

首次升级到本版需要执行一次[固定版本升级命令](../installation/upgrade-2026.10.30.2.zh-CN.md#节点与插件验收)。之后选择 `1`，从固定自维护仓库 `ksr-v/Xboard-Node--Custom-Source` 的最新正式 Release 取得同 tag 的 `install.sh` 和 `SHA256SUMS`，核对唯一摘要、资产来源和可用的 API digest，检查 Bash 语法后执行该安装器的 `upgrade`。Node 和 xbctl 继续使用安装器固定的二进制 SHA-256。

网络失败、部分下载、摘要异常或 API 限流会停止；不回退执行 dev 脚本。升级保留绑定、配置、CA 与健康端口，但会重启节点并中断已有连接；重复更新同一正式版同样会重启。后续每次正式发行须同时提供配套安装器、摘要清单和两个架构的 Node／xbctl。

## 验证范围

本轮 v9 的 Node 回归、本地与公开资产核验及面板发行附件，见[验证范围](../installation/release-2026.10.30.2-validation.zh-CN.md)。已发布 [v8 配套记录](../installation/release-2026.10.30.1-validation.zh-CN.md)保留其 Go、race、namespace、Python、PHP 和公开下载结果，不当作本轮通过数。实际 CA 签发、原生 ARM64、商业客户端、Docker、长期负载和生产迁移仅在有对应证据时记录为通过。
