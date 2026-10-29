# v2026.10.29.1 验证范围

日期：2026-10-29，Asia/Shanghai。本版默认加入 SubInfoCustomizer `1.0.1`，保留原四插件与独立 Node 版本。源码锁记录固定独立提交、完整文件摘要及官方 SubInfo `v1.0.0` 接管来源。

## 本轮结果

| 检查 | 结果 | 环境与范围 |
| --- | --- | --- |
| 默认五插件生命周期、来源保全与后台共存 | PHP 8.2：22/22；PHP 8.3：24/24 | 真实 Composer / Laravel 12.54.1 / 独立 SQLite fixture；8.3 额外执行旧 SubInfo 真实源码已启用/禁用两路升级 |
| 升级失败 marker/cache 恢复与重试 | PHP 8.2：5/5；PHP 8.3：5/5 | `tests/BundledPlugins/update_retry.php`；不调用生产更新源 |
| 原生安装后的实际 HTTP | 125/125 | Linux、aaPanel Nginx、PHP-FPM 8.3.33、Laravel 12.54.1、MariaDB 10.11.10、Redis；全新独立应用和数据库 |
| 原生站点后端边界 | 11/11 | 同一应用的 Laravel CLI；不计作 HTTP |
| SubInfo 原生 HookManager 业务 | 12/12 | PHP 8.2.33 Windows CLI / Laravel / SQLite；受控 Eloquent User 实例 |
| SubInfo 前端脚本行为 | 10/10 | 实际脚本适配器的认证、编辑、保存、异步账号切换与禁用清理；不是本轮浏览器验收 |
| SubInfo 兼容、环境、缺 mbstring、模板与语法 | 10、5、2、4、12 项全部通过 | PHP 8.2.33；临时命令配置，不修改全局 PHP 配置 |
| 五插件来源锁 | 98 个文件全部匹配 | SubInfo 22 个当前 Git blob、21 个旧版 Git blob及两个独立 ZIP 的受管字节匹配；原四项清单完全未改 |

原始检查的公开摘要见 [evidence/v2026.10.29.1](../releases/evidence/v2026.10.29.1/)。不把 v1.0.0 历史的 126 项 HTTP / 43 项恢复检查合并为本版计数。

## 默认安装、升级与保全

`xboard:install` 在新建的隔离 MariaDB 数据库中，以网站用户完成五插件安装与启用。网站仅监听测试机 loopback，通过实际 Nginx / PHP-FPM 请求运行功能测试；未覆盖或更改其他既有网站。测试依赖来自完全一致的 `composer.lock`，没有运行 `composer update`。

已验证：

- 0–4 行、九变量、两种日期格式、排序、单行开关、保存持久化与取消草稿逻辑；权限和非法模板输入拒绝。
- Base64、Clash、sing-box 保持真实节点的连接字段；0 行和无可用节点不会伪造或移除真实节点。
- 启用、禁用、再启用、升级与重复捆绑同步；已有模板、日期格式及管理员禁用选择保留；显式 `--enable-all` 正常。
- 官方 SubInfo `v1.0.0` 的完整 Git 源码在独立 PHP 8.3 子进程中安装，再由当前源码接管升级；已启用和禁用两路均保留 ID、安装时间、config、模板和额外操作员文件。独立 ZIP 额外的 `tests/` 文件可保留。
- 未知修改在任一插件源码写入前拒绝，较新版不降级；完整默认插件目录被删除后可重新部署，部分缺失和空目录仍拒绝。
- 主程序归档刷新清洁控制器后只重新插入一个桥接；未知核心不被替换。三个 Admin 动态 loader 各出现一次，二维码与 SmartExpiry 的现有后台补丁共存。
- 其余四插件启用状态、设置及八张业务表、Admin 文件、用户、节点和机器身份在本轮 HTTP 生命周期测试前后摘要一致。

## 制品验证

`scripts/build-private-release.py` 从干净、已提交的目标 HEAD 构建，核验五插件全部文件及固定 Root 资产摘要，排除 `.env`、vendor、storage、已安装插件目录和私钥。构建标识使用真实提交 UTC 日期与短 SHA，独立于人类可读 tag。

正式归档验收曾在写入前发现 Windows `autocrlf` 导致依赖文件及 shell / Python 入口转换为 CRLF，Linux 升级器据此正确拒绝。该候选未发布，未改动应用源码或数据库。最终源码通过 Git attributes 与构建命令固定 LF，并重新构建、逐字节核对依赖及入口文件后执行归档验收；没有放宽部署器的依赖保护。

正式附件 `ARCHIVE-VERIFICATION.json` 记录该 `xboard.tar.gz` 的精确摘要、大小、源码提交、全部归档成员和五插件清单核对结果。`DEPLOYMENT-VERIFICATION.json` 单独记录同一摘要归档在本次隔离原生应用上的真实升级及保全验收，不以候选快照的结果代替正式附件的制品测试。发行后还核对 GitHub 资产摘要与实际下载字节。

## 验证边界

PHP 8.2 的完整管理器与 hook 测试运行于 Windows CLI，未重新构建或启动 Docker / Octane；因此没有额外发布未经本轮复验的 Docker 镜像。原生测试使用 Nginx / PHP-FPM 8.3，OPcache 未加载；没有把它外推为启用 OPcache 的验收。

本轮未重跑真实浏览器交互、代理内核流量、Node WSS 身份认证、NIC 采集器、外部客户端扫码导入、ARM64、长期负载或生产迁移。配套 Node 保持 `v1.13-orphan.7`；本版插件捆绑不要求已使用该版本的机器重装、换身份或重建 CA。

原生默认安装和正式归档升级不等于 Root NodeEndpoint 私有 TLS 入口的全新签发验收。使用全新隔离应用完成测试，未更改全局 PHP 安全限制、现有业务网站或公共 CA。
