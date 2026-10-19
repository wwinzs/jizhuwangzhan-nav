/* v1.9: optional account UI. This file deliberately does not touch navigation localStorage. */
(function () {
  'use strict';
  var API_BASE = 'https://api-nav.wwinzs.cn';
  var AUTH_HOSTNAME = 'nav.wwinzs.cn';
  var enabled = false, busy = false, user = null;
  var $ = function (id) { return document.getElementById(id); };

  function setMessage(message, isError) {
    var el = $('accountMessage');
    el.textContent = message || '';
    el.classList.toggle('account-error', !!isError);
  }

  function paint() {
    $('accountButton').textContent = user ? '◉' : '♙';
    $('accountButton').setAttribute('aria-label',user ? '已登录账号' : '账户登录');
    $('accountButton').title = user ? '已登录账号' : '账户登录';
    $('accountSignedIn').hidden = !user;
    $('accountSignedOut').hidden = !!user;
    $('accountIdentity').textContent = user && typeof user.email === 'string' ? user.email : '';
    $('accountLoginSubmit').disabled = busy;
    $('accountLogout').disabled = busy;
  }

  async function request(path, options) {
    if (!enabled) throw Error('Account service disabled');
    var response = await fetch(API_BASE + path, Object.assign({
      method: 'GET',
      mode: 'cors',
      credentials: 'include',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }, options || {}));
    var body;
    try { body = await response.json(); }
    catch (_) { throw Error('服务端返回了无效响应'); }
    if (!response.ok) {
      var error = Error(response.status === 429 ? '尝试次数过多，请稍后再试' :
        response.status === 401 ? '账号或密码不正确' : '账号服务暂时不可用');
      error.status = response.status;
      throw error;
    }
    return body;
  }

  async function refresh() {
    if (!enabled) return;
    try {
      var data = await request('/auth/me');
      user = data && data.user && typeof data.user.email === 'string' ? data.user : null;
      setMessage(user ? '当前账号已登录。本地数据仍不会自动同步。' : '');
    } catch (err) {
      user = null;
      if (!err || err.status !== 401) setMessage('无法连接账号服务；仍可继续本地使用导航。', true);
      else setMessage('');
    }
    paint();
  }

  async function login(event) {
    event.preventDefault();
    if (busy || !enabled) return;
    var email = $('accountEmail').value.trim();
    var password = $('accountPassword').value;
    if (!email || !password) return;
    busy = true;paint();setMessage('正在验证账号…');
    try {
      var body = await request('/auth/login', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
        body: JSON.stringify({ email:email, password:password })
      });
      if (!body || !body.user || typeof body.user.email !== 'string')
        throw Error('服务端返回了不完整的账号信息');
      user = body.user;
      $('accountPassword').value = '';
      setMessage('已登录。本地收藏保持原样，云端同步尚未启用。');
    } catch (err) {
      user = null;
      $('accountPassword').value = '';
      setMessage(err && err.message || '无法登录', true);
    } finally {
      busy = false;paint();
    }
  }

  async function logout() {
    if (busy || !enabled) return;
    busy = true;paint();setMessage('正在退出…');
    try {
      await request('/auth/logout', {method:'POST',
        headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
        body: '{}' });
      user = null;
      setMessage('已退出账号，当前浏览器的导航数据不会被删除。');
    } catch (err) {
      setMessage('退出失败；请检查网络后重试。',true);
    } finally {busy=false;paint();}
  }

  function initialize(options) {
    options = options || {};
    var allowedHost = location.hostname === AUTH_HOSTNAME;
    // The disabled default is intentional until DNS, HTTPS and API deployment are verified.
    // Tests may supply testMode in their isolated same-origin browser fixture.
    if (options.testMode === true && location.hostname === '127.0.0.1') allowedHost = true;
    enabled = allowedHost && options.enabled === true;
    API_BASE = options.testMode === true && location.hostname === '127.0.0.1'
      ? (options.apiBase || API_BASE) : 'https://api-nav.wwinzs.cn';
    $('accountButton').hidden = !enabled;
    if (!enabled) {user=null;paint();return;}
    paint();refresh();
  }

  function bind() {
    $('accountButton').addEventListener('click',function () {
      if (!enabled) return;
      $('accountDialog').showModal();
      if (user) $('accountLogout').focus(); else $('accountEmail').focus();
    });
    $('accountClose').addEventListener('click',function () {$('accountDialog').close();});
    $('accountLoginForm').addEventListener('submit',login);
    $('accountLogout').addEventListener('click',logout);
    window.NavAuth = { initialize:initialize, refresh:refresh };
    initialize({ enabled:false });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',bind,{once:true});
  else bind();
})();
