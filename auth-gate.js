/*
 * Buddies authentication gate.
 *
 * Pages which include this file ask unauthenticated visitors to choose how
 * they want to continue. Guest mode deliberately does not create a session;
 * it only records the choice for the current browser tab.
 */
(function () {
  'use strict';

  const script = document.currentScript;
  const api = script?.dataset.api || './api.php';
  const guestKey = 'buddies_guest_mode';
  let overlay = null;
  let errorEl = null;
  let activeOption = 'login';

  const state = {
    user: null,
    guest: false,
    ready: null,
  };
  window.BuddiesAuth = state;

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));

  function addStyles() {
    if (document.getElementById('buddies-auth-gate-styles')) return;
    const style = document.createElement('style');
    style.id = 'buddies-auth-gate-styles';
    style.textContent = `
      .buddies-auth-gate{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.46);backdrop-filter:blur(5px)}
      .buddies-auth-gate[hidden]{display:none}
      .buddies-auth-card{width:min(100%,420px);max-height:min(90dvh,720px);overflow:auto;background:#fff;border-radius:26px;padding:28px 24px;box-shadow:0 16px 50px rgba(0,0,0,.18);color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif}
      .buddies-auth-kicker{font-size:11px;letter-spacing:.08em;color:#999;font-weight:800;text-align:center;margin-bottom:6px}
      .buddies-auth-title{font-size:23px;line-height:1.25;font-weight:900;text-align:center;margin:0}
      .buddies-auth-subtitle{font-size:13px;line-height:1.7;color:#666;text-align:center;margin:9px 0 18px}
      .buddies-auth-options{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-bottom:18px}
      .buddies-auth-option{min-height:42px;border:1px solid #ddd;border-radius:9999px;background:#fff;color:#555;font:inherit;font-size:12px;font-weight:800;cursor:pointer;padding:0 7px}
      .buddies-auth-option.active{background:#111;border-color:#111;color:#fff}
      .buddies-auth-panel{display:none}
      .buddies-auth-panel.active{display:block}
      .buddies-auth-field{margin-bottom:11px}
      .buddies-auth-field label{display:block;color:#666;font-size:12px;font-weight:700;margin-bottom:5px}
      .buddies-auth-field input{width:100%;min-height:46px;border:1px solid #ddd;border-radius:13px;padding:10px 14px;background:#fff;color:#111;font:inherit;font-size:16px;outline:none}
      .buddies-auth-field input:focus{border-color:#111}
      .buddies-auth-submit,.buddies-auth-guest{width:100%;min-height:46px;border-radius:9999px;font:inherit;font-size:14px;font-weight:800;cursor:pointer}
      .buddies-auth-submit{border:1px solid #111;background:#111;color:#fff}
      .buddies-auth-submit:disabled{opacity:.5;cursor:wait}
      .buddies-auth-guest{border:1px solid #ddd;background:#f7f7f7;color:#222}
      .buddies-auth-help{font-size:12px;line-height:1.65;color:#888;text-align:center;margin:10px 0 0}
      .buddies-auth-error{display:none;color:#c62828;background:#fff5f5;border:1px solid #ffd6d6;border-radius:12px;padding:9px 11px;font-size:12px;line-height:1.5;margin-bottom:11px}
      .buddies-auth-error.show{display:block}
      .buddies-auth-guest-note{border:1px solid #eee;border-radius:15px;background:#fafafa;color:#666;padding:13px;font-size:12px;line-height:1.7;margin-bottom:12px}
      .buddies-auth-guest-note strong{display:block;color:#222;font-size:13px;margin-bottom:2px}
      .buddies-guest-banner{position:fixed;z-index:9998;left:50%;bottom:18px;transform:translateX(-50%);display:flex;align-items:center;gap:10px;max-width:calc(100% - 24px);padding:9px 12px 9px 15px;border:1px solid #eadfbf;border-radius:9999px;background:#fffdf2;box-shadow:0 5px 20px rgba(0,0,0,.08);color:#66572a;font:700 12px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;white-space:nowrap}
      .buddies-guest-banner button{border:0;border-radius:9999px;background:#111;color:#fff;padding:7px 11px;font:inherit;font-size:11px;cursor:pointer}
      @media(max-width:500px){.buddies-auth-gate{align-items:flex-end;padding:0}.buddies-auth-card{max-height:min(92dvh,760px);border-radius:28px 28px 0 0;padding:25px 20px calc(22px + env(safe-area-inset-bottom))}.buddies-auth-card:before{content:'';display:block;width:42px;height:4px;border-radius:99px;background:#e5e5e5;margin:-10px auto 18px}}
    `;
    document.head.appendChild(style);
  }

  function createOverlay() {
    if (overlay) return;
    addStyles();
    overlay = document.createElement('div');
    overlay.className = 'buddies-auth-gate';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = `
      <div class="buddies-auth-card">
        <div class="buddies-auth-kicker">BUDDIES PROFILE</div>
        <h1 class="buddies-auth-title">ログインして始める</h1>
        <p class="buddies-auth-subtitle">アカウントを使うか、ゲストとして閲覧を続けられます。</p>
        <div class="buddies-auth-options" role="tablist" aria-label="利用方法">
          <button class="buddies-auth-option active" type="button" data-option="login">ログイン</button>
          <button class="buddies-auth-option" type="button" data-option="register">アカウント作成</button>
          <button class="buddies-auth-option" type="button" data-option="guest">ゲストで続ける</button>
        </div>
        <div class="buddies-auth-panel active" data-panel="login">
          <form id="buddies-auth-login-form">
            <div class="buddies-auth-field"><label for="buddies-auth-username">ユーザー名</label><input id="buddies-auth-username" name="username" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required></div>
            <div class="buddies-auth-field"><label for="buddies-auth-password">パスワード</label><input id="buddies-auth-password" name="password" type="password" autocomplete="current-password" required></div>
            <div class="buddies-auth-error" data-error="login"></div>
            <button class="buddies-auth-submit" type="submit">ログイン</button>
            <p class="buddies-auth-help">SakuLabo のアカウントでもログインできます。</p>
          </form>
        </div>
        <div class="buddies-auth-panel" data-panel="register">
          <div class="buddies-auth-guest-note"><strong>アカウントを新規作成</strong>アカウント情報、プロフィール、メール認証を順番に設定できます。</div>
          <a class="buddies-auth-submit" data-register-link href="./?register=1" style="display:flex;align-items:center;justify-content:center;text-decoration:none;">アカウントを新規作成</a>
        </div>
        <div class="buddies-auth-panel" data-panel="guest">
          <div class="buddies-auth-guest-note"><strong>ゲストで続ける</strong>プロフィールの閲覧など一部の機能を利用できます。投稿、いいね、参加登録などはログインが必要です。</div>
          <button class="buddies-auth-guest" type="button" data-continue-guest>ゲストで続ける</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    errorEl = overlay.querySelector('[data-error="login"]');
    overlay.querySelectorAll('[data-option]').forEach((button) => {
      button.addEventListener('click', () => setOption(button.dataset.option));
    });
    overlay.querySelector('#buddies-auth-login-form').addEventListener('submit', (event) => {
      event.preventDefault();
      submitLogin();
    });
    const registerLink = overlay.querySelector('[data-register-link]');
    if (registerLink && script?.src) {
      registerLink.href = `${new URL('./', script.src).href}?register=1`;
    }
    overlay.querySelector('[data-continue-guest]').addEventListener('click', continueAsGuest);
  }

  function setOption(option) {
    activeOption = ['login', 'register', 'guest'].includes(option) ? option : 'login';
    overlay?.querySelectorAll('[data-option]').forEach((button) => button.classList.toggle('active', button.dataset.option === activeOption));
    overlay?.querySelectorAll('[data-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.panel === activeOption));
    if (activeOption === 'login') errorEl = overlay?.querySelector('[data-error="login"]') || null;
    if (activeOption === 'register') errorEl = overlay?.querySelector('[data-error="register"]') || null;
  }

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message || '処理に失敗しました。';
    errorEl.classList.add('show');
  }

  function hideError() {
    overlay?.querySelectorAll('.buddies-auth-error').forEach((el) => {
      el.textContent = '';
      el.classList.remove('show');
    });
  }

  function setBusy(busy, label) {
    const button = overlay?.querySelector(`.buddies-auth-panel[data-panel="${activeOption}"] .buddies-auth-submit`);
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = busy;
    button.textContent = busy ? label : button.dataset.label;
  }

  async function submitLogin() {
    hideError();
    setBusy(true, 'ログイン中…');
    const username = overlay.querySelector('#buddies-auth-username').value.trim();
    const password = overlay.querySelector('#buddies-auth-password').value;
    try {
      const response = await fetch(`${api}?action=auth_login`, {
        method: 'POST', credentials: 'include', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ username, password }),
      });
      const json = await response.json();
      if (!json.ok) { showError(json.error || 'ログインできませんでした。'); return; }
      sessionStorage.removeItem(guestKey);
      location.reload();
    } catch (_) {
      showError('通信エラーが発生しました。もう一度お試しください。');
    } finally {
      setBusy(false);
    }
  }

  function continueAsGuest() {
    sessionStorage.setItem(guestKey, '1');
    state.guest = true;
    hideGate();
    showGuestBanner();
    document.dispatchEvent(new CustomEvent('buddies:auth-ready', {detail: state}));
  }

  function showGuestBanner() {
    if (document.getElementById('buddies-guest-banner')) return;
    const banner = document.createElement('div');
    banner.id = 'buddies-guest-banner';
    banner.className = 'buddies-guest-banner';
    banner.innerHTML = '<span>ゲスト表示中（利用できる機能に制限があります）</span><button type="button">ログイン</button>';
    banner.querySelector('button').addEventListener('click', () => {
      createOverlay();
      overlay.hidden = false;
      document.body.style.overflow = 'hidden';
      setOption('login');
      banner.remove();
    });
    document.body.appendChild(banner);
  }

  function hideGate() {
    if (!overlay) return;
    overlay.hidden = true;
    document.body.style.overflow = '';
  }

  function showGate() {
    createOverlay();
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    setOption('login');
    setTimeout(() => overlay.querySelector('#buddies-auth-username')?.focus(), 50);
  }

  async function init() {
    try {
      const response = await fetch(`${api}?action=auth_me`, {credentials: 'include'});
      const json = await response.json();
      state.user = json.ok ? json.data : null;
    } catch (_) {
      state.user = null;
    }
    if (state.user) {
      sessionStorage.removeItem(guestKey);
      document.dispatchEvent(new CustomEvent('buddies:auth-ready', {detail: state}));
      return state;
    }
    if (sessionStorage.getItem(guestKey) === '1') {
      state.guest = true;
      showGuestBanner();
      document.dispatchEvent(new CustomEvent('buddies:auth-ready', {detail: state}));
      return state;
    }
    showGate();
    document.dispatchEvent(new CustomEvent('buddies:auth-ready', {detail: state}));
    return state;
  }

  state.ready = init();
}());
