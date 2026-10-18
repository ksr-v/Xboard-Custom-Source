# 内置插件候选版验证记录

日期：2026-10-18。功能包版本：2026.10.18.4；实际源码发行版本使用 `日期-commit前7位`，见发行附件的 `latest.json` 和包内 `build.json`。

## 已通过的验证

| 范围 | 结果 |
| --- | --- |
| 插件集成，Windows PHP 8.2 / Linux PHP 8.3 | 13 项通过；Linux 以站点用户 `www-data` 执行 |
| 后台归档更新回归 | 5 项通过，两平台均执行 |
| Root 安装、归档、防火墙和确认检查 | Linux 47 项全部通过；Windows 43 项通过，跳过四项 Linux 权限 / PHP / 符号链接检查 |
| 隔离 PHP-FPM 站点从零安装 | 完整 Root 入口执行成功，四插件默认安装启用 |
| 重复 `update.sh --deployed-code` | 成功，原 `.env` / APP_KEY、PKI 文件、插件身份与状态、公共 CA 摘要保持 |
| 校验 SHA-256 的完整归档升级 | 成功，创建 Root 私有源码与数据库恢复副本，保留外来插件，退出维护模式 |
| 真实 IP HTTPS | 使用自动导入的公共 CA 验证 IP SAN 并到达 Xboard 鉴权接口 |
| Root CA 隔离 | 应用用户无法读取 Root CA 私钥 |

完整安装和归档升级使用用户授权的测试虚拟机 `192.168.128.129`，在独立临时目录启动单独的 Nginx / PHP-FPM，使用隔离 SQLite。临时通讯端口为 19843。未访问或升级生产面板。

2026.10.18.2 的修补验证使用官网摘要校验过的固定 Composer 2.10.3。测试前把新隔离环境的 Root 缓存父目录设为 0700，完整新装流程将其修复为 0755，并成功完成四插件安装、HTTPS 和公共 CA 导入；随后重复升级保留 `.env` / APP_KEY、PKI 和插件状态。独立 Root 测试还验证了首次创建、已有 0700 父目录修复、真实降权写入本人缓存以及不能读取另一 UID 的 0700 缓存。

版本检查覆盖 Composer 1.x、2.0、2.1 在依赖安装和 Artisan 之前停止，并显示明确的 `--composer` 恢复提示；2.2 及较新 2.x 版本通过检查。修补没有改动 `composer.json` 或 `composer.lock`，未运行依赖更新。

本机 UFW 原为未启用、空用户规则表。验证了首次窄范围 TCP 规则保存、重复操作以及保持 UFW 未启用；不据此声称公网和云安全组连通。固定旧工具包保持原摘要，空表兼容由独立摘要的 Root 防火墙补丁提供。

插件集成使用实际 Composer ClassLoader、Laravel、SQLite 迁移和原生 PluginManager。真实 NodeEndpoint 1.0.0 的 16 个文件从本地已发布 Git tag 读取，逐个匹配认领清单；旧版原生安装、源码升级和原生升级在三个独立 PHP 进程执行。启用和禁用两种状态均验证，配置、记录 ID、安装时间、公共 CA 以及无关文件保持。已修改旧源码会在任何插件复制之前拒绝。

二维码源码固定为审计副本 1.2.0。集成检查确认二维码、SmartExpiry 补丁与 NodeEndpoint / FlowScope 后台入口同时存在；这项检查不等同于所有浏览器交互验收。FlowScope 的五个节点采集器资产纳入来源摘要。

后台更新检查覆盖：应用升级子进程失败后恢复旧版本标记和缓存、同一发行版可重试、完成后才提交新版本、非法标记不覆盖，以及数据库备份进程非零退出时阻止源码变更。

## 2026.10.18.3 已验证的修补

本次修补继续使用授权测试虚拟机 `192.168.128.129` 的隔离 PHP-FPM / SQLite 站点，已验证以下实际流程：

- 在所选 PHP CLI 中禁用 `symlink` 和 `exec`，新装预检明确停止；停止发生在创建 `.env`、运行 Composer 和初始化数据库之前。恢复 PHP 配置后才继续新装。
- 主程序安装完成后，在 Root 计划确认中先输入不完整的 `INSTALL`，再输入含错误 IP 的短语；两次均提示不匹配并重新询问。随后输入 `CANCEL` 正常取消，没有生成 PKI 文件或 Nginx 独立监听配置。取消后可能保留 Root 私有空目录及工具元数据，这些不表示证书或入口已安装。
- 对已经安装的面板使用 `update.sh --deployed-code` 继续。在确认处先输入 `INSTALL`，再次提示后输入计划对应的完整短语，成功完成独立 IP HTTPS、公共 CA 导入和防火墙配置。原 `.env` / APP_KEY 的摘要保持不变，四插件均启用，二维码仍为审计副本 1.2.0。
- 使用导入的公共 CA 验证实际 IP HTTPS 通讯，应用用户仍无法读取 Root CA 私钥。
- 再次执行非交互升级成功，`.env` / APP_KEY、PKI 密钥和证书、插件身份和启停状态、公共 CA 摘要均保持；Linux 47 项 Python 检查、13 项插件集成及 5 项归档更新回归全部通过。

这些结果验证了错误确认后继续输入、主动取消以及已有安装的恢复流程；不把 Root 确认中止视为应用安装回滚，也不通过删除 `.env` 或重新生成 APP_KEY 重试。

## 2026.10.18.4 CA 口令自动托管

NodeEndpoint 升至 1.0.4，其他三插件版本保持。新 CA 的随机口令先按面板 APP_KEY 加密保存在独立表，再通过非 Root PHP 管道交给 Root 签发；Root 只保存凭据 ID 和 CA 绑定。旧 CA 不生成替代口令或更换证书。

授权虚拟机完整新装只回答计划确认，未出现隐藏口令提示；64 字符随机口令已加密入库，并通过真实 OpenSSL 验证与 CA 私钥匹配。无需输入口令的加密 CA 导出、IP HTTPS、公共 CA 导入、防火墙和重复升级均通过；APP_KEY、PKI、公共 CA 及四插件身份 / 状态保持。应用用户不能读取 CA 私钥。

Windows 与 Linux 各 106 项 Root 工具检查通过，涵盖托管中断恢复、双 CA、旧 CA 不换口令及真实 OpenSSL。独立插件的 36 项原生组件、16 项真实 Laravel Crypt / SQLite / 管理员权限回归通过；7 项 Chrome 行为检查使用真实插件页面和模拟 HTTPS/API，覆盖显式查看 / 复制、超时清空、移动布局和鉴权清空。后端 Linux 47 项检查、13 项插件集成和 5 项后台归档回归通过。

## 验证边界

本轮没有运行完整 Docker / Octane 安装、MySQL / PostgreSQL 安装、整机灾备恢复、旧 Go UUID / Observer 回归、客户端代理流量、订阅消费或每个插件的全部业务场景。归档升级测试保留的数据库备份和源码副本不能代替生产备份恢复演练。

FlowScope 节点采集器仍需在对应节点安装；面板安装不会替未知远端节点运行 Root 操作。既有支付网关继续沿用原生插件逻辑，不配置支付密钥。

## 复现组件检查

使用本地可信 Composer 依赖；以下应用检查以站点用户运行。历史源码参考指向用户控制的 NodeEndpoint 本地仓库，其中需有已发布的 `v1.0.0` tag，不会联网拉取：

```bash
runuser -u www-data -- env \
  XBOARD_TEST_VENDOR=/本地依赖/vendor/autoload.php \
  NODE_ENDPOINT_REFERENCE=/本地/NodeEndpoint仓库 \
  php tests/BundledPlugins/integration.php

runuser -u www-data -- env \
  XBOARD_TEST_VENDOR=/本地依赖/vendor/autoload.php \
  php tests/BundledPlugins/update_retry.php

python3 -m unittest discover -s tests/BundledPlugins -p 'test_*.py'
```

完整安装命令、权限约束、原版面板升级路径和失败恢复边界见 [安装与升级说明](bundled-native.zh-CN.md)。
