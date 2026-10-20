/* v1.9: optional account UI. This file deliberately does not touch navigation localStorage. */
(function () {
  'use strict';
  var API_BASE = 'https://api-nav.wwinzs.cn';
  var AUTH_HOSTNAME = 'nav.wwinzs.cn';
  var enabled = false, busy = false, user = null, otherSessionCount = 0;
  var resetToken = null, forgotVisible = false, recoveryAvailable = false;
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
    $('accountChangePasswordToggle').disabled = busy;
    $('accountChangePasswordSubmit').disabled = busy;
    $('accountChangePasswordCancel').disabled = busy;
    $('accountSessionsToggle').disabled = busy;
    $('accountRevokeOthers').disabled = busy || otherSessionCount === 0;
    $('accountForgotToggle').disabled = busy;
    $('accountForgotSubmit').disabled = busy;
    $('accountForgotCancel').disabled = busy;
    $('accountResetSubmit').disabled = busy;
    $('accountResetCancel').disabled = busy;
    $('accountForgotToggle').hidden = !recoveryAvailable || !!user || !!resetToken;
    $('accountForgotToggle').setAttribute('aria-expanded', String(forgotVisible));
    $('accountForgotForm').hidden = !!user || !!resetToken || !forgotVisible;
    $('accountResetForm').hidden = !!user || !resetToken;
    $('accountLoginForm').hidden = !user && (!!resetToken || forgotVisible);

    if (!user) {
      otherSessionCount = 0;
      $('accountSessionsPanel').hidden = true;
      $('accountSessionsToggle').setAttribute('aria-expanded', 'false');
      $('accountSessionsList').replaceChildren();
      $('accountSessionsStatus').textContent = '';
      $('accountRevokeOthers').disabled = true;
      $('accountChangePasswordForm').hidden = true;
      $('accountChangePasswordToggle').setAttribute('aria-expanded', 'false');
      $('accountChangePasswordForm').reset();
    }
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

  function togglePasswordForm(close) {
    if (!enabled || !user || busy) return;
    var form = $('accountChangePasswordForm');
    var show = !close && form.hidden;
    form.hidden = !show;
    $('accountChangePasswordToggle').setAttribute('aria-expanded', String(show));
    if (show) $('accountCurrentPassword').focus();
    else { form.reset(); setMessage(''); }
  }

  async function changePassword(event) {
    event.preventDefault();
    if (busy || !enabled || !user) return;
    var current = $('accountCurrentPassword').value;
    var next = $('accountNewPassword').value;
    var confirmation = $('accountConfirmPassword').value;
    if (!current || !next) {
      setMessage('请填写当前密码和新密码。', true);
      return;
    }
    if (next !== confirmation) {
      setMessage('两次输入的新密码不一致。', true);
      return;
    }
    var bytes = new TextEncoder().encode(next).length;
    if (bytes < 14 || bytes > 1024) {
      setMessage('新密码长度须为 14–1024 字节。', true);
      return;
    }
    if (current === next) {
      setMessage('新密码不能与当前密码相同。', true);
      return;
    }
    busy = true; paint(); setMessage('正在安全修改密码…');
    try {
      var result = await request('/auth/change-password', {
        method:'POST',
        headers:{'Content-Type':'application/json', 'Accept':'application/json'},
        body:JSON.stringify({current_password:current, new_password:next})
      });
      if (!result || result.ok !== true || result.reauth_required !== true)
        throw Error('服务端未确认密码已修改');
      user = null;
      $('accountChangePasswordForm').reset();
      $('accountPassword').value = '';
      setMessage('密码已修改，所有设备都需重新登录；本地收藏保持不变。');
    } catch (err) {
      setMessage(err && err.status === 401 ? '当前密码不正确或登录已过期。' :
        err && err.status === 409 ? '密码已在其他会话中改变，请重新登录。' :
        err && err.message || '修改密码失败', true);
    } finally {
      busy = false; paint();
    }
  }

  async function loadSessions() {
    if (!enabled || !user) return;
    $('accountSessionsStatus').textContent = '正在读取登录会话…';
    try {
      var payload = await request('/auth/sessions');
      if (!payload || !Array.isArray(payload.sessions)) throw Error('会话响应无效');
      var list = $('accountSessionsList');
      list.replaceChildren();
      otherSessionCount = 0;
      payload.sessions.slice(0, 50).forEach(function (item) {
        if (!item || typeof item.created_at !== 'string' || typeof item.expires_at !== 'string' ||
            typeof item.current !== 'boolean') return;
        var created = new Date(item.created_at);
        var expires = new Date(item.expires_at);
        if (Number.isNaN(created.getTime()) || Number.isNaN(expires.getTime())) return;
        var li = document.createElement('li');
        li.textContent = (item.current ? '当前会话' : '其他会话') +
          ' · 创建：' + created.toLocaleString('zh-CN') +
          ' · 到期：' + expires.toLocaleString('zh-CN');
        list.appendChild(li);
        if (!item.current) otherSessionCount++;
      });
      $('accountSessionsStatus').textContent = '已显示 ' + list.children.length + ' 条有效会话。';
    } catch (err) {
      otherSessionCount = 0;
      $('accountSessionsStatus').textContent = '无法读取会话；请检查账号服务后重试。';
      $('accountSessionsList').replaceChildren();
    }
    paint();
  }

  function toggleSessionsPanel(close) {
    if (!enabled || !user || busy) return;
    var panel = $('accountSessionsPanel');
    var show = !close && panel.hidden;
    panel.hidden = !show;
    $('accountSessionsToggle').setAttribute('aria-expanded', String(show));
    if (show) loadSessions();
  }

  async function revokeOtherSessions() {
    if (!enabled || !user || busy || otherSessionCount < 1) return;
    if (!window.confirm('确定退出其他所有登录会话吗？当前会话将保持登录。')) return;
    busy = true; paint();
    try {
      var result = await request('/auth/sessions/revoke-others', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({confirm: true})
      });
      if (!result || result.ok !== true || !Number.isInteger(result.revoked_count))
        throw Error('服务端未确认会话已撤销');
      setMessage('已退出其他 ' + result.revoked_count + ' 个会话；当前设备仍保持登录。');
    } catch (err) {
      setMessage(err && err.message || '无法撤销其他会话', true);
      return;
    } finally {
      busy = false; paint();
    }
    await loadSessions();
  }

  function readResetFragment() {
    // A short inline <head> script normally removes the fragment before assets load.
    var fromHead = window.__navResetFragment;
    window.__navResetFragment = null;
    var fromHash = location.hash.startsWith('#password-reset=')
      ? location.hash.slice('#password-reset='.length) : null;
    if (fromHash !== null) history.replaceState(history.state,'',location.pathname+location.search);
    var token = fromHead || fromHash;
    if (typeof token === 'string' && /^[a-f0-9]{64}$/.test(token)) {
      resetToken = token; // Memory-only; never localStorage, query string or logs.
      forgotVisible = false;
    }
  }

  function cancelReset() {
    if (busy) return;
    resetToken = null;
    forgotVisible = false;
    $('accountResetForm').reset();
    $('accountForgotForm').reset();
    paint();
    setMessage('');
  }

  function toggleForgot(close) {
    if (!enabled || user || busy || resetToken) return;
    forgotVisible = !close && !forgotVisible;
    if (!forgotVisible) $('accountForgotForm').reset();
    else {
      $('accountResetEmail').value = $('accountEmail').value.trim();
      $('accountResetEmail').focus();
    }
    setMessage('');
    paint();
  }

  async function sendResetRequest(event) {
    event.preventDefault();
    if (!enabled || user || busy || resetToken) return;
    var email = $('accountResetEmail').value.trim();
    if (!email || email.length > 254) return;
    busy = true;paint();setMessage('正在提交找回请求…');
    try {
      var response = await request('/auth/password-reset/request', {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({email:email})
      });
      if (!response || response.ok !== true) throw Error('服务器响应无效');
      setMessage('如该邮箱符合找回条件，你可能会收到重置邮件。请检查收件箱或垃圾邮件。');
      $('accountForgotForm').reset();
    } catch(err) {
      setMessage(err && err.message || '提交请求失败',true);
    } finally {busy=false;paint();}
  }

  async function confirmReset(event) {
    event.preventDefault();
    if (!enabled || !resetToken || busy) return;
    var password = $('accountResetNewPassword').value;
    var confirm = $('accountResetConfirmPassword').value;
    if (password !== confirm) {
      setMessage('两次输入的新密码不一致。',true);return;
    }
    var length = new TextEncoder().encode(password).length;
    if (length < 14 || length > 1024) {
      setMessage('新密码须为 14–1024 字节。',true);return;
    }
    busy=true;paint();setMessage('正在修改登录密码…');
    try {
      var data = await request('/auth/password-reset/confirm',{
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({token:resetToken,new_password:password})
      });
      if (!data || data.ok !== true || data.reauth_required !== true)
        throw Error('服务器未确认重置成功');
      resetToken=null; user=null;forgotVisible=false;
      $('accountResetForm').reset();
      $('accountPassword').value='';
      setMessage('密码已重置，所有原登录会话均已失效，请用新密码登录。');
    } catch (err) {
      setMessage(err && err.status===400 ? '链接可能已失效或过期，请重新申请找回邮件。' :
        err && err.message || '重置失败',true);
    } finally {busy=false;paint();}
  }

  function initialize(options) {
    options = options || {};
    var allowedHost = location.hostname === AUTH_HOSTNAME;
    // The disabled default is intentional until DNS, HTTPS and API deployment are verified.
    // Tests may supply testMode in their isolated same-origin browser fixture.
    if (options.testMode === true && location.hostname === '127.0.0.1') allowedHost = true;
    enabled = allowedHost && options.enabled === true;
    recoveryAvailable = enabled && options.passwordRecoveryEnabled === true;
    API_BASE = options.testMode === true && location.hostname === '127.0.0.1'
      ? (options.apiBase || API_BASE) : 'https://api-nav.wwinzs.cn';
    readResetFragment();
    $('accountButton').hidden = !enabled;
    if (!enabled) {user=null;forgotVisible=false;paint();return;}
    if (resetToken && recoveryAvailable) {
      user = null;
      paint();
      if (!$('accountDialog').open) $('accountDialog').showModal();
      $('accountResetNewPassword').focus();
    } else {
      paint();refresh();
    }
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
    $('accountChangePasswordToggle').addEventListener('click',function () {togglePasswordForm(false);});
    $('accountChangePasswordCancel').addEventListener('click',function () {togglePasswordForm(true);});
    $('accountChangePasswordForm').addEventListener('submit',changePassword);
    $('accountForgotToggle').addEventListener('click',function () {toggleForgot(false);});
    $('accountForgotCancel').addEventListener('click',function () {toggleForgot(true);});
    $('accountForgotForm').addEventListener('submit',sendResetRequest);
    $('accountResetForm').addEventListener('submit',confirmReset);
    $('accountResetCancel').addEventListener('click',cancelReset);
    $('accountSessionsToggle').addEventListener('click',function () {toggleSessionsPanel(false);});
    $('accountRevokeOthers').addEventListener('click',revokeOtherSessions);
    window.NavAuth = { initialize:initialize, refresh:refresh };
    initialize({ enabled:false });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',bind,{once:true});
  else bind();
})();
