# v2026.10.30.2 验证范围

目标版本为 NodeEndpoint `1.1.2`、Node `v1.13-orphan.9` 与独立 Node tools `1.0.11`。Root installer `v1.0.10`、Root tools `1.0.6`、本机助手 `1.0.0` 和其余四插件保持不变。

本轮处理已确认的混合大小写 ACME DNS 名称 Order / CSR literal 不一致。现场已确认 nftables `0.9.0`、内核 `4.19.0-21`：JSON 查询退出码为 0、返回 `nftables: null`，独立纯文本 `list tables` 也成功且为空。v9 只在该独立空表检查成立时兼容 null，其他异常、未知结构和非空结果仍拒绝。转发计划改用 `ip` / `ip6` 家族 NAT、数值优先级与未挂钩的完整所有权标记规则，避免依赖旧环境不支持的 inet NAT / table comment；仅对校验通过的旧 v8 inet 资源作受控迁移，不清空外部规则。

最终本地 v9 下载锁下，Windows PHP 8.2 / Laravel 12.69.3 原生组件 `36/36`、synthetic 安装命令生成 `4/4`、Node 迁移工具 Python `36/36`，插件 ZIP 原生上传 / 安装 / 启用与 HTTP 验收 `6/6` 通过。插件 ZIP `141` 成员、tools ZIP `101` 成员和固定 v9 关联检查通过；两次顺序构建逐字一致，旧五个 ZIP 保持原摘要。这些组件测试不执行真实 CA 或防火墙。

Node 正式源码 `39787deee9feccec4cdc368856969befd372facd`、二进制编译来源 `5d3911cbe318688cadf1ccd4848ab85cec95c34e` 和六项本地资产已固定。发行协调器已完成 Go `689` 项 / `16` 包、race `254` 项 / `5` 包、Node Python `7` 套共 `94` 项通过和 `1` 项可选外部镜像跳过。现代 nft `1.0.9` INPUT `11` 项（四后端）、NAT 生命周期 `1` 项及实际 v8 迁移 `4` 项；旧 nft `0.9.0` 在现代隔离内核上的 INPUT `11` 项（两 legacy 后端）、NAT 生命周期 `1` 项通过，合计 `28` 次 profile 执行，与单元 / race 计数分开。固定 Go `1.26.8` 下 Linux AMD64 Node / xbctl 与 Windows 构建逐字一致；ARM64 仅交叉构建。公开六资产的大小、SHA-256 和 GitHub digest 均与锁一致，tag 原始安装器与 Release 文件逐字相同。最终面板归档与本轮完整面板 PHP 套件结果见发行附件 JSON。不把[已发布 v2026.10.30.1](release-2026.10.30.1-validation.zh-CN.md)或 Node v8 的 Go、race、namespace、Python、PHP 和公开下载通过数归入本轮；ACME 库级无网络测试也不等于真实 CA 签发验收。

升级保留 `.env` / APP_KEY、CA、证书缓存、机器身份、业务数据、插件设置和禁用选择。Node 通过原配置升级，不替换已有身份；面板 Root 默认协调、显式 `--skip-endpoint`、专用系统 UID 和固定任务权限边界保持不变。原生 nftables / firewalld INPUT base chain、云安全组、Docker 端口发布、ARM64 业务、长期负载及生产迁移仍须按实际环境另行验证。

真实内核 4.19 尚未启动验收。隔离测试机未安装 QEMU，且没有 `/dev/kvm`，本轮没有安装主机包或启动嵌套虚拟机。旧 nft 0.9.0 CLI 在现代内核上的检查及官方能力文档分别记录，均不能替代实际 4.19 内核运行结果。
