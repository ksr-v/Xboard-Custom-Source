'use strict';
// The distributed Admin app has no source checkout. Keep the scoped bundle edit reproducible.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/assets/admin/manifest.json'), 'utf8'));
const file = path.join(root, 'public/assets/admin', manifest['index.html'].file);
let text = fs.readFileSync(file, 'utf8');
const marker = 'plugin-online-updates-v1';
function replaceOnce(before, after) {
  if (text.split(before).length !== 2) throw new Error('Admin bundle has changed: ' + before.slice(0, 90));
  text = text.replace(before, after);
}
if (!text.includes(marker)) {
  replaceOnce('upgradePlugin:e=>RL(`${NT}/plugin/upgrade`,{code:e}),',
    'upgradePlugin:e=>RL(`${NT}/plugin/upgrade`,{code:e}),checkPluginUpdates:()=>IL(`${NT}/plugin/checkUpdates`),updatePluginOnline:e=>RL(`${NT}/plugin/updateOnline`,{code:e}),');
  replaceOnce('function u1t({plugin:e,onInstall:t,onUpgrade:n,onUninstall:i,',
    'function u1t({plugin:e,onlineState:onlineState,isChecking:isChecking,onOnlineUpdate:onOnlineUpdate,onInstall:t,onUpgrade:n,onUninstall:i,');
  replaceOnce('Q.jsx("p",{className:"mb-3 overflow-hidden text-ellipsis text-sm text-muted-foreground",',
    `e.online_update&&Q.jsx("p",{"data-plugin-online-status":e.code,role:"status",className:"mb-2 text-xs text-muted-foreground",children:isChecking?d("online.checking"):onlineState?.error?d("online.checkError")+": "+onlineState.error:onlineState?.latest_version?d(onlineState.has_update?"online.available":"online.current",{version:onlineState.latest_version}):d(e.is_installed?"online.unchecked":"online.afterInstall")}),Q.jsx("p",{className:"mb-3 overflow-hidden text-ellipsis text-sm text-muted-foreground",`);
  replaceOnce('children:e.is_installed?Q.jsxs(Q.Fragment,{children:[e.need_upgrade&&',
    `children:e.is_installed?Q.jsxs(Q.Fragment,{children:[e.online_update&&Q.jsxs(Lf,{"data-plugin-online-update":e.code,variant:"outline",size:"sm",onClick:()=>onOnlineUpdate(e.code),disabled:a||isChecking||!!(onlineState&&!onlineState.error&&!onlineState.has_update&&!e.need_upgrade),className:"h-7 px-2 text-xs",children:[Q.jsx(kat,{className:"mr-1 h-3 w-3"}),d(a?"online.updating":"online.update")]}),e.need_upgrade&&`);
  replaceOnce('className:"flex items-center justify-end gap-2",children:e.is_installed?',
    'className:"flex flex-wrap items-center justify-end gap-2",children:e.is_installed?');
  replaceOnce('{data:E,isLoading:L,refetch:D}=Ult(),T=E?.filter',
    `{data:E,isLoading:L,refetch:D}=Ult(),onlineQuery=pC({queryKey:["pluginOnlineUpdates"],queryFn:async()=>{const{data:e}=await ET.checkPluginUpdates();return e},retry:!1,staleTime:3e5,refetchOnWindowFocus:!1}),updateOnline=async code=>{i(code);try{const result=await ET.updatePluginOnline(code);gE.success(result.message||e("online.success"));await D();t.invalidateQueries({queryKey:Wlt});await onlineQuery.refetch()}catch(error){gE.error(error.message||e("online.failed"))}finally{i(null)}},T=E?.filter`);
  replaceOnce('Q.jsxs(Lf,{onClick:()=>_(!0),variant:"outline",className:"shrink-0",size:"sm",children:[Q.jsx(Ilt,',
    `Q.jsxs(Lf,{"data-plugin-check-updates":!0,onClick:()=>onlineQuery.refetch(),disabled:onlineQuery.isFetching||n!==null,variant:"outline",className:"shrink-0",size:"sm",children:[Q.jsx(kat,{className:"mr-2 h-4 w-4"}),e(onlineQuery.isFetching?"online.checking":"online.check")]}),Q.jsxs(Lf,{onClick:()=>_(!0),variant:"outline",className:"shrink-0",size:"sm",children:[Q.jsx(Ilt,`);
  replaceOnce('Q.jsxs(rw,{value:d,onValueChange:u,className:"w-full",children:',
    `onlineQuery.isError&&Q.jsx("p",{role:"alert",className:"text-sm text-destructive",children:e("online.checkError")+": "+(onlineQuery.error?.message||e("online.retry"))}),Q.jsxs(rw,{value:d,onValueChange:u,className:"w-full",children:`);
  const card = 'plugin:e,typeInfo:R(e.type),onInstall:O,onUpgrade:M,';
  if (text.split(card).length !== 3) throw new Error('Expected both plugin card render paths');
  text = text.replaceAll(card, 'plugin:e,onlineState:onlineQuery.data?.[e.code],isChecking:onlineQuery.isFetching,onOnlineUpdate:updateOnline,typeInfo:R(e.type),onInstall:O,onUpgrade:M,');
  // Serialize lifecycle actions, including actions on other cards, while an update is in progress.
  text = text.replaceAll('onDelete:V,isLoading:n===e.code,', 'onDelete:V,isLoading:n!==null,');
  // Existing header controls need to wrap on a phone after adding the update action.
  const controls = 'className:"flex items-center gap-4",children:[Q.jsxs(yzt,{value:h,onValueChange:g,';
  replaceOnce(controls, 'className:"flex flex-wrap items-center gap-4",children:[Q.jsxs(yzt,{value:h,onValueChange:g,');
  text += '\n/* ' + marker + ' */\n';
  fs.writeFileSync(file, text);
}
if (!text.includes('isUpdating:isUpdating')) {
  replaceOnce('onlineState:onlineState,isChecking:isChecking,onOnlineUpdate:', 'onlineState:onlineState,isChecking:isChecking,isUpdating:isUpdating,onOnlineUpdate:');
  replaceOnce('onlineState?.latest_version?d(onlineState.has_update?"online.available":"online.current",', 'onlineState?.latest_version?d(onlineState.is_ahead?"online.ahead":onlineState.has_update?"online.available":"online.current",');
  replaceOnce('d(a?"online.updating":"online.update")', 'd(isUpdating?"online.updating":"online.update")');
  replaceOnce('refetchOnWindowFocus:!1}),updateOnline=async code=>{i(code);', 'refetchOnWindowFocus:!1}),[onlineBusy,setOnlineBusy]=H.useState(null),updateOnline=async code=>{i(code);setOnlineBusy(code);');
  replaceOnce('e("online.failed"))}finally{i(null)}},T=', 'e("online.failed"))}finally{i(null);setOnlineBusy(null)}},T=');
  text = text.replaceAll('isChecking:onlineQuery.isFetching,onOnlineUpdate:updateOnline,', 'isChecking:onlineQuery.isFetching,isUpdating:onlineBusy===e.code,onOnlineUpdate:updateOnline,');
  text = text.replaceAll('onDelete:V,isLoading:n!==null,', 'onDelete:V,isLoading:n!==null||p,');
  replaceOnce('disabled:onlineQuery.isFetching||n!==null,', 'disabled:onlineQuery.isFetching||n!==null||p,');
  replaceOnce('Q.jsxs(Lf,{onClick:()=>_(!0),variant:"outline",className:"shrink-0",', 'Q.jsxs(Lf,{onClick:()=>_(!0),disabled:n!==null,variant:"outline",className:"shrink-0",');
  fs.writeFileSync(file, text);
}
if (text.includes('checkPluginUpdates:()=>IL(`${NT}/plugin/checkUpdates`),')) {
  replaceOnce('checkPluginUpdates:()=>IL(`${NT}/plugin/checkUpdates`),updatePluginOnline:e=>RL(`${NT}/plugin/updateOnline`,{code:e}),',
    'checkPluginUpdates:()=>IL(`${NT}/plugin/checkUpdates`,{timeout:450000}),updatePluginOnline:e=>RL(`${NT}/plugin/updateOnline`,{code:e},{timeout:960000}),');
  fs.writeFileSync(file, text);
}
const translations = {
  'zh-CN': {
    check: '检查插件更新', checking: '正在检查更新…', update: '一键更新最新版', updating: '正在更新…',
    available: '在线更新 · 最新 v{{version}}', current: '在线更新 · 已是最新版 v{{version}}',
    ahead: '当前版本高于正式发布版（v{{version}}）',
    unchecked: '在线更新 · 尚未检查', afterInstall: '安装后支持在线更新', checkError: '检查更新失败',
    success: '插件已更新，配置与启停状态已保留', failed: '在线更新失败', retry: '请重试',
  },
  'en-US': {
    check: 'Check plugin updates', checking: 'Checking updates…', update: 'Update to latest', updating: 'Updating…',
    available: 'Online updates · Latest v{{version}}', current: 'Online updates · Up to date v{{version}}',
    ahead: 'Installed version is newer than the stable release (v{{version}})',
    unchecked: 'Online updates · Not checked', afterInstall: 'Online updates available after installation', checkError: 'Update check failed',
    success: 'Plugin updated; settings and enabled state preserved', failed: 'Online update failed', retry: 'Please retry',
  },
  'ru-RU': {
    check: 'Проверить обновления', checking: 'Проверка обновлений…', update: 'Обновить до последней', updating: 'Обновление…',
    available: 'Онлайн-обновления · Последняя v{{version}}', current: 'Онлайн-обновления · Актуальная v{{version}}',
    ahead: 'Установленная версия новее стабильной (v{{version}})',
    unchecked: 'Онлайн-обновления · Не проверено', afterInstall: 'Онлайн-обновления доступны после установки', checkError: 'Ошибка проверки обновлений',
    success: 'Плагин обновлён; настройки и состояние сохранены', failed: 'Ошибка онлайн-обновления', retry: 'Повторите попытку',
  },
};
for (const [locale, online] of Object.entries(translations)) {
  const localePath = path.join(root, 'public/assets/admin/locales', locale + '.js');
  const sandbox = {window: {}};
  vm.runInNewContext(fs.readFileSync(localePath, 'utf8'), sandbox);
  const data = sandbox.window.XBOARD_TRANSLATIONS[locale];
  data.plugin.online = online;
  fs.writeFileSync(localePath, `window.XBOARD_TRANSLATIONS = window.XBOARD_TRANSLATIONS || {};\nwindow.XBOARD_TRANSLATIONS['${locale}'] = ${JSON.stringify(data, null, 2)};\n`);
}
const blade = path.join(root, 'resources/views/admin.blade.php');
fs.writeFileSync(blade, fs.readFileSync(blade, 'utf8').replace('?v=device-online-fix-v1', '?v=' + marker));
console.log('Admin plugin online update controls and translations prepared.');
