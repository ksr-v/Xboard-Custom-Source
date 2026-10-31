# 网页端插件在线更新

后台「插件管理」可以检查更新，并在插件卡片中一键更新到正式版。五个功能插件各自使用固定维护仓库，七个核心插件使用面板正式包：

| 插件 | 固定发行源 |
| --- | --- |
| NodeEndpoint | `ksr-v/Xboard-Independent-NodeEndpoint` |
| SmartExpiry | `ksr-v/xb-SmartExpiry` |
| QRCodeExtend | `ksr-v/xb-qrcode-extend` |
| FlowScope | `ksr-v/xb-flowscope` |
| SubInfoCustomizer | `ksr-v/xb-SubInfoCustomizer` |
| AlipayF2F、BTCPay、Coinbase、CoinPayments、EPay、MGate、Telegram | `ksr-v/Xboard-Custom-Source` 的面板正式包 |

五个功能插件选择最高 `vX.Y.Z` 版本的非草稿、非预发行 Release。Node 仓库的 tools、Root 安装器和 Web 管理器发行不参与插件版本比较。最新插件发行缺少规定 ZIP 或校验信息时会报错，不会悄悄改用较旧发行或面板内的旧副本。因此 QRCodeExtend 独立发行 1.3.1 会被识别为 1.3.1，即使某个面板包曾审计的是 1.2.0。

服务器核对固定仓库、标签、资产名称、大小和 SHA-256。四个仓库使用各自的 SHA256SUMS（FlowScope 为 `SHA256SUMS-v版本`），并与可用的 GitHub 资产摘要交叉核对；目前 SmartExpiry 没有独立校验文件，必须取得 GitHub 提供的 SHA-256 资产摘要。ZIP 中的绝对路径、越界路径、符号链接、特殊文件、加密内容、大小超限、重复或大小写冲突、错误插件身份和版本都会被拒绝。七个核心插件继续校验同一面板 tag 下的 `xboard.tar.gz` 和 `SHA256SUMS`。

检查结果中的发行元数据缓存 5 分钟，失败结果缓存 15 秒，避免反复打开管理页消耗上游 API。点「更新」会重新读取发行元数据，不会把检查缓存当作安装目标；本次操作选中并校验后固定包，准备与安装之间不会切换版本。同一个批量 CLI 操作的七个核心插件共享一个固定面板快照。下载归档按 SHA-256 缓存，安装前再次核对字节。

同版本源码修复也可被识别。如果已安装版本高于发行源版本，界面会显示当前版本较新，更新器不会降级。现有 SmartExpiry、SubInfoCustomizer 的已审运行时修复有严格兼容规则：只有指定官方 ZIP 摘要、已校验的完整面板运行时文件图和整组已知修复摘要均匹配时，才把它保留为当前版本；未知修改或混合文件不获此认可。独立发行安装后记录真实仓库、tag、解析出的 Git commit、资产身份、归档摘要及实际文件图，不把插件版本填入面板 `bundle_version`。后续面板同步会保留完整匹配的同版本独立发行。

更新保留数据库配置和原启停状态。已启用插件先在独立 PHP CLI 中清理旧集成，替换源码后，再由新的 PHP CLI 执行迁移和激活；原本禁用的插件继续禁用。同版本刷新重新应用已启用插件的集成，不重复执行版本升级钩子。QR 更新专用清理会先在站点用户私有目录备份 `storage/qrcodeextend`，执行正常清理后恢复这些私有文件；这不改变管理员主动禁用或卸载插件的原有行为。数据库迁移造成的业务变化不会自动反向迁移。

在线更新和普通插件操作共享 `storage/app/private/plugin-bundles/sync.lock`，并发修改会被拒绝。站点用户需要写入插件目录及 `storage/app/private`，并能运行配套 PHP CLI（PHP 8.2+、Laravel 所需扩展、ZIP、HTTPS）；不要使用 root 运行插件 PHP 子进程。第三方上传插件仍使用手动升级入口，客户端不能指定仓库、下载 URL 或服务器命令。

源码与数据库状态记录保存在 `storage/app/private/plugin-online/backup-*/`。如果插件目录与 storage 跨文件系统，原源码可能保留在插件父目录 `.previous-*`，准确路径记录在操作 `state.json`。激活失败会尝试恢复原源码、版本、配置和启停状态；恢复启用失败时保持禁用，备份及失败记录保留供检查。升级前仍应使用现有数据库备份流程。

站点用户也可执行单个或全部已安装官方插件更新：

```sh
php artisan xboard:plugin-online-update node_endpoint
php artisan xboard:plugins-online-update --json
```

管理接口继续使用原管理员鉴权：`GET plugin/checkUpdates` 检查已安装插件（可传 `code`），`POST plugin/updateOnline` 使用 JSON `{"code":"node_endpoint"}` 更新单个插件。批量 CLI 的输出仅包含各插件实际版本、是否更新及公开发行源信息；遇到失败会停止，详细错误写入站点私有日志。
