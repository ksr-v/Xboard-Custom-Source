# 技术设计

## 源码基准

唯一基准为 `ksr-v/Xboard-Custom-Source`，执行时通过 `git clone --depth 1 --branch master` 核对，commit `d1c3bb575b60bb74f78fadc7b21ef718dcee8f15`。原生部署遵循该提交下 `docs/installation/aapanel.zh-CN.md` 与 `bundled-native.zh-CN.md`。

不修改四个默认插件的源码、`bundled-plugins/manifest.json`、默认安装器、协议生成器或依赖锁文件。

## 订阅桥接依据

`ClientController::doSubscribe()` 先调用 `client.subscribe.servers`，再筛选真实节点，之后调用私有 `setSubscribeInfoToServers()`，最后加协议前缀并创建协议实例。原生信息行以第一条节点副本表示；过滤提示也在此方法中生成，且位于原生信息开关判断之前。

现有前置 Hook 无法阻止后续原生伪节点生成。`AbstractProtocol` 的 `protocol.servers.filtered` 位于更后位置，且不提供原生信息行所有权标记。为了避免通过名称猜测删除真实节点，本插件在 `setSubscribeInfoToServers()` 入口插入唯一桥接：

```php
// <subinfo-customizer-core-v1>
$customInfoServers = HookManager::filter('client.subscribe.info', null, $servers, $user, $rejectServerCount);
if (is_array($customInfoServers)) {
    $servers = $customInfoServers;
    return;
}
// </subinfo-customizer-core-v1>
```

无过滤器时返回 `null`，继续执行原生逻辑；启用插件后返回“信息行 + 未改变的真实节点”。返回空信息行的节点数组仍会跳过原生信息和过滤提示。因此用户设置的行数就是插件生成的信息节点数，不另加隐藏提示。

信息行以第一条筛选后节点副本承载，附加 `__subinfo_customizer` 内部标记，只改变副本名称。真实节点数组保持原值和顺序；不搜索或删除任何名称。没有可用节点时返回空数组，不捏造可连接节点。协议原生的兼容性过滤仍生效。

## 核心补丁所有权

`CoreBridgePatcher` 校验控制器的 LF 归一化 SHA-256：

```text
3303d9696a44268e42e0f39371ff42aed87597da68d207c0847fccb98d270ece
```

只接受原始文件或包含一次完整自有插入的文件。使用与 SmartExpiry、QRCodeExtend 相同的 `storage/framework/xboard-admin-patch.lock` 文件锁；在原文件目录创建临时文件，校验内容、保留权限后原子重命名，并尝试失效 OPcache。

该锁是多个插件共用的协调文件，禁用或卸载时保留，不能作为本插件残留删除。

禁用时只从当前文件移除完整自有片段，不用安装前的整文件备份覆盖现状。检测到核心更新、第三方改动、重复或损坏标记时拒绝写入。写入失败仅在当前内容仍等于本次写入结果时恢复本次操作开始的快照。

## 数据和安全

`TemplateService` 使用 `User::getRemainingTraffic()`、`getTotalUsedTraffic()` 和 `UserService::getResetDay()`，读取 `u`、`d`、`transfer_enable`、`expired_at` 和用户 ID。插件不重置、扣减或保存用户流量。原生 `Helper::trafficConvert()` 最高只显示 GB，因此展示层另按 1024 换算紧凑的 T/G/M 等单位；流量余额计算仍复用面板模型。

渲染只使用 `strtr()`，不调用 PHP、Blade、表达式或 HTML 解释器。服务端检查 UTF-8、变量白名单、行数、类型、模板和展开长度以及控制字符。前端使用 DOM 文本节点输出用户内容，不将模板插入 HTML。

设置保存在插件独立表 `subinfo_customizer_settings` 的 `id=1` 记录中，无跨请求配置缓存，保存提交后下一请求读取新值。配置损坏时管理页给出恢复提示；订阅保留全部真实节点并抑制原生伪节点，记录错误，不输出示例数据。

## 后台集成

利用已被 NodeEndpoint/FlowScope 使用的 `composing: admin` 视图事件，将当前视图路径与数据传递给本插件 wrapper，再对渲染 HTML 追加自有资源。没有任何 Admin JS/CSS 磁盘补丁，不参与也不撤销其他插件的资源片段。

识别 Admin `index-CEIYH7i8.js` 构建及唯一 sidebar、主内容区域和认证存储签名。浏览器通过管理员配置接口鉴权后追加自有左侧菜单。设置页地址为原后台路径加 `#/?subinfo_customizer=1`，使用现有首页路由的 hash 查询参数；不创建 Laravel 独立 HTML 管理路由。通过自有隐藏标记暂时隐藏 `main#content` 原内容，离开页面、登出或停用后恢复，不重写 React 路由或状态。

接口：

| 方法和路径 | 权限与用途 |
| --- | --- |
| `GET /api/v2/subinfo-customizer/config` | 管理员；读取配置、默认值、变量与明确标识的预览示例 |
| `POST /api/v2/subinfo-customizer/config` | 管理员；验证并保存完整设置 |
| `GET /api/v2/subinfo-customizer/defaults` | 管理员；读取默认草稿 |
| `GET /api/v2/subinfo-customizer/status` | 公开的启用/兼容性元数据，不含配置或用户数据 |
| `GET /api/v2/subinfo-customizer/assets/{script\|style}` | 固定两项静态资源；启用状态校验，无任意文件路径 |

配置接口应用原生 `admin` 中间件、本插件启用检查和 `throttle:60,1,subinfo-customizer:` 限流。独立前缀避免与 NodeEndpoint 的管理员限流计数混用；公开状态和固定资源不读取或输出用户配置。禁用后新请求不再加载插件路由，通常返回 404；已经加载路由的请求仍在中间件阶段检查启用状态。已通过该阶段的在途请求不作撤回保证。

## 真实生命周期

按 `PluginManager` 与 `AbstractPlugin` 实现：`install()` 只预检，安装后默认禁用；`boot()` 在启用和每次插件初始化时调用；`cleanup()` 同时用于禁用、卸载和升级前清理；`update($oldVersion,$newVersion)` 进行升级预检。不存在独立 `enable()`、`disable()` 或 `uninstall()` 插件回调。

`boot()` 失败会撤销本次自有桥接并把本插件置为禁用，保留设置。`cleanup()` 不删配置。卸载的表回滚由框架迁移器处理。框架上传替换及升级过程本身并非完整事务，恢复边界见 `ROLLBACK.md`。

原生安装器在创建插件记录和调用 `install()` 之前执行迁移，所以预检失败不保证自动移除已创建的本插件表。原生资源发布还会复制插件 JS/CSS 到 `public/plugins/subinfo_customizer/`；本插件在 `boot()` 和 `cleanup()` 中只移除这两个已知副本，实际运行资源由启用检查路由提供。“卸载”保留私有源码；原生“删除”操作才移除源码目录。
