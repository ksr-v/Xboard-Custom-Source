# SubInfoCustomizer v1.0.0 测试报告

测试日期：2026-10-27，时区 Asia/Shanghai。

## 结论

已在指定仓库的真实 aaPanel + PHP 8.3 PHP-FPM 隔离站点完成插件上传、安装、功能、四插件共存、异常恢复及最终卸载验证。真实 HTTP 检查 **126/126**，最终恢复检查 **43/43**；Laravel 后端检查 **11/11**，前端脚本行为检查 **10/10**，临时 CLI 纯逻辑检查 **8/8**。这些统计分别记录，不用 CLI 结果替代 PHP-FPM 验收。

最终恢复后，本插件数据库记录、源码目录、自有公开资源和桥接标记均已移除；四个默认插件仍启用。Admin 资源、默认插件配置与八张业务表、用户、节点和机器身份数据摘要与测试基线一致。真实代理流量及独立采集器的外部链路不在此次已验证结论内，详见“验证边界”。

## 实际环境与基准

| 项目 | 实际值 |
| --- | --- |
| 仓库 / 分支 | `ksr-v/Xboard-Custom-Source` / 执行开始时最新 `master` |
| commit | `d1c3bb575b60bb74f78fadc7b21ef718dcee8f15` |
| 主仓库发行说明版本 / 运行构建标识 | `2026.10.25.2` / `20261025-d1c3bb5` |
| 测试机 | 用户授权的 `192.168.128.128`；Ubuntu 24.04.3 LTS；既有 aaPanel，站点 ID 1 |
| 站点 | `http://192.168.128.128:8080`；`/www/wwwroot/subinfo-test`；站点用户 `www` |
| HTTP 链路 | aaPanel Nginx 1.28.3 → `/tmp/php-cgi-83.sock` → PHP-FPM |
| PHP CLI / FPM | 均为 `8.3.36`；真实 socket 探针确认 `fpm-fcgi` |
| Laravel | `12.54.1`，原 `composer.lock`；固定 Composer `2.10.3`，未运行 `composer update` |
| 数据库 | MariaDB `10.11.14`，Laravel `mysql` 驱动，专用数据库和账号 |
| Redis | Redis `7.0.15`，PHP 扩展 `6.2.0`，连接检查通过 |
| 后台任务 | Supervisor/Horizon 与分钟计划正常运行 |
| 应用时区 | `Asia/Shanghai` |
| OPcache | 当前 FPM 未加载 Zend OPcache；未改动其配置 |

按上游 `aapanel.zh-CN.md` 和 `bundled-native.zh-CN.md`，从固定 SHA 获取源码并执行原生 `init.sh`。包含 NodeEndpoint 8443 独立入口及公共 CA 导入。没有使用 Docker、Octane、Windows PHP 或应用 SQLite。

FPM 保留默认禁用函数。安装/维护及队列只使用各自的最小 CLI 配置，未全局放宽 PHP 限制。曾被自动审批拒绝的全局配置动作未执行；缩小作用域的替代已获审批并完成，无遗留阻塞。详细说明和全局 ini 摘要见 [环境总结](tests/evidence/environment-summary.md)。

临时测试机 `.129` 仅安装 PHP 8.3.6 CLI/mbstring/xml 做纯逻辑与语法检查，未作为 Web 集成验收环境。

## 四个默认插件

| 插件 | 实际版本 | 验证状态 |
| --- | --- | --- |
| NodeEndpoint | 1.0.9 | 全程启用；设置 API、独立管理员设置页、CA 状态和机器对接选项正常 |
| SmartExpiry | 1.2.0 | 全程启用；用户编辑中的长期/1/3/6/9/12 月选项存在，六个月草稿计算正常，取消后数据库未变 |
| QRCodeExtend | 1.2.0，捆绑审计副本 | 全程启用；用户菜单入口正常，二维码图像完整加载为 280×280，Hy2 协议切换正常 |
| FlowScope | 2.4.0 | 全程启用；仪表盘周期流量、服务器编辑配置区、机器列表及详情 API 正常 |

不修改这些插件业务代码、版本、默认安装器或 manifest。对每个生命周期操作检查四插件状态；对八张默认插件业务表进行只含摘要和行数的前后比较。完整 Admin 目录包含入口、manifest、locales 和所有 bundle，前后摘要一致。

## 实际执行命令

本地验证：

```powershell
node tests/admin_checks.mjs
node scripts/package.mjs
```

aaPanel 站点测试，以 Root 调用站点用户执行；脚本位于 Web 根目录之外：

```bash
runuser -u www -- /opt/xboard-tools/php-subinfo-cli \
  /opt/subinfo-tests/fixtures.php /www/wwwroot/subinfo-test seed /tmp/subinfo-fixtures.json

runuser -u www -- python3 /opt/subinfo-tests/http_integration.py \
  --base http://127.0.0.1:8080 --app /www/wwwroot/subinfo-test \
  --php /opt/xboard-tools/php-subinfo-cli \
  --fixtures /tmp/subinfo-fixtures.json \
  --zip /opt/subinfo-tests/SubInfoCustomizer-v1.0.0.zip

runuser -u www -- /opt/xboard-tools/php-subinfo-cli \
  /opt/subinfo-tests/backend_checks.php /www/wwwroot/subinfo-test

runuser -u www -- python3 /opt/subinfo-tests/final_restore.py \
  --base http://127.0.0.1:8080 --app /www/wwwroot/subinfo-test \
  --php /opt/xboard-tools/php-subinfo-cli
```

插件通过实际 `POST /api/v2/{后台路径}/plugin/upload` 上传 ZIP，再使用原生 install/enable/disable/upgrade/uninstall/delete API。测试请求穿过实际 Nginx 与 FPM，没有将 Laravel 内核直接调用当作 HTTP 验收。

`tests/evidence/aapanel-php-lint.txt` 记录已安装插件、生成桥接的控制器和夹具共 15 个文件的 PHP 8.3.36 语法检查；临时 CLI 检查命令及版本另见 `static-cli.json`、`static-lint.txt`。

## 功能与安全结果

| 检查 | 结果与证据 |
| --- | --- |
| ZIP 上传、安装及启用 | 通过原生 API；重复上传/重复安装安全拒绝 |
| 0、1、2、3、4 行 | HTTP 输出数量准确，每次真实节点 URI 完整一致 |
| 原生过滤提示 | 0–4 行均覆盖带节点筛选的请求，未额外生成“过滤掉 N 条线路” |
| 九个动态变量 | 长期、有限期、超额流量三个真实数据库用户，两种日期格式逐项对照原生余额/重置计算和独立单位换算 |
| 流量单位和负值 | T/G/M 等二进制换算；原生 User 模型余额最低为 0，无重复扣减 |
| 无重置与 0 天 | 无重置经真实 HTTP 验证；0 天由真实原生重置服务的受控 CLI Carbon 时钟验证，输出 `0` 与 `无` 区分 |
| 排序与单行开关 | HTTP 和浏览器均验证 |
| 保存及持久化 | POST 后 GET 一致，浏览器刷新仍为已保存的四行与日期格式 |
| 取消、恢复默认、零行预览 | 实际浏览器验证，默认需保存后生效 |
| 权限 | 未登录及普通用户配置请求均 403；禁用/卸载后不可访问 |
| 输入安全 | 五行、未知变量、表达式、PHP/HTML 标签、换行、NUL、错误 UTF-8、超长和非法日期格式均拒绝 |
| 名称冲突 | 后端精确重名测试通过，仅调整自有信息名，最终名称长度受限 |
| Base64 | 真实接口可严格解码，信息数量/名称正确，真实节点 URI 保持一致 |
| Clash | 独立 PyYAML 成功解析，信息顺序与数量正确，真实代理完整字段与原生基线相同 |
| sing-box | 原生 JSON 可解析，真实 outbound 完整字段未变化 |
| Admin 兼容 | 三个动态视图 loader 各一次；共享资源字节不变；实际浏览器四插件入口可用 |

测试用户来自独立测试数据库。节点是用于协议输出检查的真实数据库记录，其地址为本机测试地址；没有冒充已运行的代理服务。UI 示例明确标识，正式订阅从当前请求用户读取真实账户记录。

## 生命周期与恢复结果

- 安装 → 启用 → 禁用 → 再启用通过；禁用后订阅与安装前原生输出一致，配置保留。
- 将本插件数据库版本设为 `0.9.0`，调用真实原生 upgrade 升至 `1.0.0`，配置保留。此为生命周期模拟，不声称测试了不存在的旧版发行包。
- 在隔离站点给核心追加合法的故障测试注释，启用和升级均按预期拒绝，保留未知文件内容，只将本插件置为禁用。移除测试注释后正常恢复；没有绕过版本校验。
- 重复禁用、重复卸载安全完成；最终通过原生 delete 删除本插件源码。
- 最终核心 LF 归一化 SHA-256 为 `3303d9696a44268e42e0f39371ff42aed87597da68d207c0847fccb98d270ece`，与原始仓库一致。
- 已打开的浏览器最终 `menuCount/pageCount/hiddenMarks/ownerNodes` 均为 0；宿主页面与健康队列恢复。
- 四插件、八张业务表、Admin、用户、节点和机器身份全部通过摘要比较。无用户数据丢失或默认插件降级。

## 测试中发现的问题与处理

1. 共存审查发现数值限流可能与 NodeEndpoint 共用管理员计数，已为本插件加入 `subinfo-customizer:` 独立前缀；不修改默认插件。
2. 修复页面模板长度、服务端日期预览、配置损坏提示和账号切换时的异步鉴权竞态；前端 10 项行为检查通过。
3. 首轮 HTTP 连接参数比对失败：原生 `Helper::getTlsFingerprint(null)` 每次随机选择 TLS 指纹，导致测试 Trojan URI 不稳定。诊断确认 0 行及即时启禁用本身正常；将测试节点明确配置为 `utls: chrome` 后重建基线，126 项全通过。未修改产品或上游协议行为。
4. 修复测试夹具的 ServerGroup mass assignment；将测试脚本放到 `/opt/subinfo-tests`，避免放宽 `codex` 家目录权限。
5. 原生 Git 新装与受限 FPM 初始显示 `unknown` 版本。使用真实提交时间与官方构建算法写入官方归档元数据并刷新版本缓存，未改业务代码。

故障注入产生的预期插件错误日志不等于 PHP-FPM fatal。已检查的服务日志范围及最终健康摘要见证据目录。

## 验证边界

- 本次未启动真实代理内核或 FlowScope NIC Collector，未进行真实代理连通、Node 身份认证/WSS、网卡流量采集或外部客户端扫码导入的端到端测试。四插件共存结论覆盖启用状态、资源/配置保全、实际后台操作和 API。
- 当前环境未启用 OPcache；其失效调用存在实现和静态检查，但不声称完成启用 OPcache 的 FPM 实测。
- `reset_days=0` 是受控 CLI 时钟边界验证，未将其标记为自然时钟下的 FPM 样本。
- 未测试其他 Xboard 分支、其他 PHP 大版本或未来主仓库/Admin 构建；未知核心版本会拒绝操作。
- 框架上传替换非事务、卸载迁移批次等原生限制没有通过修改核心框架消除，按 `ROLLBACK.md` 恢复。

## 原始证据

- [126 项 HTTP](tests/evidence/http-integration.json)、[43 项最终恢复](tests/evidence/final-restore.json)、[基线摘要](tests/evidence/baseline-hashes.json)
- [11 项后端](tests/evidence/backend-cli.json)、[10 项前端](tests/evidence/admin-checks.txt)、[8 项临时 CLI](tests/evidence/static-cli.json)
- [浏览器操作记录](tests/evidence/browser-acceptance.json)、[默认设置截图](tests/evidence/admin-defaults.png)、[卸载恢复截图](tests/evidence/admin-restored.png)
- [环境总结](tests/evidence/environment-summary.md)、[真实 FPM 探针](tests/evidence/fpm-diagnostics.json)、[版本来源](tests/evidence/version-metadata.json)

安装包摘要独立存放在 `dist/SHA256SUMS`，避免把 ZIP 自身摘要写进 ZIP 内的报告形成循环依赖。
