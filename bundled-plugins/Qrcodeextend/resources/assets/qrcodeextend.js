import qrcodegen from './vendor/qrcodegen.js';

const translations = {
  'zh-CN': {
    menu: '生成订阅二维码',
    choose: '选择协议:',
    hint: '使用支持扫码的客户端进行订阅',
    missing: '无法获取该用户的订阅地址',
    invalid: '无法读取该用户的订阅地址',
    qr: '订阅二维码',
    auto: '自动',
    all: '全部',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'en-US': {
    menu: 'Subscription QR Code',
    choose: 'Select Protocol:',
    hint: 'Use a client app that supports scanning QR code to subscribe',
    missing: 'Unable to get this user\'s subscription address',
    invalid: 'Unable to read this user\'s subscription address',
    qr: 'Subscription QR Code',
    auto: 'Automatic',
    all: 'All',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'ru-RU': {
    menu: 'QR-код подписки',
    choose: 'Выберите протокол:',
    hint: 'Используйте приложение с поддержкой сканирования QR-кода для подписки',
    missing: 'Не удалось получить адрес подписки пользователя',
    invalid: 'Не удалось прочитать адрес подписки пользователя',
    qr: 'QR-код подписки',
    auto: 'Автоматически',
    all: 'Все',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'ja-JP': {
    choose: 'プロトコルの選択:',
    hint: '使用支持扫码的客户端进行订阅',
    missing: 'このユーザーのサブスクリプションアドレスを取得できません',
    invalid: 'このユーザーのサブスクリプションアドレスを読み取れません',
    qr: 'サブスクリプション QR コード',
    auto: '自動',
    all: '全て',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'vi-VN': {
    choose: 'Chọn Giao thức:',
    hint: 'Sử dụng ứng dụng quét mã để đăng ký',
    missing: 'Không thể lấy địa chỉ đăng ký của người dùng này',
    invalid: 'Không thể đọc địa chỉ đăng ký của người dùng này',
    qr: 'Mã QR đăng ký',
    auto: 'Tự động',
    all: 'Tất cả',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'ko-KR': {
    choose: '프로토콜 선택:',
    hint: '스캔 가능한 클라이언트로 구독하기',
    missing: '이 사용자의 구독 주소를 가져올 수 없습니다',
    invalid: '이 사용자의 구독 주소를 읽을 수 없습니다',
    qr: '구독 QR 코드',
    auto: '자동',
    all: '전체',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'zh-TW': {
    choose: '選擇協議:',
    hint: '使用支持掃碼的客戶端進行訂閱',
    missing: '無法取得此使用者的訂閱地址',
    invalid: '無法讀取此使用者的訂閱地址',
    qr: '訂閱 QR Code',
    auto: '自動',
    all: '全部',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  },
  'fa-IR': {
    choose: 'انتخاب پروتکل:',
    hint: 'برای اشتراک از کلاینتی استفاده کنید که از کد اسکن پشتیبانی می کند',
    missing: 'دریافت آدرس اشتراک این کاربر ممکن نیست',
    invalid: 'خواندن آدرس اشتراک این کاربر ممکن نیست',
    qr: 'کد QR اشتراک',
    auto: 'خودکار',
    all: 'تمام',
    anytls: 'Anytls',
    vless: 'Vless',
    hysteria: 'Hy1',
    hysteria2: 'Hy2',
    shadowsocks: 'Shadowsocks',
    vmess: 'Vmess',
    trojan: 'Trojan'
  }
};

const supportedLocales = {
  'zh-CN': translations['zh-CN'].menu,
  'en-US': translations['en-US'].menu,
  'ru-RU': translations['ru-RU'].menu
};

for (const [locale, label] of Object.entries(supportedLocales)) {
  const root = window.XBOARD_TRANSLATIONS ?? (window.XBOARD_TRANSLATIONS = {});
  const localeData = root[locale] ?? (root[locale] = {});
  const user = localeData.user ?? (localeData.user = {});
  const columns = user.columns ?? (user.columns = {});
  const actions = columns.actions_menu ?? (columns.actions_menu = {});
  actions.generate_qrcode = label;
}

const types = ['auto', 'all', 'anytls', 'vless', 'hysteria', 'hysteria2', 'shadowsocks', 'vmess', 'trojan'];
const protocolTypes = types.filter(type => type !== 'auto' && type !== 'all');
let rootElement = null;
let escapeHandler = null;
let activeState = null;
let qrRenderId = 0;
let toastTimer = null;

function getLocale() {
  const language = window.i18next?.language
    || window.i18n?.language
    || window.localStorage?.getItem('i18nextLng')
    || document.documentElement.lang
    || 'en-US';
  const normalized = language.toLowerCase();
  if (normalized.startsWith('zh-tw')) return 'zh-TW';
  if (normalized.startsWith('zh')) return 'zh-CN';
  if (normalized.startsWith('ru')) return 'ru-RU';
  if (normalized.startsWith('ja')) return 'ja-JP';
  if (normalized.startsWith('vi')) return 'vi-VN';
  if (normalized.startsWith('ko')) return 'ko-KR';
  if (normalized.startsWith('fa')) return 'fa-IR';
  return 'en-US';
}

function text(key) {
  return translations[getLocale()][key] ?? translations['en-US'][key] ?? key;
}

function toast(message) {
  document.querySelector('.qrcodeextend-toast')?.remove();
  const notice = document.createElement('div');
  notice.className = 'qrcodeextend-toast';
  notice.setAttribute('role', 'status');
  notice.textContent = message;
  document.body.append(notice);
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => notice.remove(), 3200);
}

function currentTypes() {
  return activeState.selected.includes('all')
    ? 'all'
    : activeState.selected.includes('auto')
      ? 'auto'
      : activeState.selected.join(',');
}

function buildPayload() {
  const url = new URL(activeState.user.subscribe_url);
  url.searchParams.set('types', currentTypes());
  return url.toString();
}

function qrColor() {
  const colors = {
    default: '#316C72',
    blue: '#0665d0',
    black: '#343a40',
    darkblue: '#004175'
  };
  return colors[window.settings?.theme?.color] ?? colors.default;
}

function drawQr(canvas, logo, payload, rendered) {
  const size = 140;
  const pixelRatio = 2;
  const qr = qrcodegen.QrCode.encodeText(payload, qrcodegen.QrCode.Ecc.MEDIUM);
  const modules = qr.size;
  const scale = size * pixelRatio / modules;
  const context = canvas.getContext('2d');
  if (!context) throw new Error('Canvas is unavailable');

  canvas.width = size * pixelRatio;
  canvas.height = size * pixelRatio;
  context.clearRect(0, 0, canvas.width, canvas.height);
  const foreground = qrColor();
  for (let y = 0; y < modules; y += 1) {
    for (let x = 0; x < modules; x += 1) {
      context.fillStyle = qr.getModule(x, y) ? foreground : '#ffffff';
      const left = Math.floor(x * scale);
      const top = Math.floor(y * scale);
      context.fillRect(left, top, Math.ceil((x + 1) * scale) - left, Math.ceil((y + 1) * scale) - top);
    }
  }

  if (!logo) {
    rendered();
    return;
  }
  const image = new Image();
  image.onload = () => {
    const iconSize = 40 * pixelRatio;
    const left = (canvas.width - iconSize) / 2;
    const top = (canvas.height - iconSize) / 2;
    context.fillStyle = '#ffffff';
    context.beginPath();
    context.roundRect(left, top, iconSize, iconSize, 8);
    context.fill();
    const aspect = image.width / image.height;
    const imageWidth = aspect >= 1 ? iconSize : iconSize * aspect;
    const imageHeight = aspect <= 1 ? iconSize : iconSize / aspect;
    context.drawImage(image, left + (iconSize - imageWidth) / 2, top + (iconSize - imageHeight) / 2, imageWidth, imageHeight);
    rendered();
  };
  image.onerror = rendered;
  image.src = logo;
}

function updateQr() {
  const canvas = rootElement?.querySelector('.qrcodeextend-canvas');
  const image = rootElement?.querySelector('.qrcodeextend-image');
  if (!canvas || !image || !activeState) return;
  const renderId = ++qrRenderId;
  const payload = buildPayload();
  const publish = () => {
    if (renderId !== qrRenderId || !rootElement?.contains(image)) return;
    try {
      image.src = canvas.toDataURL('image/png');
    } catch {
      drawQr(canvas, '', payload, () => {
        if (renderId === qrRenderId && rootElement?.contains(image)) {
          image.src = canvas.toDataURL('image/png');
        }
      });
    }
  };
  try {
    drawQr(canvas, window.settings?.logo || '', payload, publish);
  } catch {
    toast(text('invalid'));
  }
}

function toggleType(type) {
  const selected = activeState.selected;
  if (type === 'auto' || (type === 'all' && selected.includes('all'))) {
    activeState.selected = ['auto'];
  } else if (type !== 'all' || selected.includes('all')) {
    activeState.selected = selected.includes(type)
      ? selected.filter(value => value !== type)
      : [...selected.filter(value => value !== 'auto'), type];
    if (protocolTypes.every(value => activeState.selected.includes(value))) {
      activeState.selected.push('all');
    } else {
      activeState.selected = activeState.selected.filter(value => value !== 'all');
    }
  } else {
    activeState.selected = [...protocolTypes];
    if (protocolTypes.every(value => activeState.selected.includes(value))) {
      activeState.selected.push('all');
    }
  }
  if (activeState.selected.length === 0) activeState.selected = ['auto'];
  rootElement?.querySelectorAll('.qrcodeextend-protocol').forEach(button => {
    const checked = activeState.selected.includes(button.dataset.type);
    button.setAttribute('aria-pressed', checked ? 'true' : 'false');
    button.classList.toggle('qrcodeextend-protocol-selected', checked);
  });
  updateQr();
}

function close() {
  if (!rootElement) return;
  qrRenderId++;
  const closingRoot = rootElement;
  closingRoot.classList.add('qrcodeextend-closing');
  window.setTimeout(() => closingRoot.remove(), 180);
  rootElement = null;
  activeState = null;
  if (escapeHandler) document.removeEventListener('keydown', escapeHandler);
  escapeHandler = null;
}

function open(user) {
  if (!user) {
    toast(text('invalid'));
    return;
  }
  if (typeof user.subscribe_url !== 'string' || user.subscribe_url.trim() === '') {
    toast(text('missing'));
    return;
  }
  try {
    new URL(user.subscribe_url);
  } catch {
    toast(text('invalid'));
    return;
  }

  close();
  activeState = { user, selected: ['auto'] };
  rootElement = document.createElement('div');
  rootElement.className = 'qrcodeextend-root';
  rootElement.innerHTML = `
    <div class="qrcodeextend-overlay"></div>
    <section class="qrcodeextend-dialog" role="dialog" aria-modal="true" aria-label="${text('qr')}">
      <div class="qrcodeextend-selector-label">${text('choose')}</div>
      <div class="qrcodeextend-protocols"></div>
      <div class="qrcodeextend-code-frame">
        <img class="qrcodeextend-image" alt="${text('qr')}" width="140" height="140" draggable="false" style="display:block;-webkit-touch-callout:default;user-select:auto">
        <canvas class="qrcodeextend-canvas" aria-hidden="true" style="display:none"></canvas>
      </div>
      <div class="qrcodeextend-hint">${text('hint')}</div>
    </section>`;
  const dialog = rootElement.querySelector('.qrcodeextend-dialog');
  const protocolContainer = rootElement.querySelector('.qrcodeextend-protocols');
  for (const type of types) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'qrcodeextend-protocol';
    button.dataset.type = type;
    button.textContent = text(type);
    button.setAttribute('aria-pressed', type === 'auto' ? 'true' : 'false');
    if (type === 'auto') button.classList.add('qrcodeextend-protocol-selected');
    button.addEventListener('click', () => toggleType(type));
    protocolContainer.append(button);
  }
  rootElement.querySelector('.qrcodeextend-overlay').addEventListener('click', close);
  dialog.addEventListener('click', event => event.stopPropagation());
  escapeHandler = event => event.key === 'Escape' && close();
  document.addEventListener('keydown', escapeHandler);
  document.body.append(rootElement);
  updateQr();
}

window.Qrcodeextend = Object.freeze({
  open,
  close,
  isReady: () => true
});
window.dispatchEvent(new Event('qrcodeextend:ready'));
