'use strict';
// Exercise the actual distributed Admin app against a loopback, synthetic API.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const results = [];
function pass(label) {results.push(label); console.log('PASS ' + label);}
(async () => {
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/assets/admin/manifest.json'), 'utf8'));
  const entry = manifest['index.html'];
  const html = `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><script>window.settings={base_url:'/',secure_path:'admin',title:'Plugin fixture',version:'fixture'};</script><script src="/assets/admin/locales/en-US.js"></script><script src="/assets/admin/locales/zh-CN.js"></script><link rel="stylesheet" href="/assets/admin/${entry.css[0]}"><script type="module" src="/assets/admin/${entry.file}"></script></head><body><div id="root"></div></body></html>`;
  const server = http.createServer((req, res) => {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    if (pathname === '/admin') {res.setHeader('Content-Type', 'text/html'); res.end(html); return;}
    const asset = path.resolve(root, 'public', '.' + pathname);
    if (!asset.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(asset) || !fs.statSync(asset).isFile()) {res.writeHead(404); res.end(); return;}
    res.setHeader('Content-Type', asset.endsWith('.css') ? 'text/css' : 'application/javascript');
    res.end(fs.readFileSync(asset));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  let browser;
  try {
    browser = await chromium.launch({channel:'chrome', headless:true});
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    await context.addInitScript(() => {
      localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({value:'Bearer synthetic-ui-fixture',time:Date.now(),expire:null}));
      localStorage.setItem('i18nextLng', 'zh-CN');
      window.__pluginTimeouts = [];
      const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function(method,url,...rest) {this.__fixtureUrl = url; return open.call(this,method,url,...rest);};
      XMLHttpRequest.prototype.send = function(...args) {window.__pluginTimeouts.push({url:this.__fixtureUrl,timeout:this.timeout}); return send.apply(this,args);};
    });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const plugins = [
      {code:'node_endpoint',name:'NodeEndpoint',version:'1.1.2',online_update:true,is_enabled:true},
      {code:'smart_expiry',name:'SmartExpiry',version:'1.2.0',online_update:true,is_enabled:false},
      {code:'telegram',name:'Telegram',version:'1.0.0',online_update:true,is_enabled:true,is_protected:true},
      {code:'manual_fixture',name:'Manual fixture',version:'1.0.0',online_update:false,is_enabled:false,need_upgrade:true},
    ].map(p => ({description:'Synthetic plugin fixture',type:'feature',author:'Fixture',is_installed:true,config:{preserved:'value'},...p}));
    let updated = false, checkFailure = false, updateCalls = [], finishUpdate;
    await page.route('**/api/**', async route => {
      const url = new URL(route.request().url());
      let data = {}, status = 200;
      if (url.pathname.endsWith('/user/info')) data = {email:'fixture@invalid.test',is_admin:true,avatar_url:null};
      else if (url.pathname.endsWith('/plugin/types')) data = [{value:'feature',label:'功能'},{value:'payment',label:'支付'}];
      else if (url.pathname.endsWith('/plugin/getPlugins')) data = plugins;
      else if (url.pathname.endsWith('/plugin/checkUpdates')) {
        if (checkFailure) {await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Synthetic network failure'})}); return;}
        data = {
          node_endpoint:{supported:true,mode:'online',current_version:plugins[0].version,latest_version:'1.1.3',has_update:!updated},
          smart_expiry:{supported:true,mode:'online',current_version:'1.2.0',latest_version:'1.2.0',has_update:false},
          telegram:{supported:true,mode:'online',current_version:'1.0.0',error:'Release unavailable'},
        };
      } else if (url.pathname.endsWith('/plugin/updateOnline')) {
        const {code} = route.request().postDataJSON(); updateCalls.push(code);
        if (code === 'telegram') {status = 400; data = null;}
        else {
          await new Promise(resolve => {finishUpdate = resolve;});
          updated = true; plugins[0].version = '1.1.3';
          data = {code,version:'1.1.3',updated:true};
        }
      }
      await route.fulfill({status,contentType:'application/json',body:JSON.stringify({data,...(status!==200?{message:'Synthetic checksum mismatch'}:{})})});
    });
    await page.goto(base + '/admin#/config/plugin');
    const button = code => page.locator(`[data-plugin-online-update="${code}"]`);
    const state = code => page.locator(`[data-plugin-online-status="${code}"]`);
    try {await state('node_endpoint').filter({hasText:'最新 v1.1.3'}).waitFor({timeout:10000});}
    catch (error) {console.error(JSON.stringify({url:page.url(),text:(await page.locator('body').innerText()).slice(0,5000),errors})); throw error;}
    assert.equal(await button('node_endpoint').isEnabled(), true);
    assert.equal(await button('smart_expiry').isDisabled(), true);
    assert.match(await state('smart_expiry').textContent(), /已是最新版/);
    assert.match(await state('telegram').textContent(), /检查更新失败.*Release unavailable/);
    assert.equal(await button('telegram').isEnabled(), true);
    assert.equal(await button('manual_fixture').count(), 0);
    pass('Version, current, and failed checks are distinct; core and disabled plugins are supported');
    await button('node_endpoint').click();
    await page.waitForFunction(() => document.querySelector('[data-plugin-online-update="node_endpoint"]').textContent.includes('正在更新'));
    assert.equal(await button('telegram').isDisabled(), true);
    assert.equal(await page.locator('[data-plugin-check-updates]').isDisabled(), true);
    assert.deepEqual(updateCalls, ['node_endpoint']);
    pass('One click submits the chosen plugin and serializes other lifecycle actions');
    const timeouts = await page.evaluate(() => window.__pluginTimeouts);
    assert.equal(timeouts.find(item => item.url.endsWith('/plugin/checkUpdates')).timeout, 450000);
    assert.equal(timeouts.find(item => item.url.endsWith('/plugin/updateOnline')).timeout, 960000);
    pass('Check and update requests allow the backend operation budget beyond the default 30 seconds');
    finishUpdate();
    await state('node_endpoint').filter({hasText:'已是最新版 v1.1.3'}).waitFor();
    assert.equal(await button('node_endpoint').isDisabled(), true);
    assert.equal(plugins[0].config.preserved, 'value');
    assert.equal(plugins[0].is_enabled, true);
    assert.equal(plugins[1].is_enabled, false);
    pass('Success refreshes plugin/version status and retains enabled and disabled choices');
    await button('telegram').click();
    await page.getByText('Synthetic checksum mismatch',{exact:true}).first().waitFor();
    await page.waitForFunction(() => !document.querySelector('[data-plugin-online-update="telegram"]').disabled);
    assert.equal(plugins[2].version, '1.0.0');
    pass('Update failure is visible and permits retry without reporting success');
    checkFailure = true;
    await page.locator('[data-plugin-check-updates]').click();
    await page.getByRole('alert').filter({hasText:'Synthetic network failure'}).waitFor();
    pass('Network check failure displays an explicit retryable error');
    await page.setViewportSize({width:390,height:844});
    checkFailure = false;
    await page.reload();
    await state('node_endpoint').filter({hasText:'已是最新版 v1.1.3'}).waitFor();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    const output = path.join(__dirname, 'work'); fs.mkdirSync(output, {recursive:true});
    await page.screenshot({path:path.join(output,'online-updates-mobile.png'),fullPage:true});
    pass('Plugin update controls wrap on a phone without horizontal overflow');
    assert.deepEqual(errors, []);
    pass('Distributed Admin app has no browser runtime errors');
    fs.writeFileSync(path.join(output,'ui-result.json'),JSON.stringify({passed:results.length,checks:results,scope:'Actual Admin JS/CSS; synthetic loopback APIs, no remote updates executed'},null,2)+'\n');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => {console.error(error.stack); process.exitCode = 1;});
