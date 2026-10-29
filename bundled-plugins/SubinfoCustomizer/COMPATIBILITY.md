# 兼容性说明

## v1.0.1 新增支持

v1.0.1 接受 PHP 8.2/8.3，要求 mbstring 和 Laravel 12。源码使用的 `readonly`、联合类型、`array_is_list()` 和字符串函数均可在 PHP 8.2 运行；安装和启用新增 mbstring 必需函数预检，扩展缺失时给出明确错误。

已重新核对主仓库 `f7e01f8fdd32c9b65060dc9cf6dcd5dc9c4086bc`（2026.10.28.1）：`ClientController.php` 的 LF 归一化 SHA-256 未变，Admin manifest 仍为 `index-CEIYH7i8.js`，四条唯一结构签名继续匹配。没有扩展控制器哈希或 Admin 签名白名单；未知版本、损坏桥接和未知 Admin 构建仍拒绝写入或启用。

本次本机 PHP 8.2.33 Windows CLI 验证覆盖预检、Unicode 模板、桥接往返、未知构建拒绝及语法；CLI 结果不等于 Docker、Octane 或 Linux PHP 8.2 HTTP 验收。本次完整结果见 [TEST-REPORT-v1.0.1.md](TEST-REPORT-v1.0.1.md)。以下内容记录 v1.0.0 的历史 aaPanel PHP-FPM 基准，不将历史统计当作本次新增验证。

## 支持基准

| 项目 | 固定基准 |
| --- | --- |
| 仓库 | `ksr-v/Xboard-Custom-Source` |
| 分支 / 版本 | `master` / `2026.10.25.2` |
| commit | `d1c3bb575b60bb74f78fadc7b21ef718dcee8f15` |
| PHP / Laravel | PHP 8.3 / Laravel 12 |
| 运行方式 | aaPanel、原生 PHP-FPM、Nginx/OpenResty、MySQL/MariaDB、Redis |
| Admin 构建 | `assets/index-CEIYH7i8.js`，同时验证唯一结构签名 |

实际环境版本、测试通过项、失败项和未验证项以 `TEST-REPORT.md` 为准。兼容性预检成功不等于完整业务验收。

本次在真实 aaPanel PHP-FPM 8.3.36 / Laravel 12.54.1 环境完成 126 项 HTTP 与 43 项最终恢复检查，全部通过。实际浏览器验证四插件入口与配置区；完整 Admin 资源、四插件记录和八张业务表前后摘要一致。未启动真实代理内核或采集器，因此不把外部代理/WSS/网卡采集链路标记为已验证。当前 FPM 未加载 OPcache，启用 OPcache 的环境仍需补测。

## 四插件共存

| 默认插件 | 捆绑版本 | 隔离原则 |
| --- | --- | --- |
| NodeEndpoint | 1.0.9 | 不改通讯设置、证书、入口或安装资产；链式保留其 Admin 视图 |
| SmartExpiry | 1.2.0 | 不改业务或快捷操作注入；核心桥接使用同一共享锁 |
| QRCodeExtend | 1.2.0 | 不改二维码业务、Admin 注入或公共资源 |
| FlowScope | 2.4.0 | 不改采集、数据库记录或后台集成；链式保留其 Admin 视图 |

SubInfoCustomizer 不修改 Admin 静态文件。它在视图渲染时注入自有资源，浏览器只管理自有 DOM 和隐藏标记。禁用/卸载不恢复旧 Admin 备份。所有默认插件的源码与清单保持原样。

新插件配置接口使用独立限流前缀，不与 NodeEndpoint 的计数混用。共享补丁锁仅用于协作写入核心桥接，卸载时不删除该锁。实际共存读取、操作和资源摘要的验证结果以测试报告为准。

## 订阅格式

信息行沿用面板原生“节点副本”表示，接入同一 `doSubscribe()` 链路。重点验证通用 Base64 与 Clash；其他格式保留原生协议过滤、名称前缀与协议构造逻辑，不实现订阅转换。

若客户端不支持第一条存活节点的协议，它仍可能按原生兼容性规则过滤信息副本；本插件不重构协议选择或伪造连接。没有真实节点时不生成信息行。启用协议名称前缀时，信息副本与真实节点均继续遵循面板原生前缀设置。

启用期间，原生信息开关和“过滤掉 N 条线路”提示不额外生成顶部节点；0 行或全部停用只隐藏信息行，真实节点仍按原筛选条件输出。框架原生的安装迁移、非事务上传与卸载批次限制见 `ROLLBACK.md`，这些边界不通过修改默认插件规避。

## 后续升级重验条件

以下任一变化均需重新审计、更新兼容性白名单并执行真实 aaPanel + PHP-FPM 测试：

- `ClientController.php` 内容或订阅调用顺序变化。
- Hook 参数、协议构造与原生信息表示变化。
- Admin manifest、构建名称、sidebar 或认证存储结构变化。
- `PluginManager` 的上传、生命周期、路由、迁移或资源发布行为变化。
- 四个默认插件的 Admin 集成、共享锁、版本或数据库结构变化。
- PHP、Laravel、站点用户权限或 OPcache 行为变化。

未知版本默认拒绝安装/启用；不应将其解释为对其他 Xboard 发行版的兼容承诺。
