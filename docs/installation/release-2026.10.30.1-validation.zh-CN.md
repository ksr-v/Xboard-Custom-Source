# v2026.10.30.1 当前验证范围

日期：2026-10-30，Asia/Shanghai。目标版本为 NodeEndpoint `1.1.1`、独立 Node `v1.13-orphan.8`；其余四插件、Root installer `v1.0.10`、Root tools `1.0.6` 和本机助手 `1.0.0` 保持不变。

本版为 Hysteria2 实际 UDP 监听口增加 owned INPUT 放行和启动、配置变化、`30` 秒周期恢复；包含不启用跳跃的 Hysteria2。支持与关闭边界见[操作说明](../operations/node-management-hy2.zh-CN.md)。

独立 NodeEndpoint `1.1.1` 的定版源码提交为 `c0908a7ca6ef9f448419a150cd6debf067318eca`。本地 Windows PHP 8.2 / Laravel 12.69.3 原生插件组件 `36/36`、synthetic 安装命令生成 `4/4` 通过；独立迁移工具 Python `36/36` 另行通过，不与 Node 源码 Python 合算。插件最终 ZIP 实际原生上传、安装及启用 `6/6` 通过，逐字核对 `139` 成员；独立 tools `1.0.10` 为 `99` 成员，两包共用已核对的 v8 发行锁。记录见独立仓库 `dist/verification/node-v8-plugin-release-20261030.json`。这些检查使用隔离 SQLite 与 synthetic 身份，不执行真实 Node 安装、防火墙或代理内核，也不替代面板捆绑归档验收。公开插件下载是否完成以对应发行附件记录为准，不与本地 ZIP 安装测试混算。

Node 本轮隔离 Linux 源码检查为 Go `599` 项 / `15` 包、race `129` 项 / `2` 包、真实 namespace 防火墙流量 `9` 项，Node 源码 Python `94` 项通过 / `1` 项可选跳过。Linux AMD64 程序在 Linux 与 Windows 主机逐字重现，ARM64 只交叉构建；这些结果由发行协调器提供，不当作面板归档或商业客户端验收。Node 正式六项公开资产已无凭据下载核对大小、SHA-256 与 GitHub API digest，tag 原始安装器与 Release 一致。

面板正式归档摘要、插件来源锁、逐成员校验、组件和隔离环境验收结果记录于随发行 JSON。面板 PHP fixture 使用全新 SQLite，包含28项捆绑集成和5项更新重试；是否通过、实际运行归档 SHA 与源码提交以该 JSON 为准，不预先计为通过。真实 `1.1.0` 标签到 `1.1.1` 的启用/禁用保全检查使用独立 PHP 进程，覆盖插件身份、设置、公共 CA、vault、不完整任务归属及业务机器记录。

Root installer、不可变 Root tools 和助手保持不变。本轮**未重跑助手 RPC、网页 UI、助手参与的完整原生新装或归档升级**；历史 [v2026.10.29.2 验证](release-2026.10.29.2-validation.zh-CN.md)单独保留，其 Root 就绪轮询、真实网页任务和完整归档保全结果不计为本次重跑。面板包内既有 Root 资产的逐字一致性与固定摘要验证，和真实 Root 生命周期验收分别记录。

9项真实 netns 检查覆盖 nft/legacy 的 v4/v6 工具共存、原有 REJECT、真实双栈 UDP 与范围约束、旧 owned 资源回收、多 owner 幂等、删除 jump 后修复、端口/地址变化、额外原生 INPUT 链拒绝、NAT+INPUT 实际报文及清理后外来规则和 policy 保留。Service 重试与失败补偿另有 Go 测试，不能将这些结果称为商业 HY2 客户端或任意系统防火墙管理器验收。额外原生 nftables/firewalld INPUT base chain 属于人工处理范围；云安全组、Docker 端口发布、原生 ARM64 业务、长期负载和生产迁移没有由上述测试证明。

面板新装仍自动安装启用五插件，原生初始化处理 HTTPS 入口和受限助手；默认 Root 归档升级保留 APP_KEY、CA、业务数据、机器身份、设置和禁用选择，并处理已有有效原生入口的助手。`--skip-endpoint` 保留明确延期语义。网页 Root 助手只管理批准的面板 TCP 入口；本次 UDP INPUT 由节点上的 Node 实例管理，两者不混用。
