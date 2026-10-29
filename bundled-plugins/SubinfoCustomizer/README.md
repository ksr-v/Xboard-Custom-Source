# SubInfoCustomizer v1.0.1

Xboard 订阅信息自定义插件。后台“订阅信息管理”可编辑全局统一的 0–4 行订阅顶部信息，支持九个动态变量、排序、单行开关、示例预览和两种日期格式。

## 支持环境

面向 [ksr-v/Xboard-Custom-Source](https://github.com/ksr-v/Xboard-Custom-Source)，要求 PHP 8.2/8.3、mbstring 和 Laravel 12。原生部署使用 Linux、Nginx/OpenResty、MySQL/MariaDB 和 Redis；PHP 8.2 的本次验证为 Windows CLI，未重新执行完整 Docker 部署验收。实际验证范围见 [TEST-REPORT-v1.0.1.md](TEST-REPORT-v1.0.1.md)。

开发基准：版本 `2026.10.25.2`、commit `d1c3bb575b60bb74f78fadc7b21ef718dcee8f15`；本次重新核对 `2026.10.28.1`、commit `f7e01f8fdd32c9b65060dc9cf6dcd5dc9c4086bc`，控制器内容和 Admin 结构签名仍匹配原校验。启用时继续校验核心文件和 Admin 构建；遇到无法识别的版本会拒绝启用。

SubInfoCustomizer 现在作为第五个默认插件随面板捆绑，仍使用独立插件记录、配置表和启停生命周期。其余四项为 NodeEndpoint 1.0.10、SmartExpiry 1.2.0、QRCodeExtend 1.2.0、FlowScope 2.4.0；面板升级保留已有设置与管理员的禁用选择。

v1.0.1 本次验证见 [TEST-REPORT-v1.0.1.md](TEST-REPORT-v1.0.1.md)；v1.0.0 的历史 PHP-FPM 验证保留在 [TEST-REPORT.md](TEST-REPORT.md)。升级兼容边界见 [COMPATIBILITY.md](COMPATIBILITY.md)。

## 安装

使用包含本插件的新版面板时，按该面板的安装或升级文档部署，默认插件流程会安装本插件，无需再次上传。对仍使用独立安装的旧面板：

1. 按主仓库的 [aaPanel 安装文档](https://github.com/ksr-v/Xboard-Custom-Source/blob/master/docs/installation/aapanel.zh-CN.md) 和 [原生安装说明](https://github.com/ksr-v/Xboard-Custom-Source/blob/master/docs/installation/bundled-native.zh-CN.md) 准备站点，保留原 `composer.lock`。确认后台、订阅和其余四个默认插件正常。
2. 备份数据库、`app/Http/Controllers/V1/Client/ClientController.php`、`plugins/` 和 Admin 资源。插件启用时需要站点运行用户对该控制器及其父目录具备原子替换权限；按主仓库站点用户权限规范配置，勿使用 `777`。
3. 核对 `dist/SHA256SUMS` 中的摘要，将 `SubInfoCustomizer-v1.0.1.zip` 上传到后台“插件管理”。上传成功后点击安装，再点击启用。
4. 登录管理员账号，刷新后台，打开左侧“订阅信息管理”。编辑后点击“保存”；下一次订阅请求生效。

源码 ZIP 的顶层目录为 `SubInfoCustomizer/`。Xboard 上传器按插件代码 `subinfo_customizer` 将其部署到 `plugins/SubinfoCustomizer/`；不要自行改动 PHP 命名空间。

若启用提示版本或权限不匹配，先按 [ROLLBACK.md](ROLLBACK.md) 检查，勿整体替换控制器或 Admin JS 来绕过校验。

## 编辑信息

默认三行：

```text
订阅:{current_date}|UID:{uid}
流量:{remaining_traffic}/{total_traffic}
到期:{expire_date}|重置:{reset_days}
```

可添加、删除、上下移动、启用或禁用每行。最多保存四行，禁用的行也占一个编辑位置；删除全部行或禁用全部行会隐藏所有订阅顶部信息。插件启用期间，原生流量、到期、重置和“过滤掉 N 条线路”提示均由插件接管，避免额外增加信息行。

启用后，顶部信息由本页独立控制，原面板“显示订阅信息”开关不再控制这些行；插件禁用后恢复该开关原有作用。0 行不会移除真实节点；若原本没有可用节点，插件也不会创建信息节点。

点击变量按钮，将变量插入当前编辑框的光标位置。预览明确使用示例用户与流量；正式订阅仅使用当前用户真实数据。日期使用应用配置的时区，可选 `MM-DD` 或 `YYYY-MM-DD`。

| 变量 | 内容 |
| --- | --- |
| `{current_date}` | 获取订阅的当前日期 |
| `{uid}` | 当前用户 ID |
| `{remaining_traffic}` | 剩余流量，最低为 0 |
| `{total_traffic}` | 用户可用总流量 |
| `{used_traffic}` | 上传与下载之和 |
| `{upload_traffic}` | 上传流量 |
| `{download_traffic}` | 下载流量 |
| `{expire_date}` | 到期日期；无期限时为“长期有效” |
| `{reset_days}` | 原生计算的重置剩余天数；无重置时为“无”，0 天显示 `0` |

流量采用 1024 进制，最多两位小数，单位 `B/K/M/G/T/P/E`。每个模板 1–160 个 Unicode 字符，展开后最多 240 个字符。仅允许白名单变量；HTML/PHP 标签、模板表达式、换行和非法控制字符不能保存。与真实节点或其他信息行重名时，插件只给自己的信息行追加区分后缀。

“恢复默认”加载默认草稿，点击保存后生效。“取消未保存修改”恢复到上一次成功保存的内容。

## 禁用、升级与卸载

禁用后恢复原生订阅信息逻辑，配置保留；重新启用继续使用已保存配置。已打开的后台会在状态轮询后清除插件入口，刷新后立即移除。后续请求不再提供配置接口。

升级前备份数据库与当前插件源码，通过原生插件管理上传更高版本。升级保留配置；框架上传器自身不提供完整源码事务回滚，失败处理与恢复见 [ROLLBACK.md](ROLLBACK.md)。

先禁用，再卸载。卸载只回滚本插件迁移及其信息桥接，不更改四个默认插件、真实节点、套餐或用户流量。配置表是否被删除遵循主仓库 Laravel 迁移批次行为，详细限制见回滚文档。

原生“卸载”会保留私有插件源码目录；需要移除源码时再使用原生“删除插件”操作。安装预检失败后的迁移残留处理见 [ROLLBACK.md](ROLLBACK.md)。

## 交付文件

- `SubInfoCustomizer/`：可安装插件源码。
- `dist/SubInfoCustomizer-v1.0.1.zip`、`dist/SHA256SUMS`：安装包及校验。
- `TECHNICAL-DESIGN.md`、`TEST-REPORT.md`、`COMPATIBILITY.md`、`ROLLBACK.md`：设计、测试、兼容性与恢复说明。
- `tests/`、`scripts/`：隔离环境测试和可重复打包工具，不部署到网站 `public/`。
