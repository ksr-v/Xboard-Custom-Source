# 内置插件候选版验证记录

日期：2026-10-18。功能包版本：2026.10.18.1；实际源码发行版本使用 `日期-commit前7位`，见发行附件的 `latest.json` 和包内 `build.json`。

## 已通过的验证

| 范围 | 结果 |
| --- | --- |
| 插件集成，Windows PHP 8.2 / Linux PHP 8.3 | 13 项通过；Linux 以站点用户 `www-data` 执行 |
| 后台归档更新回归 | 5 项通过，两平台均执行 |
| Root 安装、归档和防火墙检查 | Linux 28 项通过；Windows 跳过两项原生符号链接检查 |
| 隔离 PHP-FPM 站点从零安装 | 完整 Root 入口执行成功，四插件默认安装启用 |
| 重复 `update.sh --deployed-code` | 成功，原 `.env` / APP_KEY、PKI 文件、插件身份与状态、公共 CA 摘要保持 |
| 校验 SHA-256 的完整归档升级 | 成功，创建 Root 私有源码与数据库恢复副本，保留外来插件，退出维护模式 |
| 真实 IP HTTPS | 使用自动导入的公共 CA 验证 IP SAN 并到达 Xboard 鉴权接口 |
| Root CA 隔离 | 应用用户无法读取 Root CA 私钥 |

完整安装和归档升级使用用户授权的测试虚拟机 `192.168.128.129`，在独立临时目录启动单独的 Nginx / PHP-FPM，使用隔离 SQLite。临时通讯端口为 19843。未访问或升级生产面板。

本机 UFW 原为未启用、空用户规则表。验证了首次窄范围 TCP 规则保存、重复操作以及保持 UFW 未启用；不据此声称公网和云安全组连通。固定旧工具包保持原摘要，空表兼容由独立摘要的 Root 防火墙补丁提供。

插件集成使用实际 Composer ClassLoader、Laravel、SQLite 迁移和原生 PluginManager。真实 NodeEndpoint 1.0.0 的 16 个文件从本地已发布 Git tag 读取，逐个匹配认领清单；旧版原生安装、源码升级和原生升级在三个独立 PHP 进程执行。启用和禁用两种状态均验证，配置、记录 ID、安装时间、公共 CA 以及无关文件保持。已修改旧源码会在任何插件复制之前拒绝。

二维码源码固定为审计副本 1.2.0。集成检查确认二维码、SmartExpiry 补丁与 NodeEndpoint / FlowScope 后台入口同时存在；这项检查不等同于所有浏览器交互验收。FlowScope 的五个节点采集器资产纳入来源摘要。

后台更新检查覆盖：应用升级子进程失败后恢复旧版本标记和缓存、同一发行版可重试、完成后才提交新版本、非法标记不覆盖，以及数据库备份进程非零退出时阻止源码变更。

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
