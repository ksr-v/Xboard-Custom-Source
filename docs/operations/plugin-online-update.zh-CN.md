# 网页端插件在线更新

后台「插件管理」支持检查更新，并在插件卡片中一键更新到最新正式发行。
覆盖五个内置功能插件 NodeEndpoint、SmartExpiry、QRCodeExtend、FlowScope、SubInfoCustomizer，以及七个核心插件 AlipayF2F、BTCPay、Coinbase、CoinPayments、EPay、MGate、Telegram。

更新来源固定为 `ksr-v/Xboard-Custom-Source` 的最新非草稿、非预发布 Release。服务器从同一个 tag 获取 `xboard.tar.gz` 和 `SHA256SUMS`，验证文件大小、唯一 SHA-256 以及可用的 GitHub API digest 后，只替换所选插件目录。检查更新时也读取该受校验归档内的插件 `config.json`，不会把面板发行 tag 当作插件版本。下载失败或校验失败会显示错误，不能据此判断已是最新版。

同版号的源码修复也会被识别并更新；如果本地插件版本高于正式发行，界面会说明当前版本较新，更新器不会降级。第三方上传插件保留手动上传升级入口，不会自动信任它们声明的远程 URL。

更新保留数据库配置和原启停状态。已启用插件先在独立 PHP CLI 进程清理旧集成，再替换源码，并在新的 PHP CLI 进程执行迁移和启用新版本；原来禁用的插件继续禁用。同版源码刷新重新应用已启用插件的集成，不重复执行版本升级钩子。

站点用户必须可以写入插件目录及 `storage/app/private`，并能运行与站点配套的 PHP CLI（PHP 8.2+、Laravel 所需扩展及 HTTPS 访问能力）。PHP 子进程不得使用 root。在线更新与内置插件同步共享文件锁；并发更新会被拒绝并提示重试。若 Web 服务设有很短的请求超时，请按部署文档给插件更新请求足够的执行时间。

原源码与数据库状态记录保存在 `storage/app/private/plugin-online/backup-*/`。若插件目录与 storage 不在同一文件系统，原源码会保留在插件父目录的 `.previous-*`，准确路径记录在该操作的 `state.json`。新版本激活失败时，更新器尝试恢复原源码、版本和启停状态；恢复启用失败则保持禁用供检查。数据库迁移产生的业务变化不会被自动反向迁移，升级前仍应使用现有数据库备份流程。

站点用户也可以执行：

```sh
php artisan xboard:plugin-online-update node_endpoint
```

公开管理接口仍受原管理员鉴权保护：`GET plugin/checkUpdates` 检查已安装插件（可传 `code`），`POST plugin/updateOnline` 使用 JSON `{"code":"node_endpoint"}` 更新单个插件。客户端不能指定仓库、下载地址或服务器命令。
