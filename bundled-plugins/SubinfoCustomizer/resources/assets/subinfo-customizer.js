/* SubInfoCustomizer 1.0.1 — isolated Admin adapter for the audited Admin build.
 * Own DOM only. No React state, native requests, shared bundles or routes patched. */
(() => {
  'use strict';
  const config = window.__SUBINFO_CUSTOMIZER__;
  if (window.__SUBINFO_CUSTOMIZER_RUNTIME__ || !config || config.enabled !== true ||
      config.compatibility?.supported !== true || config.compatibility.build !== 'index-CEIYH7i8.js') return;

  const OWNER = 'subinfo_customizer';
  const OWN = '[data-subinfo-customizer-owner="' + OWNER + '"]';
  const HIDDEN = 'data-subinfo-customizer-hidden';
  const PAGE_HASH = '#/?subinfo_customizer=1';
  const apiBase = '/api/v2/subinfo-customizer';
  const allowedVariables = ['current_date', 'uid', 'remaining_traffic', 'total_traffic', 'used_traffic', 'upload_traffic', 'download_traffic', 'expire_date', 'reset_days'];
  const labels = ['获取订阅日期', '用户 UID', '剩余流量', '套餐总流量', '已使用流量', '上传流量', '下载流量', '到期日期', '重置剩余天数'];
  const hidden = new Set();
  const requests = new Set();
  const cleanupListeners = [];
  let stopped = false, authorized = false, scheduled = false, loading = false, statusBusy = false;
  let lastToken = '', generation = 0, lastStatus = 0;
  let saved = null, draft = null, defaults = null, sample = {}, sampleDate = '', loadWarning = '', activeIndex = 0, busy = false;
  let page = null, rowsHost = null, previewHost = null, message = null, dirtyLabel = null, addButton = null;
  let editorCount = null, previewCount = null, dateSelect = null, saveButton = null;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  }
  function own(node) { node.setAttribute('data-subinfo-customizer-owner', OWNER); return node; }
  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function listen(target, type, handler) {
    target.addEventListener(type, handler);
    cleanupListeners.push(() => target.removeEventListener(type, handler));
  }
  function button(label, handler, className = '') {
    const node = element('button', 'sic-button ' + className, label);
    node.type = 'button';
    node.addEventListener('click', handler);
    return node;
  }
  function token() {
    try {
      const entry = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
      return entry && typeof entry.value === 'string' && entry.value &&
        (entry.expire == null || typeof entry.expire === 'number' && entry.expire > Date.now()) ? entry.value : '';
    } catch { return ''; }
  }
  function onPage() {
    const hash = location.hash.slice(1).split('?');
    return (hash[0] === '/' || hash[0] === '') && new URLSearchParams(hash[1] || '').get('subinfo_customizer') === '1';
  }
  function validSettings(settings) {
    return settings && ['MM-DD', 'YYYY-MM-DD'].includes(settings.date_format) && Array.isArray(settings.rows) &&
      settings.rows.length <= 4 && settings.rows.every(row => row && typeof row.enabled === 'boolean' && typeof row.template === 'string');
  }
  function restoreContent() {
    for (const node of hidden) if (node.getAttribute(HIDDEN) === OWNER) node.removeAttribute(HIDDEN);
    hidden.clear();
  }
  function unmount() {
    restoreContent();
    page?.remove();
    page = rowsHost = previewHost = message = dirtyLabel = addButton = editorCount = previewCount = dateSelect = saveButton = null;
  }
  function clearUI() {
    unmount();
    for (const node of document.querySelectorAll(OWN)) node.remove();
  }
  function invalidate() {
    generation++;
    authorized = false;
    saved = draft = defaults = null;
    busy = false;
    clearUI();
    for (const controller of requests) controller.abort();
    requests.clear();
  }
  function destroy() {
    if (stopped) return;
    stopped = true;
    observer.disconnect();
    clearInterval(timer);
    for (const remove of cleanupListeners.splice(0)) remove();
    invalidate();
    for (const node of document.querySelectorAll('[data-subinfo-customizer-asset="1"]')) node.remove();
    delete window.__SUBINFO_CUSTOMIZER_RUNTIME__;
  }
  async function request(path, body) {
    const expectedToken = token();
    if (!expectedToken) throw new Error('请先登录管理员账号。');
    const controller = new AbortController();
    requests.add(controller);
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(apiBase + path, {
        method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {Authorization: expectedToken, Accept: 'application/json', 'Content-Type': 'application/json'},
        body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal
      });
      if (expectedToken !== token()) throw new Error('登录状态已变化，请刷新页面。');
      const payload = await response.json().catch(() => null);
      if ([401, 403, 404, 410].includes(response.status)) invalidate();
      if (!response.ok) {
        const details = payload?.errors && Object.values(payload.errors).flat().filter(x => typeof x === 'string');
        throw new Error(details?.length ? details.join('；') : payload?.message || '请求失败，请稍后重试。');
      }
      if (!payload || !Object.hasOwn(payload, 'data')) throw new Error('服务端返回了无效数据。');
      return payload.data;
    } finally {
      clearTimeout(timeout);
      requests.delete(controller);
    }
  }
  async function checkAccess() {
    if (stopped || loading || !token()) return;
    const revision = generation;
    const expectedToken = token();
    loading = true;
    try {
      const data = await request('/config');
      if (stopped || revision !== generation) return;
      if (!validSettings(data.settings) || !validSettings(data.defaults)) throw new Error('配置格式不受支持。');
      saved = clone(data.settings); draft = clone(saved); defaults = clone(data.defaults);
      sample = data.sample_variables && typeof data.sample_variables === 'object' ? data.sample_variables : {};
      sampleDate = typeof data.sample_date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(data.sample_date) ? data.sample_date : '';
      loadWarning = typeof data.warning === 'string' ? data.warning : '';
      authorized = true;
      schedule();
    } catch { /* The native Admin remains untouched if authorization fails. */ }
    finally {
      loading = false;
      // A previous account's in-flight request must not prevent the next
      // account from being checked after its token changes.
      if (!stopped && token() && token() !== expectedToken) queueMicrotask(checkAccess);
    }
  }
  async function checkStatus() {
    if (stopped || statusBusy || !authorized) return;
    statusBusy = true;
    const controller = new AbortController();
    requests.add(controller);
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(apiBase + '/status', {cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
      if ([404, 410].includes(response.status)) { destroy(); return; }
      const data = await response.json();
      if (data.enabled !== true || data.compatibility?.supported !== true) destroy();
    } catch { /* Transient networking cannot alter the native Admin. */ }
    finally { clearTimeout(timeout); requests.delete(controller); statusBusy = false; }
  }
  function setMessage(text, error = false) {
    if (!message) return;
    message.textContent = text;
    message.classList.toggle('sic-error', error);
  }
  function currentDate(format) {
    if (sampleDate) return format === 'YYYY-MM-DD' ? sampleDate : sampleDate.slice(5);
    try {
      const parts = new Intl.DateTimeFormat('en', {timeZone: config.timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(new Date());
      const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
      return (format === 'YYYY-MM-DD' ? values.year + '-' : '') + values.month + '-' + values.day;
    } catch { return String(sample.current_date || '—'); }
  }
  function previewVariables() {
    const values = {
      uid: '1', remaining_traffic: '97.52T', total_traffic: '97.66T', used_traffic: '143.36G',
      upload_traffic: '20.48G', download_traffic: '122.88G', expire_date: '长期有效', reset_days: '无'
    };
    for (const key of allowedVariables) if (typeof sample[key] === 'string' || typeof sample[key] === 'number') values[key] = String(sample[key]);
    values.current_date = currentDate(draft.date_format);
    return values;
  }
  function updatePreview() {
    if (!draft || !previewHost) return;
    previewHost.replaceChildren();
    const variables = previewVariables();
    const enabledRows = draft.rows.filter(row => row.enabled);
    enabledRows.forEach((row, index) => {
      const line = element('div', 'sic-preview-line');
      line.append(element('span', 'sic-preview-number', String(index + 1).padStart(2, '0')));
      const text = row.template.replace(/\{([a-z_]+)\}/g, (match, key) => Object.hasOwn(variables, key) ? variables[key] : match);
      line.append(element('span', 'sic-preview-name', text || '（空白信息行）'));
      previewHost.append(line);
    });
    if (!enabledRows.length) previewHost.append(element('p', 'sic-empty', '不显示订阅顶部信息，仅保留真实代理节点。'));
    previewCount.textContent = enabledRows.length + ' 行生效';
    editorCount.textContent = draft.rows.length + ' / 4 行';
    const changed = JSON.stringify(draft) !== JSON.stringify(saved);
    dirtyLabel.textContent = changed ? '有未保存修改' : '配置已同步';
    dirtyLabel.classList.toggle('sic-dirty', changed);
    addButton.disabled = busy || draft.rows.length >= 4;
    saveButton.disabled = busy;
  }
  function renderRows(focusIndex = null) {
    if (!rowsHost || !draft) return;
    rowsHost.replaceChildren();
    draft.rows.forEach((row, index) => {
      const card = element('div', 'sic-row' + (row.enabled ? '' : ' sic-row-disabled'));
      const top = element('div', 'sic-row-top');
      const heading = element('div', 'sic-row-heading');
      heading.append(element('span', 'sic-row-index', String(index + 1).padStart(2, '0')), element('span', '', '信息行 ' + (index + 1)));
      const tools = element('div', 'sic-row-tools');
      const toggle = element('label', 'sic-switch-label');
      const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.checked = row.enabled;
      checkbox.setAttribute('aria-label', '启用信息行 ' + (index + 1));
      checkbox.disabled = busy;
      checkbox.addEventListener('change', () => { row.enabled = checkbox.checked; card.classList.toggle('sic-row-disabled', !row.enabled); updatePreview(); setMessage(''); });
      toggle.append(checkbox, element('span', '', '启用'));
      const up = button('↑', () => moveRow(index, -1), 'sic-icon-button'); up.title = '上移'; up.setAttribute('aria-label', '上移信息行 ' + (index + 1)); up.disabled = busy || index === 0;
      const down = button('↓', () => moveRow(index, 1), 'sic-icon-button'); down.title = '下移'; down.setAttribute('aria-label', '下移信息行 ' + (index + 1)); down.disabled = busy || index === draft.rows.length - 1;
      const remove = button('删除', () => { draft.rows.splice(index, 1); activeIndex = Math.min(activeIndex, Math.max(0, draft.rows.length - 1)); renderRows(); setMessage(''); }, 'sic-delete'); remove.disabled = busy;
      tools.append(toggle, up, down, remove); top.append(heading, tools);
      const input = element('input', 'sic-template'); input.type = 'text'; input.value = row.template; input.maxLength = 160;
      input.autocomplete = 'off'; input.spellcheck = false; input.disabled = busy;
      input.setAttribute('aria-label', '信息行 ' + (index + 1) + ' 模板'); input.dataset.sicRow = String(index);
      input.placeholder = '输入文字，或点击下方变量插入';
      input.addEventListener('focus', () => { activeIndex = index; });
      input.addEventListener('input', () => { row.template = input.value; activeIndex = index; updatePreview(); setMessage(''); });
      card.append(top, input); rowsHost.append(card);
    });
    if (!draft.rows.length) rowsHost.append(element('div', 'sic-zero-state', '当前为 0 行。点击“新增信息行”开始编辑。'));
    if (focusIndex !== null) rowsHost.querySelector('[data-sic-row="' + focusIndex + '"]')?.focus();
    updatePreview();
  }
  function moveRow(index, direction) {
    const other = index + direction;
    if (busy || other < 0 || other >= draft.rows.length) return;
    [draft.rows[index], draft.rows[other]] = [draft.rows[other], draft.rows[index]];
    activeIndex = other; renderRows(other); setMessage('');
  }
  function insertVariable(name) {
    if (busy || !draft.rows.length) { setMessage('请先新增一行，再插入变量。'); return; }
    activeIndex = Math.min(Math.max(activeIndex, 0), draft.rows.length - 1);
    const input = rowsHost.querySelector('[data-sic-row="' + activeIndex + '"]');
    if (!input) return;
    const start = input.selectionStart ?? input.value.length, end = input.selectionEnd ?? start;
    const value = input.value.slice(0, start) + '{' + name + '}' + input.value.slice(end);
    if ([...value].length > 160) { setMessage('每行模板最多 160 个字符。', true); return; }
    input.value = value; draft.rows[activeIndex].template = value;
    input.focus(); input.setSelectionRange(start + name.length + 2, start + name.length + 2);
    updatePreview(); setMessage('');
  }
  async function save() {
    if (busy || !draft) return;
    const revision = generation;
    busy = true; renderRows(); dateSelect.disabled = true; setMessage('正在保存…');
    try {
      const data = await request('/config', clone(draft));
      if (stopped || revision !== generation) return;
      if (!validSettings(data.settings)) throw new Error('服务端返回了无效配置。');
      saved = clone(data.settings); draft = clone(saved); loadWarning = ''; setMessage('已保存，下次订阅请求即生效。');
    } catch (error) { setMessage(error.name === 'AbortError' ? '请求超时，请重试。' : error.message, true); }
    finally {
      if (revision === generation) {
        busy = false;
        if (dateSelect) { dateSelect.disabled = false; dateSelect.value = draft.date_format; }
        renderRows();
      }
    }
  }
  function mountPage(main) {
    page = own(element('section', 'sic-page')); page.id = 'subinfo-customizer-page'; page.setAttribute('aria-label', '订阅信息管理');
    const header = element('header', 'sic-page-header');
    const titles = element('div'); titles.append(element('p', 'sic-eyebrow', 'SUBINFOCUSTOMIZER'), element('h1', '', '订阅信息管理'), element('p', 'sic-subtitle', '为所有用户统一订阅顶部格式，变量按各自账户数据生成。'));
    dirtyLabel = element('span', 'sic-sync-label', '配置已同步'); header.append(titles, dirtyLabel);
    const layout = element('div', 'sic-layout');
    const editor = element('section', 'sic-card sic-editor');
    const editorHeader = element('div', 'sic-card-header'); const editorTitle = element('div'); editorTitle.append(element('h2', '', '信息行模板'), element('p', 'sic-hint', '最多 4 行，可调整顺序或单独停用。'));
    editorCount = element('span', 'sic-count'); editorHeader.append(editorTitle, editorCount);
    rowsHost = element('div', 'sic-rows');
    addButton = button('＋ 新增信息行', () => { if (busy || draft.rows.length >= 4) return; draft.rows.push({enabled: true, template: ''}); activeIndex = draft.rows.length - 1; renderRows(activeIndex); setMessage(''); }, 'sic-add');
    const variables = element('div', 'sic-variables'); variables.append(element('h3', '', '快捷插入变量'), element('p', 'sic-hint', '先选择一个信息行，变量将插入光标位置。'));
    const chips = element('div', 'sic-variable-list');
    allowedVariables.forEach((name, index) => { const chip = button('{' + name + '}', () => insertVariable(name), 'sic-variable'); chip.title = labels[index]; chips.append(chip); });
    variables.append(chips); editor.append(editorHeader, rowsHost, addButton, variables);
    const aside = element('aside', 'sic-preview-column');
    const preview = element('section', 'sic-card sic-preview'); const previewHeader = element('div', 'sic-card-header'); const previewTitle = element('div'); previewTitle.append(element('h2', '', '实时预览'), element('p', 'sic-hint', '示例数据 · 非真实用户数据'));
    previewCount = element('span', 'sic-count'); previewHeader.append(previewTitle, previewCount);
    previewHost = element('div', 'sic-preview-list');
    const nativeNode = element('div', 'sic-native-node'); nativeNode.append(element('span', 'sic-status-dot'), element('span', '', '真实代理节点'), element('span', 'sic-hint', '原有节点保留'));
    preview.append(previewHeader, previewHost, nativeNode, element('p', 'sic-preview-note', '此处仅演示名称排版。正式订阅使用当前用户的真实流量、到期和重置数据。'));
    const dateCard = element('section', 'sic-card sic-date-card'); dateCard.append(element('h2', '', '日期格式'));
    const dateLabel = element('label', 'sic-field-label', '获取订阅及到期日期'); dateLabel.htmlFor = 'sic-date-format';
    dateSelect = element('select', 'sic-date-select'); dateSelect.id = 'sic-date-format';
    for (const format of ['MM-DD', 'YYYY-MM-DD']) { const option = element('option', '', format); option.value = format; dateSelect.append(option); }
    dateSelect.value = draft.date_format;
    dateSelect.addEventListener('change', () => { draft.date_format = dateSelect.value; updatePreview(); setMessage(''); });
    dateCard.append(dateLabel, dateSelect, element('p', 'sic-hint', '应用时区：' + (config.timezone || 'UTC')), element('p', 'sic-hint', '无到期时间显示“长期有效”，无重置日期显示“无”。'));
    aside.append(preview, dateCard); layout.append(editor, aside);
    const footer = element('footer', 'sic-footer'); const actions = element('div', 'sic-actions');
    saveButton = button('保存设置', save, 'sic-primary');
    const cancel = button('取消未保存修改', () => { if (busy) return; draft = clone(saved); dateSelect.value = draft.date_format; renderRows(); setMessage('已取消未保存修改。'); });
    const reset = button('恢复默认', () => { if (busy) return; draft = clone(defaults); dateSelect.value = draft.date_format; activeIndex = 0; renderRows(); setMessage('已载入默认模板，点击“保存设置”后生效。'); });
    actions.append(saveButton, cancel, reset); message = element('p', 'sic-message'); message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite');
    footer.append(actions, message); page.append(header, layout, footer); main.append(page); renderRows();
    if (loadWarning) setMessage(loadWarning, true);
  }
  function mountMenu() {
    const anchors = [...document.querySelectorAll('#root aside nav a[href="#/"]')];
    for (const anchor of anchors) {
      const nav = anchor.closest('nav');
      if (!nav || nav.querySelector(OWN)) continue;
      const link = own(element('a', 'sic-menu-link', ''));
      link.href = PAGE_HASH; link.title = '订阅信息管理';
      const icon = element('span', 'sic-menu-icon', '≡'); icon.setAttribute('aria-hidden', 'true');
      link.append(icon, element('span', 'sic-menu-text', '订阅信息管理'));
      nav.append(link);
    }
    for (const link of document.querySelectorAll('a' + OWN)) {
      link.classList.toggle('sic-menu-active', onPage());
      if (onPage()) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
    }
  }
  function reconcile() {
    scheduled = false;
    if (stopped || !authorized || !draft || !token()) return;
    mountMenu();
    const main = document.querySelector('#root main#content');
    if (!onPage() || !main) { unmount(); return; }
    if (page && (!page.isConnected || page.parentElement !== main)) unmount();
    if (!page) mountPage(main);
    for (const child of main.children) {
      if (child === page) continue;
      child.setAttribute(HIDDEN, OWNER); hidden.add(child);
    }
    for (const node of hidden) if (!node.isConnected) hidden.delete(node);
  }
  function schedule() { if (!stopped && !scheduled) { scheduled = true; queueMicrotask(reconcile); } }
  const observer = new MutationObserver(records => {
    if (records.some(record => {
      const target = record.target.nodeType === 1 ? record.target : record.target.parentElement;
      if (target?.closest(OWN)) return false;
      if (record.type === 'childList' && [...record.addedNodes, ...record.removedNodes].every(node => node.nodeType === 1 && node.matches(OWN))) return false;
      return true;
    })) schedule();
  });
  observer.observe(document.documentElement, {subtree: true, childList: true, attributes: true, attributeFilter: ['href', 'class', 'data-collapsed']});
  listen(window, 'hashchange', schedule);
  listen(window, 'popstate', schedule);
  listen(window, 'storage', event => { if (event.key === 'XBOARD_ACCESS_TOKEN') syncToken(); });
  function syncToken() {
    const current = token();
    if (current === lastToken) return;
    lastToken = current; invalidate();
    if (current) checkAccess();
  }
  const timer = setInterval(() => {
    if (stopped) return;
    syncToken();
    if (authorized && Date.now() - lastStatus >= 15000) { lastStatus = Date.now(); checkStatus(); }
  }, 1000);
  window.__SUBINFO_CUSTOMIZER_RUNTIME__ = Object.freeze({version: config.version, destroy, refresh: () => { checkStatus(); schedule(); }});
  syncToken();
})();
