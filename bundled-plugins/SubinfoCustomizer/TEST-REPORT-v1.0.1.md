# SubInfoCustomizer v1.0.1 验证记录

日期：2026-10-29，Asia/Shanghai。

v1.0.1 用于作为面板第五个默认插件捆绑，补充 PHP 8.2 支持与 mbstring 明确预检。配置、数据库迁移、模板业务、订阅桥接和 Admin 集成策略沿用 v1.0.0；升级不重置保存的配置。

## 本地已执行

环境为 Windows PHP 8.2.33 CLI，通过命令参数临时加载 mbstring，不修改全局 PHP 配置。

| 检查 | 结果 |
| --- | --- |
| 12 个运行 PHP 文件语法 | 12/12 通过 |
| `tests/compatibility_checks.php` | 10/10 通过：当前 Admin/控制器、三种换行往返、重复操作、未知和损坏内容拒绝、原面板字节保全 |
| `tests/plugin_preflight_checks.php`，加载 mbstring | 5/5 通过：PHP 8.2 安装预检、未知 Laravel/Admin/核心拒绝、安装不应用桥接 |
| 同一预检，不加载 mbstring | 2/2 通过：缺扩展明确拒绝、未知 Laravel 拒绝 |
| `tests/static_checks.php . --templates-only` | 4/4 通过：Unicode/Emoji、0–4 行、非法模板与编码拒绝、单位边界 |
| `tests/admin_checks.mjs` | 10/10 通过：认证、编辑保存、零行、异步账号切换、禁用清理等实际脚本行为 |
| `tests/php82_hook_checks.php`，真实 Laravel 12.54.1 / SQLite | 12/12 通过：原生安装启停/升级、实际 HookManager 九变量和 0–4 行输出、同名去重、零节点、外来过滤器保留、损坏配置回退、未知核心失败禁用与原始字节恢复 |

检查源为主仓库 `f7e01f8fdd32c9b65060dc9cf6dcd5dc9c4086bc`。控制器 LF SHA-256 为 `3303d9696a44268e42e0f39371ff42aed87597da68d207c0847fccb98d270ece`。四条 Admin 唯一结构签名全部匹配；测试在临时目录中写入故障，未修改被核对的面板文件。

追加业务检查使用真实 Composer 锁定依赖、Laravel Application、原生 PluginManager、SQLite 插件记录与配置表，以及实际 HookManager 和 Eloquent User 类型；User 为受控内存实例，不是持久化或生产账号。更新回调检查将当前源码的版本记录设为 `1.0.0`，验证 cleanup → update → boot 的配置保留和唯一桥接，不将其冒充真实历史源码升级。真实历史源码接管由面板捆绑测试单独记录。原始结果位于 `tests/evidence/v1.0.1/`。

## 面板默认捆绑集成

面板 `v2026.10.29.1` 将本插件纳入第五项默认清单。Windows PHP 8.2.33 / Laravel 12.54.1 / SQLite 的捆绑生命周期回归 22/22、升级失败恢复 5/5 通过；旧版 v1.0.0 原生安装的两条升级路径只在 PHP 8.3 执行，未作为 PHP 8.2 的通过项计数。

独立 Linux aaPanel PHP-FPM 8.3.33 / Laravel 12.54.1 / MariaDB 10.11.10 站点从原生 `xboard:install` 完成五插件默认安装启用，真实 HTTP 125/125 与后端检查 11/11 通过。覆盖九变量、0–4 行、日期格式、权限、恶意输入、Base64/Clash/sing-box 真实节点字段保全、启禁升级、重复捆绑同步保留禁用选择，以及其余四插件记录、配置、后台资源和业务表摘要保全。

主面板的完整历史源码升级、制品摘要及归档本体部署结果见[同版验证记录](https://github.com/ksr-v/Xboard-Custom-Source/blob/v2026.10.29.1/docs/installation/release-2026.10.29.1-validation.zh-CN.md)及 Release 附件。这里的 HTTP 是实际 Nginx/PHP-FPM 请求，没有把 Laravel 内核调用记为 HTTP；前端 10 项为脚本行为测试，没有声称本次重新完成实际浏览器交互。

## 验证边界

PHP 8.2 Windows CLI 的结果不等于 Docker 或 Octane 生命周期验收。面板本次发行的原生 HTTP、升级、默认安装及独立插件接管结果以面板同版验证报告为准。此文件不把 v1.0.0 历史的 126 项 HTTP 与 43 项恢复检查计入本次。

没有重新执行完整 Docker PHP 8.2、真实代理内核、Node WSS 身份认证、NIC 采集器、外部扫码客户端、长期负载或开启 OPcache 的端到端验收。
