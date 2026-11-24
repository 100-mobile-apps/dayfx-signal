/* DayFX Signals: web app (vanilla JS, talks to ../api with a bearer token) */
(() => {
'use strict';
const C = window.DAYFX || {};
const API = (C.api || '../api').replace(/\/$/, '');
const $root = document.getElementById('root');
const LS = {
  get(k, d = null) { try { const v = localStorage.getItem('dfx_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem('dfx_' + k, JSON.stringify(v)); } catch (e) {} },
  del(k) { try { localStorage.removeItem('dfx_' + k); } catch (e) {} },
};
const S = { token: LS.get('token'), user: LS.get('user'), cfg: null, timers: [], known: LS.get('known', {}) };

// ------------------------------------------------------------------ helpers
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ICONS = {
  signals: '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
  news: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  results: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
  earn: '<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
  account: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  tools: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 19h8"/>',
  crown: '<path d="M2 18l2-11 5 5 3-7 3 7 5-5 2 11z"/><path d="M4 21h16"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
  logout: '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
  copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
  lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>',
  up: '<path d="M7 17L17 7M8 7h9v9"/>', down: '<path d="M7 7l10 10M17 8v9H8"/>',
  check: '<circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/>', tick: '<path d="M20 6L9 17l-5-5"/>',
  back: '<path d="M19 12H5M12 19l-7-7 7-7"/>', share: '<path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><path d="M16 6l-4-4-4 4M12 2v13"/>',
  chev: '<path d="M9 18l6-6-6-6"/>', bolt: '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>', shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  award: '<circle cx="12" cy="8" r="6"/><path d="M15.5 13L17 22l-5-3-5 3 1.5-9"/>', bell: '<path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
  users: '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
  wallet: '<path d="M20 12V8H6a2 2 0 010-4h12v4"/><path d="M4 6v12a2 2 0 002 2h14v-4"/><path d="M18 12a2 2 0 000 4h4v-4z"/>',
  admin: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
  play: '<path d="M5 3l14 9-14 9z"/>', chat: '<path d="M21 11.5a8.4 8.4 0 01-11.9 7.6L3 21l1.9-6.1A8.4 8.4 0 1121 11.5z"/>',
  calc: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 14h8M8 18h8M8 10h8"/>', x: '<path d="M18 6L6 18M6 6l12 12"/>',
};
const ic = (n) => `<svg viewBox="0 0 24 24">${ICONS[n] || ''}</svg>`;

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('on');
  clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.remove('on'), 2600);
}
async function api(path, opts = {}) {
  const url = API.includes('?r=') ? API + path.replace('?', '&') : API + path;
  const h = { 'Content-Type': 'application/json' };
  if (S.token) h.Authorization = 'Bearer ' + S.token;
  let res;
  try {
    res = await fetch(url, { method: opts.method || (opts.body ? 'POST' : 'GET'), headers: h, body: opts.body ? JSON.stringify(opts.body) : undefined });
  } catch (e) { throw new Error('No connection. Check your internet and try again.'); }
  let j = {};
  try { j = await res.json(); } catch (e) {}
  if (res.status === 401 && S.token && !opts.noLogout) { logout(true); throw new Error('Please sign in again'); }
  if (!res.ok) throw new Error(j.error || 'Something went wrong (' + res.status + ')');
  return j;
}
const pipSize = (p) => p.startsWith('XAU') ? 0.1 : p.includes('JPY') ? 0.01 : 0.0001;
const decs = (p) => p.startsWith('XAU') ? 2 : p.includes('JPY') ? 3 : 5;
const fmtP = (p, v) => v == null ? '—' : Number(v).toFixed(decs(p));
const fmtPips = (v) => (v > 0 ? '+' : '') + Number(v).toFixed(1);
const ago = (iso) => {
  const s = (Date.now() - new Date(iso)) / 1000;
  if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + 'm ago';
  if (s < 86400) return Math.floor(s / 3600) + 'h ago'; return Math.floor(s / 86400) + 'd ago';
};
const dt = (iso, o = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) => new Date(iso).toLocaleString([], o);
const tm = (iso) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const STATUS = {
  ACTIVE: ['Running', 'p-blue'], TP1: ['TP1 hit', 'p-green'], WIN: ['Win', 'p-green'], LOSS: ['Loss', 'p-red'],
  BREAKEVEN: ['Breakeven', 'p-gray'], CLOSED_EOD: ['Closed EOD', 'p-gray'],
};
const tierPill = (t) => t === 'ELITE' ? `<span class="pill p-gold">${ic('award')}Elite</span>` : t === 'HIGH' ? '<span class="pill p-purple">High</span>' : '<span class="pill p-gray">Standard</span>';
const statusPill = (s) => `<span class="pill ${STATUS[s][1]}">${STATUS[s][0]}</span>`;
function ring(score) {
  const c = score >= 85 ? 'var(--gold)' : score >= 75 ? 'var(--blue)' : 'var(--mute)';
  const L = 2 * Math.PI * 19;
  return `<div class="ring"><svg viewBox="0 0 46 46"><circle cx="23" cy="23" r="19" stroke="var(--line2)" stroke-width="4"/><circle cx="23" cy="23" r="19" stroke="${c}" stroke-width="4" stroke-dasharray="${L}" stroke-dashoffset="${L * (1 - score / 100)}"/></svg><b>${score}</b></div>`;
}

// ------------------------------------------------------------------ market clock
const SESSIONS = [['Sydney', 21, 6], ['Tokyo', 0, 9], ['London', 7, 16], ['New York', 12, 21]];
function marketOpen(d = new Date()) {
  const wd = d.getUTCDay(), h = d.getUTCHours();
  if (wd === 6) return false; if (wd === 5 && h >= 21) return false; if (wd === 0 && h < 21) return false; return true;
}
const sessOpen = ([, o, c], d = new Date()) => { if (!marketOpen(d)) return false; const h = d.getUTCHours(); return o < c ? h >= o && h < c : h >= o || h < c; };
const sessionsHtml = () => `<div class="sessions">${SESSIONS.map((s) => { const on = sessOpen(s); return `<div class="sess ${on ? 'on' : ''}"><b>${s[0]}</b><small>${on ? 'Open' : 'Closed'}</small></div>`; }).join('')}</div>`;

// ------------------------------------------------------------------ auth state
function saveAuth(j) { S.token = j.token; S.user = j.user; LS.set('token', j.token); LS.set('user', j.user); }
function setUser(u) { S.user = u; LS.set('user', u); }
function logout(silent) {
  if (!silent && S.token) api('/auth/logout', { method: 'POST', noLogout: true }).catch(() => {});
  S.token = null; S.user = null; LS.del('token'); LS.del('user'); LS.del('known');
  if (!silent) toast('Signed out');
  location.hash = '#/login';
}

// referral code from ?ref= or /r/CODE, kept for 30 days
(function captureRef() {
  const q = new URLSearchParams(location.search);
  const code = (q.get('ref') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (code) {
    LS.set('ref', { code, t: Date.now() });
    fetch(API + (API.includes('?r=') ? '/ref/click' : '/ref/click'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }) }).catch(() => {});
    q.delete('ref');
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : '') + (location.hash || (S.token ? '#/signals' : '#/register')));
  }
})();
const refCode = () => { const r = LS.get('ref'); return r && Date.now() - r.t < 30 * 864e5 ? r.code : null; };

// ------------------------------------------------------------------ layout
const NAV = [
  ['signals', 'Signals', 'signals'], ['news', 'News', 'news'], ['results', 'Results', 'results'],
  ['tools', 'Tools', 'tools'], ['earn', 'Earn', 'earn'], ['premium', 'Premium', 'crown'], ['account', 'Account', 'settings'],
];
const BNAV = [['signals', 'Signals', 'signals'], ['news', 'News', 'news'], ['results', 'Results', 'results'], ['earn', 'Earn', 'earn'], ['account', 'Account', 'account']];
function shell(active, body) {
  const u = S.user || {};
  const prem = u.is_premium ? `<span class="premium-badge">${ic('crown')}Premium</span>` : '<small class="mute">Free plan</small>';
  const showPrem = S.cfg && S.cfg.premium_enabled;
  const nav = NAV.filter(([k]) => k !== 'premium' || showPrem || u.is_premium);
  const ann = S.cfg && S.cfg.announcement ? `<div class="announce">${esc(S.cfg.announcement)}</div>` : '';
  return `<div class="shell">
  <aside class="side">
    <a class="brand" href="#/signals"><img src="icon.png" alt="">DayFX</a>
    <nav class="nav">${nav.map(([k, l, i]) => `<a href="#/${k}" class="${active === k ? 'on' : ''}">${ic(i)}${l}</a>`).join('')}
    ${u.is_admin ? `<a href="../admin/">${ic('admin')}Admin panel</a>` : ''}</nav>
    <div class="grow"></div>
    <div class="me"><div class="av">${esc((u.name || '?')[0].toUpperCase())}</div><div style="min-width:0"><b>${esc(u.name)}</b>${prem}</div></div>
  </aside>
  <div class="main">
    <header class="top"><a class="brand" href="#/signals"><img src="icon.png" alt="">DayFX</a>
      ${u.is_premium ? `<span class="premium-badge">${ic('crown')}Premium</span>` : showPrem ? `<a class="btn sm" href="#/premium">${ic('crown')}Go Premium</a>` : ''}</header>
    ${ann}
    <div class="page" id="page">${body}</div>
  </div>
  <nav class="bnav">${BNAV.map(([k, l, i]) => `<a href="#/${k}" class="${active === k || (k === 'account' && ['tools', 'premium'].includes(active)) ? 'on' : ''}">${ic(i)}${l}</a>`).join('')}</nav>
</div>`;
}
const loading = () => '<div class="grid g2"><div class="skel"></div><div class="skel"></div><div class="skel"></div><div class="skel"></div></div>';
const page = () => document.getElementById('page');
function clearTimers() { S.timers.forEach(clearInterval); S.timers = []; }
function every(ms, fn) { S.timers.push(setInterval(fn, ms)); }

// ------------------------------------------------------------------ router
const routes = {};
async function route() {
  clearTimers();
  const h = location.hash.replace(/^#\/?/, '') || (S.token ? 'signals' : 'login');
  const [name, arg] = h.split('/');
  const open = ['login', 'register', 'forgot'];
  if (!S.token && !open.includes(name)) { location.hash = '#/login'; return; }
  if (S.token && open.includes(name)) { location.hash = '#/signals'; return; }
  const fn = routes[name] || routes.signals;
  window.scrollTo(0, 0);
  try { await fn(arg); } catch (e) { const p = page(); if (p) p.innerHTML = `<div class="err">${esc(e.message)}</div>`; }
}
window.addEventListener('hashchange', route);

// ------------------------------------------------------------------ auth screens
function authLayout(inner) {
  return `<div class="auth">
  <div class="hero">
    <div class="brand" style="padding:0"><img src="icon.png" alt="">DayFX Signals</div>
    <div>
      <h1>Forex day trading signals, <em>tracked live</em></h1>
      <p class="mute" style="max-width:460px;font-size:17px">Price action and news strategies for the major pairs and gold. Every signal is tracked against real prices, so you can see exactly how it performs.</p>
      <ul>
        <li>${ic('tick')}Entry, stop loss and two take profits on every signal</li>
        <li>${ic('tick')}Elite mode: only the highest-confluence setups</li>
        <li>${ic('tick')}News calendar, lot size calculator and session clock</li>
        <li>${ic('tick')}${S.cfg && S.cfg.premium_enabled ? 'Refer traders and earn commission' : 'Every signal free, with a public track record'}</li>
      </ul>
    </div>
    <small class="dim">Trading forex carries a high risk of loss. Signals are educational, not financial advice.</small>
  </div>
  <div class="form"><div class="box"><div class="mbrand"><div class="brand" style="padding:0"><img src="icon.png" alt="">DayFX Signals</div></div>${inner}</div></div>
</div>`;
}
function bindForm(id, fn) {
  const f = document.getElementById(id);
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = f.querySelector('button[type=submit]'); const err = f.querySelector('.err');
    btn.disabled = true; if (err) err.remove();
    try { await fn(Object.fromEntries(new FormData(f))); }
    catch (x) { f.insertAdjacentHTML('afterbegin', `<div class="err">${esc(x.message)}</div>`); }
    finally { btn.disabled = false; }
  });
}
routes.login = () => {
  $root.innerHTML = authLayout(`<h1>Welcome back</h1><p class="sub" style="margin-bottom:22px">Sign in with your DayFX account (same as the app).</p>
  <form id="f"><label class="field"><span>Email</span><input class="input" name="email" type="email" autocomplete="email" required></label>
  <label class="field"><span>Password</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
  <button class="btn block" type="submit">Sign in</button></form>
  <p class="alt"><a href="#/forgot">Forgot password?</a></p>
  <p class="alt">New to DayFX? <a href="#/register">Create a free account</a></p>`);
  bindForm('f', async (d) => { saveAuth(await api('/auth/login', { body: d })); location.hash = '#/signals'; });
};
routes.register = async () => {
  const code = refCode();
  let refHtml = '';
  if (code) {
    refHtml = `<div class="refbadge">${ic('users')}Invited with code <b>${esc(code)}</b></div>`;
    api('/ref/check?code=' + encodeURIComponent(code)).then((r) => {
      const b = document.querySelector('.refbadge');
      if (b && r.valid && r.name) b.innerHTML = `${ic('users')}<span>${esc(r.name)} invited you to DayFX</span>`;
      if (b && !r.valid) b.remove();
    }).catch(() => {});
  }
  $root.innerHTML = authLayout(`<h1>Create your account</h1><p class="sub" style="margin-bottom:22px">Free forever plan. No card needed.</p>${refHtml}
  <form id="f"><label class="field"><span>Your name</span><input class="input" name="name" autocomplete="name" required minlength="2"></label>
  <label class="field"><span>Email</span><input class="input" name="email" type="email" autocomplete="email" required></label>
  <label class="field"><span>Password (8+ characters)</span><input class="input" name="password" type="password" autocomplete="new-password" required minlength="8"></label>
  ${code ? '' : '<label class="field"><span>Referral code (optional)</span><input class="input" name="ref" autocomplete="off" style="text-transform:uppercase"></label>'}
  <button class="btn block" type="submit">Create account</button>
  <p class="dim" style="font-size:12px;margin-top:12px">By signing up you accept that trading carries a high risk of loss and that signals are educational, not financial advice.</p></form>
  <p class="alt">Already have an account? <a href="#/login">Sign in</a></p>`);
  bindForm('f', async (d) => {
    d.ref = code || (d.ref || '').trim().toUpperCase(); d.source = 'web';
    saveAuth(await api('/auth/register', { body: d }));
    LS.set('disclaimer', true);
    location.hash = '#/signals'; toast('Welcome to DayFX 👋');
  });
};
routes.forgot = () => {
  $root.innerHTML = authLayout(`<h1>Reset password</h1><p class="sub" style="margin-bottom:22px">We'll email you a 6-digit code.</p>
  <form id="f1"><label class="field"><span>Email</span><input class="input" name="email" type="email" required></label><button class="btn block" type="submit">Send code</button></form>
  <form id="f2" style="display:none"><input type="hidden" name="email"><label class="field"><span>Code from the email</span><input class="input" name="code" inputmode="numeric" required></label>
  <label class="field"><span>New password</span><input class="input" name="password" type="password" minlength="8" required></label><button class="btn block" type="submit">Set new password</button></form>
  <p class="alt"><a href="#/login">Back to sign in</a></p>`);
  bindForm('f1', async (d) => {
    await api('/auth/forgot', { body: d });
    document.getElementById('f1').style.display = 'none';
    const f2 = document.getElementById('f2'); f2.style.display = 'block'; f2.email.value = d.email;
    toast('If that email has an account, a code is on its way');
  });
  bindForm('f2', async (d) => { saveAuth(await api('/auth/reset', { body: d })); location.hash = '#/signals'; toast('Password updated'); });
};

// ------------------------------------------------------------------ signals
function lockedLevels(s) {
  return `<div class="lockbox"><div class="lv"><div><small>Entry</small><b>1.08642</b></div><div><small>SL</small><b>1.08512</b></div><div><small>TP1</small><b>1.08772</b></div><div><small>TP2</small><b>1.08902</b></div></div>
  <div class="ov">${ic('lock')}Premium signal · Unlock</div></div>`;
}
function sigCard(s) {
  const buy = s.direction === 'BUY';
  const res = s.result_pips != null ? `<span class="pill ${s.result_pips > 0 ? 'p-green' : s.result_pips < 0 ? 'p-red' : 'p-gray'} num">${fmtPips(s.result_pips)} pips</span>` : '';
  return `<a class="sig ${s.tier === 'ELITE' ? 'elite' : ''}" href="#/signal/${s.id}">
  <div class="row"><div class="ic ${buy ? 'buy' : 'sell'}">${ic(buy ? 'up' : 'down')}</div>
    <div style="min-width:0"><div><span class="pair">${esc(s.pair)}</span><span class="dir ${buy ? 'g' : 'r'}">${s.direction}</span></div>
    <div class="meta">${esc(s.strategy_name)} · ${ago(s.created_at)}</div></div>${ring(s.score)}</div>
  ${s.locked ? lockedLevels(s) : `<div class="lv num"><div><small>Entry</small><b>${fmtP(s.pair, s.entry)}</b></div><div><small>SL</small><b class="r">${fmtP(s.pair, s.sl)}</b></div><div><small>TP1</small><b class="g">${fmtP(s.pair, s.tp1)}</b></div><div><small>TP2</small><b class="g">${fmtP(s.pair, s.tp2)}</b></div></div>`}
  <div class="tags">${tierPill(s.tier)}${statusPill(s.status)}${res}</div></a>`;
}
routes.signals = async () => {
  const st = { scope: LS.get('scope', 'active'), elite: LS.get('elite', !!(S.user && S.user.elite_only)), items: [], more: false };
  $root.innerHTML = shell('signals', `
  <div class="head"><div><h1>Day-trade signals</h1><p class="sub">Forex and gold · M15 entries with H1 trend · London &amp; New York sessions</p></div>
  <span class="pill ${marketOpen() ? 'p-green' : 'p-gray'}">${marketOpen() ? '● Market open' : 'Market closed'}</span></div>
  ${sessionsHtml()}<div id="wk"></div>
  <div class="toolbar"><div class="seg" id="scope"><button data-v="active">Live</button><button data-v="history">History</button></div>
  <button class="chipbtn" id="elite">${ic('award')}Elite only</button></div>
  <div id="list">${loading()}</div><div id="morewrap" style="text-align:center;margin-top:16px"></div>`);
  api('/stats?period=week').then((r) => {
    const x = r.summary; if (!x || !x.total) return;
    document.getElementById('wk').innerHTML = `<div class="banner"><div><small class="mute">This week</small><div class="big">${x.win_rate != null ? x.win_rate + '% win rate' : '—'}</div><small class="mute">${x.wins}W · ${x.losses}L · ${x.breakeven} BE</small></div>
    <div style="text-align:right"><div class="big ${x.pips >= 0 ? 'g' : 'r'} num">${fmtPips(x.pips)} pips</div><small class="mute">tracked live</small></div></div>`;
  }).catch(() => {});
  const paint = () => {
    document.querySelectorAll('#scope button').forEach((b) => b.classList.toggle('on', b.dataset.v === st.scope));
    document.getElementById('elite').classList.toggle('on', st.elite);
    const L = document.getElementById('list');
    if (!st.items.length) {
      L.innerHTML = st.scope === 'active'
        ? `<div class="card empty">${ic('signals')}<div><b style="color:var(--text)">No live signals right now</b></div><p style="margin-top:6px">${marketOpen() ? 'The engine scans every 15 minutes between 07:00 and 19:00 UTC. Good setups are rare by design.' : 'The forex market is closed. Signals resume when London opens on Monday.'}</p></div>`
        : `<div class="card empty">${ic('results')}No closed signals yet</div>`;
    } else L.innerHTML = `<div class="sigs">${st.items.map(sigCard).join('')}</div>`;
    document.getElementById('morewrap').innerHTML = st.more ? '<button class="btn ghost" id="more">Load more</button>' : '';
    const m = document.getElementById('more'); if (m) m.onclick = () => load(true);
  };
  const load = async (append) => {
    const q = `/signals?scope=${st.scope}&limit=30${st.elite ? '&tier=elite' : ''}${append && st.items.length ? '&before_id=' + st.items[st.items.length - 1].id : ''}`;
    const r = await api(q);
    st.items = append ? st.items.concat(r.signals) : r.signals;
    st.more = st.scope === 'history' && r.signals.length === 30;
    if (st.scope === 'active') notifyNew(r.signals);
    paint();
  };
  document.querySelectorAll('#scope button').forEach((b) => b.onclick = () => { st.scope = b.dataset.v; LS.set('scope', st.scope); st.items = []; document.getElementById('list').innerHTML = loading(); load(); });
  document.getElementById('elite').onclick = () => { st.elite = !st.elite; LS.set('elite', st.elite); load(); };
  await load();
  every(60000, () => { if (st.scope === 'active' && !document.hidden) load().catch(() => {}); });
};

// browser notifications while a DayFX tab is open
function notifyNew(list) {
  const first = !Object.keys(S.known).length;
  let changed = false;
  for (const s of list) {
    const prev = S.known[s.id];
    if (prev !== s.status) {
      if (!first && LS.get('webnotify') && 'Notification' in window && Notification.permission === 'granted') {
        const title = prev ? `${s.direction} ${s.pair}: ${STATUS[s.status][0]}` : `New ${s.tier.toLowerCase()} signal: ${s.direction} ${s.pair}`;
        const body = s.locked ? 'Premium signal. Open DayFX for the levels.' : `Entry ${fmtP(s.pair, s.entry)}  SL ${fmtP(s.pair, s.sl)}  TP1 ${fmtP(s.pair, s.tp1)}`;
        try { new Notification(title, { body, icon: 'icon.png', tag: 'dfx' + s.id }); } catch (e) {}
      }
      S.known[s.id] = s.status; changed = true;
    }
  }
  if (changed) LS.set('known', S.known);
}

// ------------------------------------------------------------------ signal detail
routes.signal = async (id) => {
  $root.innerHTML = shell('signals', loading());
  const { signal: s } = await api('/signals/' + encodeURIComponent(id));
  const buy = s.direction === 'BUY';
  const risk = s.locked ? 0 : Math.abs(s.entry - s.sl) / pipSize(s.pair);
  const lvl = (label, v, cls, extra) => `<div class="lr" data-copy="${fmtP(s.pair, v)}" data-label="${label}"><div><small class="mute">${label}</small><br><b class="num ${cls}">${fmtP(s.pair, v)}</b></div><div class="x">${extra ? `<span class="mute num">${extra}</span>` : ''}${ic('copy')}</div></div>`;
  const levels = s.locked
    ? `<div class="lock-cta">${ic('lock')}<h2 style="margin:0 0 6px">Premium signal</h2><p class="mute" style="max-width:340px;margin:0 auto 16px">Entry, stop loss and take profits for live signals are for Premium members. The full levels show here as soon as you upgrade, and in the history once the trade closes.</p>${S.cfg && S.cfg.premium_enabled ? `<a class="btn" href="#/premium">${ic('crown')}See Premium plans</a>` : ''}</div>`
    : `<div class="card levels">${lvl('Entry (market)', s.entry, '')}${lvl('Stop loss', s.sl, 'r', '-' + risk.toFixed(1) + ' pips')}${lvl('Take profit 1 · 1R', s.tp1, 'g', '+' + risk.toFixed(1) + ' pips')}${lvl('Take profit 2 · 2R', s.tp2, 'g', '+' + (risk * 2).toFixed(1) + ' pips')}</div>
       <p class="dim" style="font-size:13px;margin-top:10px">Plan: close half at TP1 and move your stop to entry. The rest runs to TP2. Every trade is closed by 21:00 UTC.</p>`;
  page().innerHTML = `
  <a href="#/signals" class="row mute" style="margin-bottom:14px;font-weight:600">${ic('back')}All signals</a>
  <div class="head"><div class="row"><div class="ic ${buy ? 'buy' : 'sell'}" style="width:48px;height:48px;border-radius:13px;display:grid;place-items:center;background:${buy ? 'rgba(34,197,94,.14)' : 'rgba(239,68,68,.14)'};color:${buy ? 'var(--green)' : 'var(--red)'}">${ic(buy ? 'up' : 'down')}</div>
  <div><h1>${esc(s.pair)} <span class="${buy ? 'g' : 'r'}">${s.direction}</span></h1><p class="sub">${esc(s.strategy_name)} · ${dt(s.created_at)}</p></div></div>
  <div class="row">${ring(s.score)}<button class="btn ghost sm" id="share">${ic('share')}Share</button></div></div>
  <div class="tags" style="margin-bottom:18px">${tierPill(s.tier)}${statusPill(s.status)}${s.result_pips != null ? `<span class="pill ${s.result_pips > 0 ? 'p-green' : 'p-red'}">${fmtPips(s.result_pips)} pips</span>` : ''}</div>
  <div class="cols"><div>
    ${levels}
    <div class="sec">Why this signal</div>
    <div class="card"><ul class="why">${s.reasons.map((r) => `<li>${ic('check')}<span>${esc(r)}</span></li>`).join('')}</ul></div>
  </div><div>
    ${s.locked ? '' : `<div class="sec" style="margin-top:0">Position size</div><div class="card" id="calc"></div>`}
    <div class="sec" ${s.locked ? 'style="margin-top:0"' : ''}>Timeline</div>
    <div class="card"><div class="kv"><span>Published</span><b>${dt(s.created_at)}</b></div><div class="kv"><span>Auto-close (end of day)</span><b>${dt(s.closes_at)}</b></div>
    ${s.closed_at ? `<div class="kv"><span>Closed</span><b>${dt(s.closed_at)}</b></div>` : ''}<div class="kv"><span>Best move in your favour</span><b class="num">${Number(s.max_favorable_pips).toFixed(1)} pips</b></div></div>
  </div></div>
  <p class="dim" style="font-size:12px;margin-top:20px">Educational signal generated automatically from technical rules. Not financial advice. Results use mid-market prices without spread.</p>`;
  document.querySelectorAll('[data-copy]').forEach((el) => el.onclick = () => { navigator.clipboard && navigator.clipboard.writeText(el.dataset.copy); toast(el.dataset.label + ' copied'); });
  document.getElementById('share').onclick = () => {
    const txt = `${s.direction} ${s.pair} (${s.tier}, ${s.score}/100) on DayFX Signals${S.user && S.user.ref_code ? '\n' + location.origin + location.pathname.replace(/app\/?$/, '') + 'r/' + S.user.ref_code : ''}`;
    if (navigator.share) navigator.share({ text: txt }).catch(() => {}); else { navigator.clipboard.writeText(txt); toast('Copied to share'); }
  };
  if (!s.locked) lotCalc(document.getElementById('calc'), s.pair, risk);
};

// ------------------------------------------------------------------ lot size calculator
let PRICES = null;
async function prices() { if (!PRICES) { try { PRICES = (await api('/prices')).prices || {}; } catch (e) { PRICES = {}; } } return PRICES; }
function pipValue(pair, P) {
  const quote = pair.split('/')[1];
  const inQuote = pipSize(pair) * (pair.startsWith('XAU') ? 100 : 100000);
  if (quote === 'USD') return inQuote;
  if (P['USD/' + quote]) return inQuote / P['USD/' + quote].price;
  if (P[quote + '/USD']) return inQuote * P[quote + '/USD'].price;
  const approx = { JPY: 150, CAD: 1.37, CHF: 0.88, GBP: 0.78, AUD: 1.52, NZD: 1.68, EUR: 0.92 };
  return inQuote / (approx[quote] || 1);
}
const PAIRS = ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'EUR/JPY', 'XAU/USD', 'NZD/USD', 'USD/CHF', 'GBP/JPY'];
async function lotCalc(el, fixedPair, fixedStop) {
  const P = await prices();
  const v = LS.get('calc', { bal: 1000, risk: 1, pair: 'EUR/USD', stop: 20 });
  el.innerHTML = `${fixedPair ? '' : `<label class="field"><span>Pair</span><select class="input" id="c_pair">${PAIRS.map((p) => `<option ${p === v.pair ? 'selected' : ''}>${p}</option>`).join('')}</select></label>`}
  <div class="grid g2"><label class="field"><span>Balance (USD)</span><input class="input num" id="c_bal" type="number" min="0" step="any" value="${v.bal}"></label>
  <label class="field"><span>Risk %</span><input class="input num" id="c_risk" type="number" min="0" step="0.1" value="${v.risk}"></label></div>
  ${fixedPair ? '' : `<label class="field"><span>Stop loss (pips)</span><input class="input num" id="c_stop" type="number" min="0" step="0.1" value="${v.stop}"></label>`}
  <div class="grid g3" style="grid-template-columns:repeat(3,1fr);text-align:center;margin-top:4px">
  <div><small class="mute">Lot size</small><div class="g num" style="font-size:24px;font-weight:800" id="o_lot">—</div></div>
  <div><small class="mute">Money at risk</small><div class="r num" style="font-size:24px;font-weight:800" id="o_risk">—</div></div>
  <div><small class="mute">Pip value / lot</small><div class="num" style="font-size:24px;font-weight:800" id="o_pv">—</div></div></div>`;
  const calc = () => {
    const pair = fixedPair || el.querySelector('#c_pair').value;
    const bal = +el.querySelector('#c_bal').value || 0, risk = +el.querySelector('#c_risk').value || 0;
    const stop = fixedStop || +el.querySelector('#c_stop').value || 0;
    const pv = pipValue(pair, P), money = bal * risk / 100, lots = stop > 0 ? money / (stop * pv) : 0;
    el.querySelector('#o_lot').textContent = lots.toFixed(2);
    el.querySelector('#o_risk').textContent = '$' + money.toFixed(2);
    el.querySelector('#o_pv').textContent = '$' + pv.toFixed(2);
    LS.set('calc', { bal, risk, pair: fixedPair ? v.pair : pair, stop: fixedPair ? v.stop : stop });
  };
  el.querySelectorAll('input,select').forEach((i) => i.oninput = calc);
  calc();
}

// ------------------------------------------------------------------ news
routes.news = async () => {
  $root.innerHTML = shell('news', `<div class="head"><div><h1>Forex news calendar</h1><p class="sub">High and medium impact events in your local time</p></div>
  <button class="chipbtn" id="hi">${ic('bolt')}High impact only</button></div><div id="nx"></div><div id="ev">${loading()}</div>`);
  const { events } = await api('/news');
  let hi = LS.get('newshi', false);
  const next = events.find((e) => e.impact === 'High' && new Date(e.time) > new Date());
  const cd = () => {
    if (!next) return '';
    const s = Math.max(0, (new Date(next.time) - Date.now()) / 1000);
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = Math.floor(s % 60);
    return `<div class="banner" style="background:linear-gradient(120deg,rgba(239,68,68,.16),rgba(239,68,68,.03));border-color:rgba(239,68,68,.3)"><div><small class="r" style="font-weight:800;letter-spacing:.08em">NEXT HIGH-IMPACT</small><div style="font-weight:800;font-size:17px">${esc(next.currency)} · ${esc(next.title)}</div><small class="mute">${dt(next.time)}</small></div><div class="big num">${h}h ${String(m).padStart(2, '0')}m ${String(sec).padStart(2, '0')}s</div></div>`;
  };
  const paint = () => {
    document.getElementById('hi').classList.toggle('on', hi);
    const list = events.filter((e) => !hi || e.impact === 'High');
    const days = {};
    list.forEach((e) => { const k = new Date(e.time).toDateString(); (days[k] = days[k] || []).push(e); });
    document.getElementById('ev').innerHTML = `<div class="note" style="margin-bottom:16px">${ic('shield')} News protection: the engine pauses new signals 30 minutes before and after red-folder news on either currency of a pair. News Momentum looks for clean follow-through after the release.</div>` +
      (Object.keys(days).length ? Object.entries(days).map(([d, evs]) => `<div class="sec">${new Date(evs[0].time).toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })}</div><div class="card" style="padding:4px 18px">${evs.map((e) => `<div class="ev"><div class="t num">${tm(e.time)}</div><div class="bar" style="background:${e.impact === 'High' ? 'var(--red)' : 'var(--orange)'}"></div><div style="flex:1;min-width:0"><div><span class="cur">${esc(e.currency)}</span>${esc(e.title)}</div><small class="mute">${e.forecast ? 'Forecast ' + esc(e.forecast) : ''}${e.previous ? ' · Previous ' + esc(e.previous) : ''}</small></div>${new Date(e.time) < new Date() ? '<span class="pill p-gray">Released</span>' : ''}</div>`).join('')}</div>`).join('') : `<div class="card empty">${ic('news')}No events this week yet</div>`);
  };
  document.getElementById('hi').onclick = () => { hi = !hi; LS.set('newshi', hi); paint(); };
  paint();
  const nx = () => { const el = document.getElementById('nx'); if (el) el.innerHTML = cd(); };
  nx(); every(1000, nx);
};

// ------------------------------------------------------------------ results
routes.results = async () => {
  const st = { period: LS.get('period', 'week'), elite: false };
  $root.innerHTML = shell('results', `<div class="head"><div><h1>Track record</h1><p class="sub">Every signal, tracked automatically against live prices</p></div></div>
  <div class="toolbar"><div class="seg" id="per">${['today', 'week', 'month', 'all'].map((p) => `<button data-v="${p}">${p[0].toUpperCase() + p.slice(1)}</button>`).join('')}</div>
  <button class="chipbtn" id="el">${ic('award')}Elite only</button></div><div id="res">${loading()}</div>`);
  const load = async () => {
    document.querySelectorAll('#per button').forEach((b) => b.classList.toggle('on', b.dataset.v === st.period));
    document.getElementById('el').classList.toggle('on', st.elite);
    const r = await api(`/stats?period=${st.period}${st.elite ? '&tier=elite' : ''}`);
    const x = r.summary;
    const max = Math.max(1, ...r.daily.map((d) => Math.abs(d.pips)));
    const bars = r.daily.map((d) => { const h = Math.abs(d.pips) / max * 50; return `<div class="c" title="${d.date}: ${fmtPips(d.pips)} pips">${d.pips >= 0 ? `<i style="height:${h}%;background:var(--green);margin-top:auto;margin-bottom:0;transform:translateY(-50%);position:absolute;bottom:${50}%;left:0;right:0;transform:none"></i>` : `<i style="height:${h}%;background:var(--red);position:absolute;top:50%;left:0;right:0"></i>`}</div>`; }).join('');
    const tbl = (rows, name) => rows.length ? `<div class="tscroll"><table><tr><th>${name}</th><th>Win rate</th><th>W / L / BE</th><th style="text-align:right">Pips</th></tr>${rows.map((t) => `<tr><td><b>${esc(t.name || t.key)}</b></td><td class="num">${t.win_rate != null ? t.win_rate + '%' : '—'}</td><td class="num mute">${t.wins} / ${t.losses} / ${t.breakeven}</td><td class="num ${t.pips >= 0 ? 'g' : 'r'}" style="text-align:right">${fmtPips(t.pips)}</td></tr>`).join('')}</table></div>` : '<p class="mute">No closed signals in this period.</p>';
    document.getElementById('res').innerHTML = `
    <div class="grid g4"><div class="card kpi"><div class="l">Win rate</div><div class="v g num">${x.win_rate != null ? x.win_rate + '%' : '—'}</div><div class="s">${x.wins + x.losses} decided trades</div></div>
    <div class="card kpi"><div class="l">Net pips</div><div class="v num ${x.pips >= 0 ? 'g' : 'r'}">${fmtPips(x.pips)}</div><div class="s">before spread</div></div>
    <div class="card kpi"><div class="l">Wins</div><div class="v g num">${x.wins}</div><div class="s">TP1 reached</div></div>
    <div class="card kpi"><div class="l">Losses</div><div class="v r num">${x.losses}</div><div class="s">${x.breakeven} breakeven</div></div></div>
    <div class="sec">Daily pips · last 30 days</div><div class="card"><div class="chart">${bars}</div></div>
    <div class="grid g2 sm1" style="margin-top:12px"><div class="card"><h2>By strategy</h2>${tbl(r.by_strategy, 'Strategy')}</div><div class="card"><h2>By tier</h2>${tbl(r.by_tier, 'Tier')}</div></div>
    <div class="sec">How results are counted</div><div class="card mute" style="font-size:14px">A win is TP1 reached (half closed at +1R, stop moved to entry); reaching TP2 adds the second half at +2R. A loss is the stop hit before TP1. If one candle touches both, it counts as a loss. Prices are mid-market without spread or commission, so your broker's results will differ. Past results do not guarantee future results.</div>`;
  };
  document.querySelectorAll('#per button').forEach((b) => b.onclick = () => { st.period = b.dataset.v; LS.set('period', st.period); load(); });
  document.getElementById('el').onclick = () => { st.elite = !st.elite; load(); };
  await load();
};

// ------------------------------------------------------------------ tools
routes.tools = async () => {
  $root.innerHTML = shell('tools', `<div class="head"><div><h1>Trading tools</h1><p class="sub">Lot size calculator and market sessions</p></div></div>
  <div class="cols"><div><div class="sec" style="margin-top:0">Lot size calculator</div><div class="card" id="calc"></div></div>
  <div><div class="sec" style="margin-top:0">Market sessions (your time)</div><div class="card" id="sess"></div><div class="sec">Prices</div><div class="card" id="px"><p class="mute">Loading…</p></div></div></div>`);
  lotCalc(document.getElementById('calc'));
  const sess = () => {
    const now = new Date();
    document.getElementById('sess').innerHTML = SESSIONS.map((s) => {
      const on = sessOpen(s, now);
      const o = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), s[1])), c = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), s[2]));
      return `<div class="kv"><span><b style="color:var(--text)">${s[0]}</b><br><small>${tm(o)} – ${tm(c)}</small></span><span class="pill ${on ? 'p-green' : 'p-gray'}">${on ? 'Open' : 'Closed'}</span></div>`;
    }).join('') + `<p class="dim" style="font-size:12px;margin-top:8px">Standard hours; daylight saving moves them by about an hour part of the year. New signals: 07:00–19:00 UTC.</p>`;
  };
  sess(); every(30000, sess);
  const P = await prices();
  const ks = Object.keys(P);
  document.getElementById('px').innerHTML = ks.length ? ks.map((k) => `<div class="kv"><span><b style="color:var(--text)">${esc(k)}</b></span><span class="num"><b>${fmtP(k, P[k].price)}</b> <span class="${P[k].change_pct >= 0 ? 'g' : 'r'}">${P[k].change_pct >= 0 ? '+' : ''}${P[k].change_pct}%</span></span></div>`).join('') + '<p class="dim" style="font-size:12px;margin-top:8px">Updated every 15 minutes during the trading day.</p>' : '<p class="mute">Prices appear once the engine has run.</p>';
};

// ------------------------------------------------------------------ premium
routes.premium = async () => {
  $root.innerHTML = shell('premium', loading());
  const q = new URLSearchParams(location.search);
  const ref = q.get('reference') || q.get('trxref');
  if (ref) {
    history.replaceState(null, '', location.pathname + '#/premium');
    try {
      const r = await api('/billing/verify', { body: { reference: ref } });
      setUser(r.user);
      toast(r.status === 'success' ? 'Payment confirmed. Welcome to Premium!' : r.status === 'failed' ? 'Payment failed. You were not charged.' : 'Payment is still processing. Refresh in a minute.');
    } catch (e) { toast(e.message); }
  }
  const b = await api('/billing');
  const u = S.user;
  const scopeTxt = { all: 'every live signal', elite_high: 'live Elite and High signals', elite: 'live Elite signals' }[b.lock_scope] || 'live signals';
  const feats = [`Entry, stop loss and take profits for ${scopeTxt} as soon as they're published`, 'Premium email alerts with full levels', 'Elite mode: the highest-confluence setups', 'Works on the web and in the Android app with the same account', 'Track record, news calendar and tools stay free for everyone'];
  page().innerHTML = `<div class="head"><div><h1>DayFX Premium</h1><p class="sub">Get the levels the moment a signal is published</p></div></div>
  ${b.is_premium ? `<div class="hero-card" style="margin-bottom:18px"><span class="premium-badge">${ic('crown')}PREMIUM ACTIVE</span><h2 style="margin:8px 0 4px;font-size:22px">You're on Premium</h2><p class="mute">Active until <b style="color:var(--text)">${new Date(b.premium_until).toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' })}</b>. Paying again adds time to your current plan.</p></div>` : ''}
  ${b.enabled ? `<div class="plans">${b.plans.map((p) => `<div class="plan ${p.id === 'yearly' ? 'best' : ''}">${p.save_pct > 0 ? `<span class="pill p-green save">Save ${p.save_pct}%</span>` : ''}<b>${p.name}</b><div class="price num">${esc(p.label)}</div><p class="mute">per ${p.per}${p.id === 'yearly' ? ' · ' + esc(money(p.amount / 12, p.currency)) + '/month' : ''}</p><button class="btn block" style="margin-top:16px" data-plan="${p.id}">${b.is_premium ? 'Add ' + p.days + ' days' : 'Choose ' + p.name}</button></div>`).join('')}</div>
  <p class="dim" style="font-size:13px;margin-top:10px">Secure payment by Paystack (card, bank transfer, USSD). One-off payment, no auto-renewal.</p>`
  : `<div class="note">Premium isn't open yet. Everything in DayFX is free for now.</div>`}
  <div class="sec">What you get</div><div class="card"><ul class="feat">${feats.map((f) => `<li>${ic('tick')}<span>${f}</span></li>`).join('')}</ul></div>
  ${b.payments.length ? `<div class="sec">Payment history</div><div class="card tscroll"><table><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Status</th></tr>${b.payments.map((p) => `<tr><td>${new Date(p.date).toLocaleDateString()}</td><td>${esc(p.plan)}</td><td class="num">${esc(p.label)}</td><td><span class="pill ${p.status === 'success' ? 'p-green' : p.status === 'refunded' ? 'p-gray' : 'p-red'}">${esc(p.status)}</span></td></tr>`).join('')}</table></div>` : ''}
  <p class="dim" style="font-size:12px;margin-top:20px">Signals are educational and not financial advice. Premium gives faster access to the same tracked signals; it does not promise profits.</p>`;
  document.querySelectorAll('[data-plan]').forEach((btn) => btn.onclick = async () => {
    btn.disabled = true; btn.textContent = 'Opening Paystack…';
    try { const r = await api('/billing/checkout', { body: { plan: btn.dataset.plan } }); location.href = r.authorization_url; }
    catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Try again'; }
  });
};
function money(minor, cur) {
  const sym = { NGN: '₦', USD: '$', GHS: 'GH₵', KES: 'KSh ', ZAR: 'R' }[cur] || cur + ' ';
  return sym + (minor / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ------------------------------------------------------------------ affiliate / earn
routes.earn = async () => {
  $root.innerHTML = shell('earn', loading());
  const a = await api('/affiliate');
  if (!a.enabled) { page().innerHTML = `<div class="head"><div><h1>Earn with DayFX</h1></div></div><div class="note">The affiliate programme is paused right now. Check back soon.</div>`; return; }
  const B = a.balance_labels, d = a.payout_details || {};
  const msg = encodeURIComponent(`I use DayFX for forex day trading signals with a live track record. Join with my link: ${a.link}`);
  page().innerHTML = `<div class="head"><div><h1>Earn with DayFX</h1><p class="sub">${!a.premium_enabled ? 'Invite traders to free DayFX signals' : `Invite traders and earn ${a.commission_pct}% of ${a.recurring ? 'every Premium payment they make' : 'their first Premium payment'}`}</p></div></div>
  <div class="hero-card"><small class="mute" style="font-weight:700;letter-spacing:.08em">YOUR REFERRAL LINK</small>
  <div class="linkbox"><input class="input" id="lnk" readonly value="${esc(a.link)}"><button class="btn" id="cp">${ic('copy')}Copy</button></div>
  <div class="row mute" style="margin-top:8px;font-size:14px">Referral code: <b style="color:var(--text);letter-spacing:.06em">${esc(a.code)}</b> · people can also type it when they sign up in the app</div>
  <div class="share"><a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/?text=${msg}">${ic('chat')}WhatsApp</a><a class="btn ghost sm" target="_blank" rel="noopener" href="https://t.me/share/url?url=${encodeURIComponent(a.link)}&text=${encodeURIComponent('Forex day trading signals with a live track record')}">Telegram</a><a class="btn ghost sm" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=${msg}">X / Twitter</a><a class="btn ghost sm" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(a.link)}">Facebook</a></div></div>
  ${a.premium_enabled ? '' : '<div class="note" style="margin-top:12px">Everything in DayFX is free, so there are no commissions to earn. Share your link to bring traders in; everyone who joins with it stays linked to your account.</div>'}
  <div class="sec">Your referrals</div>
  <div class="grid g4"><div class="card kpi"><div class="l">Link visits</div><div class="v num">${a.stats.clicks}</div><div class="s">unique per day</div></div>
  <div class="card kpi"><div class="l">Sign-ups</div><div class="v num">${a.stats.signups}</div><div class="s">joined with your link</div></div>
  <div class="card kpi"><div class="l">Paid</div><div class="v num">${a.stats.paying}</div><div class="s">bought Premium</div></div>
  <div class="card kpi"><div class="l">Premium now</div><div class="v num">${a.stats.active_premium}</div><div class="s">active members</div></div></div>
  <div class="sec">Earnings</div>
  <div class="grid g4"><div class="card kpi"><div class="l">Available</div><div class="v ${a.balance.available < 0 ? 'r' : 'g'} num">${esc(B.available)}</div><div class="s">${a.balance.available < 0 ? 'a refunded sale was already paid out; new earnings cover it first' : 'ready to withdraw'}</div></div>
  <div class="card kpi"><div class="l">Pending</div><div class="v gold num">${esc(B.pending)}</div><div class="s">clears after ${a.hold_days} days</div></div>
  <div class="card kpi"><div class="l">Requested</div><div class="v num">${esc(B.requested)}</div><div class="s">being processed</div></div>
  <div class="card kpi"><div class="l">Paid out</div><div class="v num">${esc(B.paid)}</div><div class="s">all time</div></div></div>
  <div class="grid g2 sm1" style="margin-top:12px">
    <div class="card"><h2>Withdraw</h2><p class="mute" style="font-size:14px;margin-bottom:14px">Minimum ${esc(a.min_payout_label)}. Payouts go to your bank account, usually within 3 working days.</p>
    <form id="po"><label class="field"><span>Amount (${esc(a.currency)})</span><input class="input num" name="amount" type="number" min="0" step="any" value="${Math.max(0, Math.floor(a.balance.available / 100))}"></label>
    <button class="btn block" type="submit" ${a.balance.available < a.min_payout ? 'disabled' : ''}>${ic('wallet')}Request payout</button></form></div>
    <div class="card"><h2>Bank details</h2><form id="bd"><label class="field"><span>Bank</span><input class="input" name="bank_name" value="${esc(d.bank_name || '')}" required></label>
    <div class="grid g2"><label class="field"><span>Account number</span><input class="input num" name="account_number" inputmode="numeric" value="${esc(d.account_number || '')}" required></label>
    <label class="field"><span>Account name</span><input class="input" name="account_name" value="${esc(d.account_name || '')}" required></label></div>
    <button class="btn ghost block" type="submit">Save bank details</button></form></div></div>
  <div class="sec">Commissions</div><div class="card tscroll">${a.commissions.length ? `<table><tr><th>Date</th><th>From</th><th>Amount</th><th>Status</th></tr>${a.commissions.map((c) => `<tr><td>${new Date(c.date).toLocaleDateString()}</td><td>${esc(c.from)}</td><td class="num g">${esc(c.label)}</td><td>${c.status === 'pending' ? `<span class="pill p-gold">Clears ${new Date(c.available_at).toLocaleDateString([], { month: 'short', day: 'numeric' })}</span>` : c.status === 'void' ? '<span class="pill p-gray">Refunded</span>' : '<span class="pill p-green">Available</span>'}</td></tr>`).join('')}</table>` : '<p class="mute">No commissions yet. Share your link to get started.</p>'}</div>
  ${a.payouts.length ? `<div class="sec">Payouts</div><div class="card tscroll"><table><tr><th>Date</th><th>Amount</th><th>Status</th><th>Note</th></tr>${a.payouts.map((p) => `<tr><td>${new Date(p.date).toLocaleDateString()}</td><td class="num">${esc(p.label)}</td><td><span class="pill ${p.status === 'paid' ? 'p-green' : p.status === 'rejected' ? 'p-red' : 'p-blue'}">${esc(p.status)}</span></td><td class="mute">${esc(p.note || '')}</td></tr>`).join('')}</table></div>` : ''}
  <div class="sec">How it works</div><div class="card"><ol class="steps"><li>Share your link or code with traders: WhatsApp groups, Telegram, your status, YouTube or TikTok.</li><li>They sign up on the web or in the Android app with your code. They're linked to you for good.</li><li>When they pay for Premium you earn ${a.commission_pct}%${a.recurring ? ', on renewals too' : ''}. Commissions clear after ${a.hold_days} days (refund window).</li><li>Request a payout once you have ${esc(a.min_payout_label)} available.</li></ol>
  <p class="dim" style="font-size:12px;margin-top:14px">No self-referrals or fake accounts. Don't promise profits or guaranteed results when you promote DayFX. Commissions from refunded or fraudulent payments are removed.</p></div>`;
  document.getElementById('cp').onclick = () => { const i = document.getElementById('lnk'); i.select(); navigator.clipboard ? navigator.clipboard.writeText(i.value) : document.execCommand('copy'); toast('Link copied'); };
  bindForm('bd', async (f) => { await api('/affiliate/details', { body: f }); toast('Bank details saved'); });
  bindForm('po', async (f) => { await api('/affiliate/payout', { body: { amount: +f.amount } }); toast('Payout requested'); routes.earn(); });
};

// ------------------------------------------------------------------ account
routes.account = async () => {
  try { const r = await api('/me'); setUser(r.user); } catch (e) {}
  const u = S.user;
  const notif = LS.get('webnotify', false) && 'Notification' in window && Notification.permission === 'granted';
  $root.innerHTML = shell('account', `<div class="head"><div><h1>Account</h1><p class="sub">${esc(u.email)}</p></div>${u.is_premium ? `<span class="premium-badge">${ic('crown')}Premium until ${new Date(u.premium_until).toLocaleDateString()}</span>` : ''}</div>
  <div class="cols"><div>
    <div class="sec" style="margin-top:0">Alerts</div>
    <div class="card">
      <div class="set"><div><b>Email alerts</b><small>New signals and results by email</small></div><label class="switch"><input type="checkbox" data-s="email_alerts" ${u.email_alerts ? 'checked' : ''}><i></i></label></div>
      <div class="set"><div><b>Elite mode</b><small>Only alert me about Elite (85+) signals</small></div><label class="switch"><input type="checkbox" data-s="elite_only" ${u.elite_only ? 'checked' : ''}><i></i></label></div>
      <div class="set"><div><b>Browser notifications</b><small>While a DayFX tab is open. For alerts in your pocket, use the Android app.</small></div><label class="switch"><input type="checkbox" id="wn" ${notif ? 'checked' : ''}><i></i></label></div>
    </div>
    <div class="sec">Profile</div>
    <div class="card"><form id="nm" class="row" style="align-items:flex-end"><label class="field" style="flex:1;margin:0"><span>Name</span><input class="input" name="name" value="${esc(u.name)}"></label><button class="btn ghost" type="submit">Save</button></form></div>
    <div class="card"><h2>Change password</h2><form id="pw"><label class="field"><span>Current password</span><input class="input" type="password" name="current" required></label><label class="field"><span>New password</span><input class="input" type="password" name="password" minlength="8" required></label><button class="btn ghost" type="submit">Update password</button></form></div>
  </div><div>
    <div class="sec" style="margin-top:0">More</div>
    <div class="card list" style="padding:4px 18px">
      ${S.cfg && S.cfg.premium_enabled || u.is_premium ? `<a class="item" href="#/premium">${ic('crown')}Premium<span class="ch">${ic('chev')}</span></a>` : ''}
      <a class="item" href="#/earn">${ic('earn')}Earn with referrals<span class="ch">${ic('chev')}</span></a>
      <a class="item" href="#/tools">${ic('calc')}Lot size calculator &amp; sessions<span class="ch">${ic('chev')}</span></a>
      ${C.playStore ? `<a class="item" href="${esc(C.playStore)}" target="_blank" rel="noopener">${ic('play')}Get the Android app<span class="ch">${ic('chev')}</span></a>` : ''}
      ${C.whatsapp ? `<a class="item" href="https://wa.me/${esc(C.whatsapp)}?text=${encodeURIComponent('Hi, I need help with DayFX Signals')}" target="_blank" rel="noopener">${ic('chat')}Chat with support<span class="ch">${ic('chev')}</span></a>` : ''}
      <a class="item" href="#" id="disc">${ic('shield')}Risk disclaimer<span class="ch">${ic('chev')}</span></a>
      ${C.privacy ? `<a class="item" href="${esc(C.privacy)}" target="_blank">${ic('lock')}Privacy policy<span class="ch">${ic('chev')}</span></a>` : ''}
      ${u.is_admin ? `<a class="item" href="../admin/">${ic('admin')}Admin panel<span class="ch">${ic('chev')}</span></a>` : ''}
    </div>
    <div class="grid" style="margin-top:12px"><button class="btn ghost block" id="lo">${ic('logout')}Sign out</button><button class="btn danger block" id="del">Delete my account</button></div>
  </div></div>`);
  document.querySelectorAll('[data-s]').forEach((c) => c.onchange = async () => {
    try { const r = await api('/me/settings', { body: { [c.dataset.s]: c.checked } }); setUser(r.user); toast('Saved'); } catch (e) { toast(e.message); c.checked = !c.checked; }
  });
  document.getElementById('wn').onchange = async (e) => {
    if (e.target.checked) {
      if (!('Notification' in window)) { toast('This browser does not support notifications'); e.target.checked = false; return; }
      const p = await Notification.requestPermission();
      if (p !== 'granted') { toast('Notifications are blocked in your browser settings'); e.target.checked = false; return; }
      LS.set('webnotify', true); toast('Browser notifications on');
    } else { LS.set('webnotify', false); }
  };
  bindForm('nm', async (f) => { const r = await api('/me/name', { body: f }); setUser(r.user); toast('Name updated'); });
  bindForm('pw', async (f) => { await api('/me/password', { body: f }); toast('Password updated'); document.getElementById('pw').reset(); });
  document.getElementById('lo').onclick = () => logout();
  document.getElementById('disc').onclick = (e) => { e.preventDefault(); disclaimer(true); };
  document.getElementById('del').onclick = () => modal(`<h2>Delete your account?</h2><p class="mute" style="margin-bottom:18px">This permanently deletes your account, settings${u.is_premium ? ', your remaining Premium time' : ''} and affiliate earnings. It can't be undone.</p><div class="row"><button class="btn ghost" data-close>Cancel</button><button class="btn danger" id="cdel">Delete permanently</button></div>`, (m) => {
    m.querySelector('#cdel').onclick = async () => { await api('/me', { method: 'DELETE' }); logout(true); toast('Account deleted'); };
  });
};

// ------------------------------------------------------------------ modal + disclaimer
function modal(html, bind) {
  const m = document.createElement('div'); m.className = 'modal';
  m.innerHTML = `<div class="card">${html}</div>`;
  m.onclick = (e) => { if (e.target === m || e.target.closest('[data-close]')) m.remove(); };
  document.body.appendChild(m); if (bind) bind(m); return m;
}
function disclaimer(force) {
  if (!force && LS.get('disclaimer')) return;
  modal(`<h2>${ic('shield')} Before you trade</h2><div class="mute" style="font-size:14px;display:grid;gap:10px;margin:10px 0 18px">
  <p>Forex and gold trading carries a high risk of loss and is not suitable for everyone. You can lose more than you expect.</p>
  <p>DayFX signals are generated automatically from technical rules and are for education only. They are not financial advice, and no win rate is guaranteed.</p>
  <p>Results are tracked with mid-market prices without spread or commission, so your broker's fills will differ. Past results do not guarantee future results.</p>
  <p>Only trade money you can afford to lose and always use a stop loss.</p></div>
  <button class="btn block" data-close id="acc">I understand</button>`, (m) => { m.querySelector('#acc').addEventListener('click', () => LS.set('disclaimer', true)); });
}

// ------------------------------------------------------------------ boot
(async function boot() {
  try { S.cfg = await api('/config'); } catch (e) { S.cfg = {}; }
  if (S.token) { api('/me').then((r) => setUser(r.user)).catch(() => {}); }
  await route();
  if (S.token) {
    disclaimer(false);
    const q = new URLSearchParams(location.search);
    if ((q.get('reference') || q.get('trxref')) && !location.hash.startsWith('#/premium')) location.hash = '#/premium';
  }
})();
})();
