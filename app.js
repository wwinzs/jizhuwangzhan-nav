'use strict';
(function () {
  var STORAGE_KEY = 'jizhuwangzhan-nav:v1';
  var $ = function (id) { return document.getElementById(id); };
  var searchEngines = { baidu: 'https://www.baidu.com/s?wd=', bing: 'https://www.bing.com/search?q=', google: 'https://www.google.com/search?q=' };
  var colorPalette = ['#5f89f6', '#8b76ef', '#f17b8b', '#f39c61', '#38b2a8', '#526780'];
  var defaults = {
    version: 1, notes: '把重要的事写在这里，\n让灵感随时发生。',
    groups: [
      { id: 'daily', name: '日常网站', icon: '☀' }, { id: 'ai', name: '人工智能', icon: '✧' },
      { id: 'work', name: '效率工具', icon: '⌘' }, { id: 'dev', name: '开发设计', icon: '⚡' },
      { id: 'fun', name: '影音娱乐', icon: '♫' }
    ],
    folders: [],
    layout: {
      mode:'auto', positions:{},
      widgets:{
        order:['quote','calendar','note'],
        sizes:{quote:'normal',calendar:'normal',note:'normal'},
        visible:{quote:true,calendar:true,note:true},
        placement:'below'
      }
    },
    links: [
      ['百度','https://www.baidu.com','daily','百','#5a85f4',true],
      ['哔哩哔哩','https://www.bilibili.com','fun','▶','#e978a2',true],
      ['知乎','https://www.zhihu.com','daily','知','#5597ee',true],
      ['淘宝','https://www.taobao.com','daily','淘','#f58a53',false],
      ['京东','https://www.jd.com','daily','京','#ea6a76',false],
      ['微博','https://weibo.com','daily','微','#e99a58',false],
      ['ChatGPT','https://chatgpt.com','ai','✳','#34af97',true],
      ['DeepSeek','https://chat.deepseek.com','ai','鲸','#4c79df',true],
      ['豆包','https://www.doubao.com','ai','豆','#9170df',false],
      ['Kimi','https://www.kimi.com','ai','K','#54648a',false],
      ['GitHub','https://github.com','dev','⌘','#4b5979',true],
      ['Figma','https://www.figma.com','dev','◈','#a474dc',false],
      ['Excalidraw','https://excalidraw.com','dev','✎','#7189e4',false],
      ['Notion','https://www.notion.so','work','N','#454c62',false],
      ['Google Drive','https://drive.google.com','work','▲','#43b9a5',false],
      ['谷歌翻译','https://translate.google.com','work','译','#5f9ddd',false],
      ['YouTube','https://www.youtube.com','fun','▶','#df6672',false],
      ['抖音','https://www.douyin.com','fun','♪','#343e52',false]
    ].map(function (l, i) { return { id: 'link-' + i, name: l[0], url: l[1], groupId: l[2], icon: l[3], color: l[4], favorite: l[5] }; }),
    settings: { theme: 'dark', wallpaper: 'aurora', customWallpaper: '', engine: 'baidu', showWidgets: true, compact: false }
  };
  var state = { data: null, activeGroup: 'all', editingId: null, sortMode: false, focus: false, monthOffset: 0, contextItemId: null, contextTrigger: null, activeFolderId: null, editingFolderId: null, layoutEditing:false, layoutSelected:null };
  var toastTimer;
  var brokenRaw = null;
  var lastSavedData = null;
  var editingGroupId = null;
  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  function str(v, max) { return typeof v === 'string' ? v.trim().slice(0, max) : ''; }
  function id() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(36).slice(2); }
  function safeUrl(input) {
    if (typeof input !== 'string') return null;
    var value = input.trim();
    if (!value || value.length > 2048) return null;
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) value = 'https://' + value;
    try {
      var url = new URL(value);
      if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || url.username || url.password) return null;
      return url.href;
    } catch (_) { return null; }
  }
  function validate(raw) {
    if (!raw || typeof raw !== 'object' || !Array.isArray(raw.groups) || !Array.isArray(raw.links)) throw Error('无效的导航备份文件');
    if (!raw.groups.length || raw.groups.length > 50 || raw.links.length > 500) throw Error('分类或网站数量超过限制');
    var usedGroups = new Set(), usedLinks = new Set();
    var groups = raw.groups.map(function (g) {
      if (!g || typeof g !== 'object') throw Error('分类格式错误');
      var key = str(g.id, 80) || id();
      while (usedGroups.has(key)) key = id();
      usedGroups.add(key);
      return { id: key, name: str(g.name, 32) || '未命名分类', icon: str(g.icon, 4) || '✦' };
    });
    var incomingFolders = raw.folders === undefined ? [] : raw.folders;
    if (!Array.isArray(incomingFolders) || incomingFolders.length > 100) throw Error('文件夹数据异常或超过数量限制');
    var usedFolders = new Set();
    var folders = incomingFolders.map(function(f){
      if (!f || typeof f !== 'object') throw Error('文件夹格式错误');
      var key = str(f.id,80) || id();
      while (usedFolders.has(key)) key=id();
      usedFolders.add(key);
      return {id:key,name:str(f.name,32)||'未命名文件夹',
        groupId:usedGroups.has(f.groupId) ? f.groupId : groups[0].id};
    });
    var folderMap = new Map(folders.map(function(f){return [f.id,f];}));
    var links = raw.links.map(function (link) {
      if (!link || typeof link !== 'object') throw Error('网站格式错误');
      var url = safeUrl(link.url);
      if (!url) throw Error('备份中包含无效网址');
      var key = str(link.id, 80) || id();
      while (usedLinks.has(key)) key = id();
      usedLinks.add(key);
      var groupId = usedGroups.has(link.groupId) ? link.groupId : groups[0].id;
      var folder = typeof link.folderId === 'string' ? folderMap.get(link.folderId) : null;
      return {
        id: key, url: url, name: str(link.name, 40) || new URL(url).hostname,
        groupId: groupId,
        folderId: folder && folder.groupId === groupId ? folder.id : null,
        icon: str(link.icon, 4) || '✦',
        color: /^#[a-f0-9]{6}$/i.test(link.color) ? link.color : colorPalette[0],
        favorite: link.favorite === true
      };
    });
    var rawLayout = raw.layout && typeof raw.layout === 'object' && !Array.isArray(raw.layout) ? raw.layout : {};
    var rawPositions = rawLayout.positions && typeof rawLayout.positions === 'object' && !Array.isArray(rawLayout.positions) ? rawLayout.positions : {};
    var positions = Object.create(null), occupied = new Set();
    var keys = folders.map(function(f){return 'f:'+f.id;}).concat(links.filter(function(l){return !l.folderId;}).map(function(l){return 'l:'+l.id;}));
    keys.forEach(function(key) {
      var p=rawPositions[key];
      if(Number.isInteger(p) && p>=0 && p<2000 && !occupied.has(p)){
        positions[key]=p;occupied.add(p);
      }
    });
    var rawWidgets = rawLayout.widgets && typeof rawLayout.widgets === 'object' ? rawLayout.widgets : {};
    var widgetNames=['quote','calendar','note'];
    var incomingOrder=Array.isArray(rawWidgets.order) ? rawWidgets.order : [];
    var widgetOrder=widgetNames.filter(function(name){return incomingOrder.includes(name);})
      .sort(function(a,b){return incomingOrder.indexOf(a)-incomingOrder.indexOf(b);});
    widgetNames.forEach(function(name){if(!widgetOrder.includes(name))widgetOrder.push(name);});
    var sizes={}, visibility={};
    widgetNames.forEach(function(name){
      sizes[name]=rawWidgets.sizes && ['normal','wide','compact'].includes(rawWidgets.sizes[name]) ? rawWidgets.sizes[name] : 'normal';
      visibility[name]=!rawWidgets.visible || rawWidgets.visible[name] !== false;
    });
    var layout = {mode:rawLayout.mode==='manual'?'manual':'auto',positions:positions,
      widgets:{order:widgetOrder,sizes:sizes,visible:visibility,
        placement:rawWidgets.placement==='above'?'above':'below'}};
    var settings = raw.settings && typeof raw.settings === 'object' ? raw.settings : {};
    var wp = ['aurora','dusk','ocean','forest','night','custom'].includes(settings.wallpaper) ? settings.wallpaper : 'aurora';
    var custom = typeof settings.customWallpaper === 'string' && /^data:image\/(?:png|jpeg|webp);base64,[a-z0-9+/=]+$/i.test(settings.customWallpaper) && settings.customWallpaper.length <= 3000000 ? settings.customWallpaper : '';
    return {
      version: 1, groups: groups, folders: folders, links: links, layout:layout, notes: typeof raw.notes === 'string' ? raw.notes.slice(0, 10000) : '',
      settings: {
        theme: settings.theme === 'light' ? 'light' : 'dark',
        wallpaper: wp === 'custom' && !custom ? 'aurora' : wp, customWallpaper: custom,
        engine: Object.prototype.hasOwnProperty.call(searchEngines, settings.engine) ? settings.engine : 'baidu',
        showWidgets: settings.showWidgets !== false, compact: settings.compact === true
      }
    };
  }
  function load() {
    var raw = null;
    try { raw = localStorage.getItem(STORAGE_KEY); }
    catch (_) { toast('浏览器禁止读取本地存储，本次修改无法持久保存'); return clone(defaults); }
    if (raw !== null) {
      try { return validate(JSON.parse(raw)); }
      catch (_) {
        // 不删除、不覆盖损坏的存储；用户可以导出原始数据自行检查。
        brokenRaw = raw;
      }
    }
    return clone(defaults);
  }
  function restoreSnapshot() {
    if (!lastSavedData) return;
    state.data = clone(lastSavedData);
    if (state.activeGroup !== 'all' && state.activeGroup !== 'favorites' && !state.data.groups.some(function(g){return g.id === state.activeGroup;})) state.activeGroup = 'all';
    render();
    $('notes').value = state.data.notes;
    $('noteCount').textContent = state.data.notes.length + ' / 10000';
  }
  function save() {
    if (brokenRaw !== null) {
      restoreSnapshot();
      toast('原始数据尚未恢复，保存已暂停；请先下载原始数据');
      return false;
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state.data));
      lastSavedData = clone(state.data);
      return true;
    } catch (_) {
      restoreSnapshot();
      toast('保存失败：本地存储不可用或空间不足，请导出备份');
      return false;
    }
  }
  function exportOriginal() {
    if (brokenRaw === null) return;
    var blob = new Blob([brokenRaw], {type:'application/json'});
    var url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = '记住网站-待恢复的原始数据.json'; link.click();
    setTimeout(function(){URL.revokeObjectURL(url);},1500);
  }
  function resetOriginal() {
    if (brokenRaw === null) return;
    if (!confirm('确定重置无法读取的导航数据吗？请先下载原始文件保存。')) return;
    try {
      var fresh = clone(defaults);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(fresh));
      brokenRaw = null; state.data = fresh; lastSavedData = clone(fresh);
      state.activeGroup = 'all';
      $('storageRecovery').hidden = true;
      $('notes').value = fresh.notes;
      $('noteCount').textContent = fresh.notes.length + ' / 10000';
      render(); toast('本地数据已重置，保存功能恢复');
    } catch (_) { toast('重置失败：浏览器拒绝写入本地存储'); }
  }
  function toast(message) {
    var el = $('toast'); el.textContent = message; el.hidden = false; clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3000);
  }
  function node(tag, className, text) { var el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el; }
  function applySettings() {
    var s = state.data.settings, app = $('app');
    app.dataset.theme = s.theme; app.dataset.wallpaper = s.wallpaper;
    app.classList.toggle('compact', s.compact); app.classList.toggle('focus', state.focus);
    app.classList.toggle('sorting',state.sortMode);
    app.classList.toggle('manual-layout',desktopManual());
    app.classList.toggle('layout-editing',state.layoutEditing && desktopManual());
    if (s.wallpaper === 'custom' && s.customWallpaper) app.style.setProperty('--custom-wallpaper', 'url("' + s.customWallpaper + '")');
    else app.style.removeProperty('--custom-wallpaper');
    applyWidgetLayout();
    renderWidgetSettings();
    $('engine').value = s.engine;
    $('showWidgets').checked = s.showWidgets;
    $('compact').checked = s.compact;
    document.querySelectorAll('[data-theme]').forEach(function (b) { if (b.tagName === 'BUTTON') b.classList.toggle('selected', b.dataset.theme === s.theme); });
    document.querySelectorAll('[data-wallpaper]').forEach(function (b) { if (b.tagName === 'BUTTON') b.classList.toggle('selected', b.dataset.wallpaper === s.wallpaper); });
    $('focusButton').lastElementChild.textContent = state.focus ? '退出专注' : '专注模式';
  }
  function closeMobile(restoreFocus) {
    $('sidebar').classList.remove('mobile-open');
    $('sidebar').inert = true;
    $('sidebar').setAttribute('aria-hidden','true');
    $('mobileScrim').hidden = true;
    $('menu').setAttribute('aria-expanded','false');
    if (restoreFocus) $('menu').focus();
  }
  function openSidebar() {
    hideContext(false);
    $('sidebar').inert = false;
    $('sidebar').removeAttribute('aria-hidden');
    $('sidebar').classList.add('mobile-open');
    $('mobileScrim').hidden = false;
    $('menu').setAttribute('aria-expanded','true');
    $('closeSidebar').focus();
  }
  function selectGroup(key) { if(state.layoutEditing)finishLayoutEdit(); state.activeGroup = key; $('filter').value = ''; render(); closeMobile(); }
  function renderGroups() {
    var nav = $('groupNav'); nav.replaceChildren();
    var choices = [{ id: 'all', icon: '▦', name: '全部站点', count: state.data.links.length }, { id: 'favorites', icon: '♥', name: '我的常用', count: state.data.links.filter(function (v) { return v.favorite; }).length }];
    state.data.groups.forEach(function (g) {
      choices.push({ id: g.id, icon: g.icon, name: g.name, count: state.data.links.filter(function (l) { return l.groupId === g.id; }).length });
    });
    choices.forEach(function (g) {
      var btn = node('button', 'group-row' + (state.activeGroup === g.id ? ' active' : ''));
      btn.type = 'button'; btn.title = g.name; btn.setAttribute('aria-label',g.name + '，' + g.count + ' 个网站'); btn.append(node('span','group-icon',g.icon), node('span','group-name',g.name), node('span','count',String(g.count)));
      btn.addEventListener('click', function () { selectGroup(g.id); }); nav.append(btn);
    });
  }
  function hideContext(restoreFocus) {
    var menu = $('linkContextMenu');
    if (!menu.hidden) {
      var trigger = state.contextTrigger;
      menu.hidden = true;
      state.contextItemId = null;
      state.contextTrigger = null;
      if (restoreFocus && trigger && trigger.isConnected) trigger.focus();
    }
  }
  function openContext(item, x, y, trigger) {
    if (state.sortMode) return;
    hideContext(false);
    state.contextItemId = item.id;
    state.contextTrigger = trigger;
    var menu = $('linkContextMenu');
    $('contextFav').textContent = item.favorite ? '♥ 取消常用' : '♡ 加入常用';
    menu.hidden = false;
    var bounds = menu.getBoundingClientRect();
    var left = Math.max(8, Math.min(x, window.innerWidth - bounds.width - 8));
    var top = Math.max(8, Math.min(y, window.innerHeight - bounds.height - 8));
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    $('contextOpen').focus();
  }
  function contextItem() {
    return state.data.links.find(function (item) { return item.id === state.contextItemId; });
  }
  function reorderLink(sourceId,targetId) {
    var from = state.data.links.findIndex(function(l){return l.id===sourceId;});
    var to = state.data.links.findIndex(function(l){return l.id===targetId;});
    if(from<0 || to<0 || from===to)return false;
    var moved=state.data.links.splice(from,1)[0];
    state.data.links.splice(to,0,moved);
    if(!save())return false;
    renderTiles();
    return true;
  }
  function linkAtPoint(x,y) {
    var el=document.elementFromPoint(x,y);
    var tile=el && el.closest ? el.closest('.tile[data-link-id]') : null;
    return tile ? tile.dataset.linkId : null;
  }
  function tile(item) {
    var wrap = node('div','tile'), logo = node('span','tile-icon'), title = node('span','tile-name',item.name);
    // 默认尝试获取真实站点图标，失败则使用用户设置的字符图标。
    var fallback = node('span','favicon-letter',item.icon);
    logo.append(fallback);
    try {
      var hostname = new URL(item.url).hostname;
      var favicon = node('img','favicon-img');
      favicon.alt = ''; favicon.decoding = 'async'; favicon.referrerPolicy = 'no-referrer';
      favicon.addEventListener('load',function() {
        if (favicon.naturalWidth >= 16) { logo.append(favicon); logo.classList.add('has-favicon'); }
      });
      favicon.src = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(hostname) + '&sz=128';
    } catch (_) { /* 保留字符图标 */ }
    logo.style.background = item.color;
    wrap.dataset.linkId=item.id;
    wrap.draggable = state.sortMode || state.layoutEditing;
    if (state.sortMode) {
      wrap.addEventListener('dragstart', function (e) {
        if (!e.dataTransfer) return;
        wrap.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain',item.id);
      });
      wrap.addEventListener('dragend',function(){wrap.classList.remove('dragging');});
      wrap.addEventListener('dragover',function(e){e.preventDefault();if(e.dataTransfer)e.dataTransfer.dropEffect='move';});
      wrap.addEventListener('drop',function(e){
        e.preventDefault();if(!e.dataTransfer)return;
        reorderLink(e.dataTransfer.getData('text/plain'),item.id);
      });
      var touchSorting=false,dropTarget=null;
      wrap.addEventListener('pointerdown',function(e){
        if(e.pointerType!=='touch'&&e.pointerType!=='pen')return;
        e.preventDefault();touchSorting=true;dropTarget=null;
        wrap.classList.add('touch-sorting');
        try{wrap.setPointerCapture(e.pointerId);}catch(_){}
      });
      wrap.addEventListener('pointermove',function(e){
        if(!touchSorting)return;
        e.preventDefault();
        dropTarget=linkAtPoint(e.clientX,e.clientY);
        document.querySelectorAll('.tile.drop-target').forEach(function(t){t.classList.remove('drop-target');});
        if(dropTarget && dropTarget!==item.id){
          var target=document.querySelector('.tile[data-link-id="'+dropTarget+'"]');
          if(target)target.classList.add('drop-target');
        }
      });
      function finishTouch(e,cancelled){
        if(!touchSorting)return;
        touchSorting=false;wrap.classList.remove('touch-sorting');
        document.querySelectorAll('.tile.drop-target').forEach(function(t){t.classList.remove('drop-target');});
        try{if(wrap.hasPointerCapture(e.pointerId))wrap.releasePointerCapture(e.pointerId);}catch(_){}
        if(!cancelled){
          var dest=linkAtPoint(e.clientX,e.clientY)||dropTarget;
          if(dest && dest!==item.id)reorderLink(item.id,dest);
        }
        dropTarget=null;
      }
      wrap.addEventListener('pointerup',function(e){finishTouch(e,false);});
      wrap.addEventListener('pointercancel',function(e){finishTouch(e,true);});
    }
    var link = node('a','tile-link'); link.href = item.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
    link.setAttribute('aria-label',state.sortMode ? '排序 ' + item.name + '，可使用 Alt 加方向键' : '打开 ' + item.name); link.append(logo,title);
    link.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-decoration:none;color:inherit;width:100%;height:100%';
    wrap.append(link);
    // 桌面右键、键盘 Shift+F10、触屏长按共用同一菜单。
    link.addEventListener('contextmenu',function(e){
      e.preventDefault();
      if(!state.layoutEditing)openContext(item,e.clientX,e.clientY,link);
    });
    link.addEventListener('keydown',function(e){
      if(state.sortMode && e.altKey && ['ArrowLeft','ArrowUp','ArrowRight','ArrowDown'].includes(e.key)){
        e.preventDefault();
        var ids=Array.from(document.querySelectorAll('.tile[data-link-id]')).map(function(n){return n.dataset.linkId;});
        var pos=ids.indexOf(item.id),next=pos + (e.key==='ArrowLeft'||e.key==='ArrowUp'?-1:1);
        if(pos>=0 && next>=0 && next<ids.length && reorderLink(item.id,ids[next])){
          var nextLink=document.querySelector('.tile[data-link-id="'+item.id+'"] .tile-link');
          if(nextLink)nextLink.focus();
        }
        return;
      }
      if (!state.layoutEditing && ((e.shiftKey && e.key === 'F10') || e.key === 'ContextMenu')) {
        e.preventDefault();
        var b = link.getBoundingClientRect();
        openContext(item,b.left + b.width/2,b.top + b.height/2,link);
      }
    });
    var pressTimer = null, originX = 0, originY = 0, longPressed = false;
    function cancelPress(){ if (pressTimer !== null) clearTimeout(pressTimer); pressTimer = null; }
    link.addEventListener('pointerdown',function(e){
      cancelPress(); longPressed = false;
      if (state.sortMode || state.layoutEditing || (e.pointerType !== 'touch' && e.pointerType !== 'pen')) return;
      originX = e.clientX; originY = e.clientY;
      var x = e.clientX, y = e.clientY;
      pressTimer = setTimeout(function(){
        pressTimer = null; longPressed = true;
        openContext(item,x,y,link);
      },560);
    });
    link.addEventListener('pointermove',function(e){
      if (Math.abs(e.clientX-originX)>12 || Math.abs(e.clientY-originY)>12) cancelPress();
    });
    link.addEventListener('pointerup',cancelPress);
    link.addEventListener('pointercancel',cancelPress);
    link.addEventListener('pointerleave',cancelPress);
    link.addEventListener('click',function(e){
      if (state.sortMode || state.layoutEditing || longPressed) { e.preventDefault(); if(!state.layoutEditing)e.stopPropagation(); longPressed=false; }
    });
    attachLayoutHandlers(wrap,'l:'+item.id);
    return wrap;
  }
  function folderTile(folder) {
    var count=state.data.links.filter(function(l){return l.folderId === folder.id;}).length;
    var btn=node('button','tile tile-folder');
    btn.type='button'; btn.dataset.folderId=folder.id;
    btn.title='打开 '+folder.name+'（'+count+' 个网站）';
    btn.setAttribute('aria-label',btn.title);
    btn.append(node('span','tile-icon folder-icon','▤'),node('span','tile-name',folder.name),node('span','folder-count',count+' 个'));
    btn.addEventListener('click',function(){
      if(state.layoutEditing){selectLayoutTile('f:'+folder.id);return;}
      if(state.sortMode){toast('请先退出图标排序模式再打开文件夹');return;}
      openFolder(folder.id);
    });
    attachLayoutHandlers(btn,'f:'+folder.id);
    return btn;
  }
  function renderFolderContents() {
    var box=$('folderContents'); box.replaceChildren();
    var folder=state.data.folders.find(function(f){return f.id===state.activeFolderId;});
    if(!folder)return;
    var members=state.data.links.filter(function(l){return l.folderId===folder.id;});
    $('folderTitle').textContent=folder.name;
    $('folderCount').textContent=members.length+' 个网站 · '+(state.data.groups.find(function(g){return g.id===folder.groupId;})||{}).name;
    if(!members.length)box.append(node('p','folder-empty','这里还没有网站，点击下方「添加网站」。'));
    members.forEach(function(item,i){
      var row=node('div','folder-entry'), a=node('a','folder-entry-link');
      a.href=item.url; a.target='_blank'; a.rel='noopener noreferrer';
      a.append(node('span','folder-entry-icon',item.icon),node('span','folder-entry-name',item.name));
      var buttons=node('div','folder-entry-actions');
      function action(label,title,fn){
        var b=node('button','',label);b.type='button';b.title=title;b.setAttribute('aria-label',title+' '+item.name);
        b.addEventListener('click',fn);buttons.append(b);
      }
      action('↑','上移',function(){
        if(i===0)return;
        if(reorderLink(item.id,members[i-1].id))renderFolderContents();
      });
      action('↓','下移',function(){
        if(i>=members.length-1)return;
        if(reorderLink(item.id,members[i+1].id))renderFolderContents();
      });
      action('✎','编辑网站',function(){
        $('folderDialog').close();openEdit(item);
      });
      action('↗','移出文件夹',function(){
        item.folderId=null;
        if(save()){render();renderFolderContents();toast('网站已移至桌面');}
      });
      row.append(a,buttons);box.append(row);
    });
  }
  function openFolder(folderId) {
    var folder=state.data.folders.find(function(f){return f.id===folderId;});
    if(!folder)return;
    state.activeFolderId=folderId;
    renderFolderContents();
    $('folderDialog').showModal();
  }
  function renderFolderManager() {
    var box=$('folderManager');box.replaceChildren();
    state.data.folders.forEach(function(folder){
      var row=node('div','manage-row');
      var count=state.data.links.filter(function(l){return l.folderId===folder.id;}).length;
      var label=node('span','', '▤  '+folder.name+' · '+count);
      var edit=node('button','','编辑');
      edit.type='button';edit.addEventListener('click',function(){
        $('settingsDialog').close();changeFolder(folder);
      });
      row.append(label,edit);box.append(row);
    });
    if(!state.data.folders.length)box.append(node('p','folder-empty','还没有文件夹'));
  }
  function changeFolder(folder) {
    state.editingFolderId=folder?folder.id:null;
    $('folderEditTitle').textContent=folder?'编辑文件夹':'新建文件夹';
    $('folderName').value=folder?folder.name:'';
    $('folderError').textContent='';
    var group=$('folderGroup');group.replaceChildren();
    state.data.groups.forEach(function(g){
      var option=node('option','',g.name);option.value=g.id;group.append(option);
    });
    group.value=folder?folder.groupId:state.data.groups.some(function(g){return g.id===state.activeGroup;})?state.activeGroup:state.data.groups[0].id;
    $('folderDelete').hidden=!folder;
    $('folderEditDialog').showModal();$('folderName').focus();
  }
  function saveFolder(e) {
    e.preventDefault();
    var name=$('folderName').value.trim(), groupId=$('folderGroup').value;
    if(!name || name.length>32){$('folderError').textContent='文件夹名称应为 1 至 32 个字符';return;}
    if(!state.data.groups.some(function(g){return g.id===groupId;})){$('folderError').textContent='请选择有效分类';return;}
    var existing=state.data.folders.find(function(f){return f.id===state.editingFolderId;});
    if(state.editingFolderId && !existing){$('folderError').textContent='文件夹不存在，请重新打开';return;}
    if(existing){
      existing.name=name;
      existing.groupId=groupId;
      state.data.links.forEach(function(l){if(l.folderId===existing.id)l.groupId=groupId;});
    } else {
      if(state.data.folders.length>=100){$('folderError').textContent='最多支持 100 个文件夹';return;}
      existing={id:id(),name:name,groupId:groupId};state.data.folders.push(existing);
    }
    if(!save())return;
    $('folderEditDialog').close();render();toast('文件夹已保存');
  }
  function deleteFolder(folder) {
    if(!confirm('删除「'+folder.name+'」文件夹？里面的网站会回到桌面，不会删除。'))return;
    state.data.folders=state.data.folders.filter(function(f){return f.id!==folder.id;});
    state.data.links.forEach(function(l){if(l.folderId===folder.id)l.folderId=null;});
    if(!save())return;
    state.activeFolderId=null;
    $('folderEditDialog').close();render();toast('文件夹已删除，网站已保留');
  }
  function refreshFolderChoices(selected) {
    var list=$('linkFolder'), groupId=$('linkGroup').value;
    list.replaceChildren();
    var base=node('option','','桌面（不放入文件夹）');base.value='';list.append(base);
    state.data.folders.filter(function(f){return f.groupId===groupId;}).forEach(function(folder){
      var option=node('option','',folder.name);option.value=folder.id;list.append(option);
    });
    list.value=selected && Array.from(list.options).some(function(x){return x.value===selected;}) ? selected:'';
  }
  function desktopKeys() {
    return state.data.folders.map(function(f){return 'f:'+f.id;})
      .concat(state.data.links.filter(function(l){return !l.folderId;}).map(function(l){return 'l:'+l.id;}));
  }
  function desktopManual() {
    return state.data.layout.mode==='manual' && !state.sortMode && state.activeGroup==='all' &&
      !$('filter').value.trim() && window.matchMedia('(min-width: 851px)').matches;
  }
  function layoutColumns() {
    var template=getComputedStyle($('tiles')).gridTemplateColumns;
    return Math.max(1,template.split(' ').filter(Boolean).length);
  }
  function positionsFor(keys) {
    var saved=state.data.layout.positions, result=Object.create(null), used=new Set();
    keys.forEach(function(key){
      var n=saved[key];
      if(Number.isInteger(n)&&n>=0&&n<2000&&!used.has(n)){
        result[key]=n;used.add(n);
      }
    });
    keys.forEach(function(key){
      if(result[key]!==undefined)return;
      var n=0;while(used.has(n))n++;
      result[key]=n;used.add(n);
    });
    return result;
  }
  function moveToSlot(key,slot) {
    if (!state.layoutEditing || !desktopManual() || !Number.isInteger(slot) || slot<0 || slot>=2000)return false;
    var keys=desktopKeys();
    if(!keys.includes(key))return false;
    var current=positionsFor(keys), from=current[key];
    if(from===slot)return true;
    var other=keys.find(function(x){return x!==key && current[x]===slot;});
    current[key]=slot;
    if(other)current[other]=from;
    state.data.layout.positions=current;
    if(!save())return false;
    renderTiles();
    return true;
  }
  function selectLayoutTile(key) {
    if(!state.layoutEditing || !desktopManual())return;
    if(state.layoutSelected && state.layoutSelected!==key){
      var positions=positionsFor(desktopKeys());
      if(moveToSlot(state.layoutSelected,positions[key])){state.layoutSelected=null;renderTiles();}
      return;
    }
    state.layoutSelected=state.layoutSelected===key?null:key;
    highlightSelection();
  }
  function highlightSelection() {
    document.querySelectorAll('.tile-layout-selected').forEach(function(el){el.classList.remove('tile-layout-selected');});
    if(!state.layoutSelected)return;
    var list=Array.from(document.querySelectorAll('#tiles [data-layout-key]'));
    var target=list.find(function(el){return el.dataset.layoutKey===state.layoutSelected;});
    if(target)target.classList.add('tile-layout-selected');
  }
  function attachLayoutHandlers(el,key) {
    if(!state.layoutEditing || !desktopManual())return;
    el.dataset.layoutKey=key;el.draggable=true;
    el.addEventListener('click',function(e){e.preventDefault();selectLayoutTile(key);});
    el.addEventListener('dragstart',function(e){
      if(!e.dataTransfer)return;
      e.dataTransfer.effectAllowed='move';
      e.dataTransfer.setData('text/plain','desktop:'+key);
      el.classList.add('dragging');
      state.layoutSelected=key;
    });
    el.addEventListener('dragend',function(){el.classList.remove('dragging');});
    el.addEventListener('dragover',function(e){e.preventDefault();});
    el.addEventListener('drop',function(e){
      e.preventDefault();if(!e.dataTransfer)return;
      var data=e.dataTransfer.getData('text/plain');
      if(!data.startsWith('desktop:'))return;
      var keySource=data.slice(8),target=positionsFor(desktopKeys())[key];
      if(moveToSlot(keySource,target))state.layoutSelected=null;
    });
    var tracking=false, candidate=null;
    el.addEventListener('pointerdown',function(e){
      if(e.pointerType!=='touch' && e.pointerType!=='pen')return;
      e.preventDefault();tracking=true;candidate=null;
      try{el.setPointerCapture(e.pointerId);}catch(_){}
    });
    el.addEventListener('pointermove',function(e){
      if(!tracking)return;
      e.preventDefault();
      var target=document.elementFromPoint(e.clientX,e.clientY);
      var node=target && target.closest('[data-layout-slot]');
      candidate=node?Number(node.dataset.layoutSlot):null;
      document.querySelectorAll('.layout-drop-target').forEach(function(t){t.classList.remove('layout-drop-target');});
      if(node && node!==el)node.classList.add('layout-drop-target');
    });
    function finish(e,cancelled){
      if(!tracking)return;
      tracking=false;
      document.querySelectorAll('.layout-drop-target').forEach(function(t){t.classList.remove('layout-drop-target');});
      try{if(el.hasPointerCapture(e.pointerId))el.releasePointerCapture(e.pointerId);}catch(_){}
      if(!cancelled && candidate!==null && moveToSlot(key,candidate))state.layoutSelected=null;
      candidate=null;
    }
    el.addEventListener('pointerup',function(e){finish(e,false);});
    el.addEventListener('pointercancel',function(e){finish(e,true);});
    el.addEventListener('keydown',function(e){
      if(!e.altKey || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
      e.preventDefault();e.stopPropagation();
      var map=positionsFor(desktopKeys()),col=layoutColumns();
      var delta=e.key==='ArrowLeft'?-1:e.key==='ArrowRight'?1:e.key==='ArrowUp'?-col:col;
      var slot=map[key]+delta;
      if(slot<0 || slot>=2000)return;
      if((e.key==='ArrowLeft' && map[key]%col===0) || (e.key==='ArrowRight' && map[key]%col===col-1))return;
      if(moveToSlot(key,slot)){
        var next=Array.from(document.querySelectorAll('[data-layout-key]')).find(function(n){return n.dataset.layoutKey===key;});
        if(next)(next.querySelector('.tile-link')||next).focus();
      }
    });
  }
  function layoutSlot(slot) {
    var button=node('button','tile-slot');
    button.type='button';button.dataset.layoutSlot=String(slot);
    button.setAttribute('aria-label','空白位置 '+(slot+1));
    button.title='放置图标到此位置';
    var col=layoutColumns();
    button.style.gridColumnStart=String(slot%col+1);
    button.style.gridRowStart=String(Math.floor(slot/col)+1);
    button.addEventListener('click',function(){
      if(state.layoutSelected){
        if(moveToSlot(state.layoutSelected,slot))state.layoutSelected=null;
      }else toast('请先点击一个网站或文件夹图标');
    });
    button.addEventListener('dragover',function(e){e.preventDefault();button.classList.add('layout-drop-target');});
    button.addEventListener('dragleave',function(){button.classList.remove('layout-drop-target');});
    button.addEventListener('drop',function(e){
      e.preventDefault();button.classList.remove('layout-drop-target');
      if(!e.dataTransfer)return;
      var data=e.dataTransfer.getData('text/plain');
      if(data.startsWith('desktop:') && moveToSlot(data.slice(8),slot))state.layoutSelected=null;
    });
    return button;
  }
  function autoTidy() {
    state.data.layout.mode='auto';
    state.data.layout.positions={};
    state.layoutEditing=false;state.layoutSelected=null;
    if(!save())return;
    $('layoutToolbar').hidden=true;
    applySettings();renderTiles();toast('已自动整理为紧凑网格');
  }
  function startLayoutEdit() {
    if(!window.matchMedia('(min-width: 851px)').matches){
      toast('自由摆放适用于电脑宽屏；手机可使用图标排序');
      return;
    }
    state.activeGroup='all';state.sortMode=false;state.layoutSelected=null;
    state.data.layout.mode='manual';
    if(!save())return;
    state.layoutEditing=true;
    $('settingsDialog').close();
    $('filter').value='';$('filter').hidden=true;
    $('filterToggle').setAttribute('aria-expanded','false');
    $('sortMode').classList.remove('active');
    $('sortMode').textContent='⇅ 图标排序';
    $('layoutToolbar').hidden=false;
    render();toast('自由排列模式：点选图标后点空位，也可拖动');
    $('linksSection').scrollIntoView({behavior:'smooth',block:'start'});
  }
  function finishLayoutEdit() {
    state.layoutEditing=false;state.layoutSelected=null;
    $('layoutToolbar').hidden=true;
    applySettings();renderTiles();
    toast('桌面布局已保存');
  }
  function renderWidgetSettings() {
    var box=$('widgetManager');box.replaceChildren();
    var meta={quote:'今日灵感',calendar:'轻日历',note:'随手记'};
    var prefs=state.data.layout.widgets;
    $('widgetPlacement').value=prefs.placement;
    prefs.order.forEach(function(name,index){
      var row=node('div','widget-manager-row');
      var label=node('span','widget-manager-name',meta[name]);
      var visible=node('input');visible.type='checkbox';visible.checked=prefs.visible[name];visible.setAttribute('aria-label','显示'+meta[name]);
      visible.addEventListener('change',function(){
        prefs.visible[name]=visible.checked;
        if(save()){applySettings();renderWidgetSettings();}
      });
      var size=node('select','widget-size-select');size.setAttribute('aria-label',meta[name]+'尺寸');
      [['compact','紧凑'],['normal','标准'],['wide','宽版']].forEach(function(pair){
        var opt=node('option','',pair[1]);opt.value=pair[0];size.append(opt);
      });
      size.value=prefs.sizes[name];
      size.addEventListener('change',function(){
        prefs.sizes[name]=size.value;
        if(save()){applySettings();renderWidgetSettings();}
      });
      function move(diff){
        var next=index+diff;if(next<0 || next>=prefs.order.length)return;
        prefs.order.splice(index,1);prefs.order.splice(next,0,name);
        if(save()){applySettings();renderWidgetSettings();}
      }
      var up=node('button','widget-reorder','↑');up.type='button';up.title='向前移动 '+meta[name];
      up.setAttribute('aria-label',up.title);up.disabled=index===0;up.addEventListener('click',function(){move(-1);});
      var down=node('button','widget-reorder','↓');down.type='button';down.title='向后移动 '+meta[name];
      down.setAttribute('aria-label',down.title);down.disabled=index===prefs.order.length-1;down.addEventListener('click',function(){move(1);});
      row.append(label,visible,size,up,down);box.append(row);
    });
  }
  function applyWidgetLayout() {
    var prefs=state.data.layout.widgets,wrap=$('widgets');
    var classes={quote:'.quote-widget',calendar:'.calendar-widget',note:'.note-widget'};
    prefs.order.forEach(function(name){
      var widget=wrap.querySelector(classes[name]);if(!widget)return;
      widget.dataset.widget=name;
      widget.classList.remove('widget-size-normal','widget-size-compact','widget-size-wide');
      widget.classList.add('widget-size-'+prefs.sizes[name]);
      widget.hidden=!prefs.visible[name];wrap.append(widget);
    });
    wrap.hidden=!state.data.settings.showWidgets || !prefs.order.some(function(name){return prefs.visible[name];});
    $('app').classList.toggle('widgets-above',prefs.placement==='above');
  }
  function renderTiles() {
    hideContext(false);
    var tiles = $('tiles'); tiles.replaceChildren();
    var term = $('filter').value.trim().toLocaleLowerCase();
    var scope = function(l) {return state.activeGroup === 'all' || state.activeGroup === 'favorites' && l.favorite || l.groupId === state.activeGroup;};
    var visible = state.data.links.filter(function(l){
      return scope(l) && (term ? (l.name+' '+l.url).toLocaleLowerCase().includes(term) :
        (!l.folderId || state.activeGroup==='favorites'));
    });
    var folders = !term && state.activeGroup !== 'favorites'
      ? state.data.folders.filter(function(f){return state.activeGroup==='all'||f.groupId===state.activeGroup;}) : [];
    var total = state.data.links.filter(scope).length;
    $('sectionName').textContent = (state.activeGroup === 'all' ? '全部站点' : state.activeGroup === 'favorites' ? '我的常用' : (state.data.groups.find(function (g) { return g.id === state.activeGroup; }) || {}).name || '全部站点') + ' · ' + total;
    var manual=desktopManual(), lastManualSlot=-1, cols=layoutColumns();
    tiles.classList.toggle('layout-grid',manual);
    if(manual){
      var entries=folders.map(function(f){return {key:'f:'+f.id, node:folderTile(f)};})
        .concat(visible.map(function(l){return {key:'l:'+l.id,node:tile(l)};}));
      var placements=positionsFor(entries.map(function(e){return e.key;}));
      var occupied=new Set();
      entries.forEach(function(entry){
        var slot=placements[entry.key];
        entry.node.dataset.layoutSlot=String(slot);
        entry.node.style.gridColumnStart=String(slot%cols+1);
        entry.node.style.gridRowStart=String(Math.floor(slot/cols)+1);
        tiles.append(entry.node);
        occupied.add(slot);lastManualSlot=Math.max(lastManualSlot,slot);
      });
      if(state.layoutEditing){
        var limit=Math.min(2000,Math.max(cols*3,lastManualSlot+cols+1));
        for(var slot=0;slot<limit;slot++){
          if(!occupied.has(slot))tiles.append(layoutSlot(slot));
        }
      }
      highlightSelection();
    } else {
      folders.forEach(function(f){tiles.append(folderTile(f));});
      visible.forEach(function(l){tiles.append(tile(l));});
    }
    if (!term && !state.layoutEditing) {
      var add = node('button','tile tile-add'), ico = node('span','tile-icon','＋'), name = node('span','tile-name','添加网站');
      add.append(ico,name); add.addEventListener('click',function () { openEdit(); }); tiles.append(add);
      if(manual){
        var addSlot=lastManualSlot+1;
        add.style.gridColumnStart=String(addSlot%cols+1);
        add.style.gridRowStart=String(Math.floor(addSlot/cols)+1);
      }
      if(state.activeGroup!=='favorites'){
        var addFolder=node('button','tile tile-add tile-add-folder');
        addFolder.type='button';addFolder.append(node('span','tile-icon','＋'),node('span','tile-name','新建文件夹'));
        addFolder.addEventListener('click',function(){changeFolder();});
        tiles.append(addFolder);
        if(manual){
          var folderSlot=lastManualSlot+2;
          addFolder.style.gridColumnStart=String(folderSlot%cols+1);
          addFolder.style.gridRowStart=String(Math.floor(folderSlot/cols)+1);
        }
      }
    }
    var hint=document.querySelector('.manage-hint');
    if(hint)hint.textContent=state.layoutEditing?'自由排列：点选图标并选择空格，或拖动交换位置':state.sortMode?'排序模式：拖动图标或按 Alt + 方向键':'右键或长按图标进行管理';
    $('empty').hidden = visible.length > 0 || folders.length > 0 || !term;
  }
  function renderManager() {
    var box = $('groupManager'); box.replaceChildren();
    state.data.groups.forEach(function (g) {
      var row = node('div','manage-row'), name = node('span','',g.icon + '  ' + g.name), buttons = node('span');
      var rename = node('button','','编辑'), remove = node('button','','删除');
      rename.addEventListener('click',function () { changeGroup(g); });
      remove.addEventListener('click',function () { deleteGroup(g); });
      buttons.append(rename,remove); row.append(name,buttons); box.append(row);
    });
  }
  function render() { renderGroups(); renderTiles(); renderManager(); renderFolderManager(); applySettings(); }
  function groupSelect() {
    var target = $('linkGroup'); target.replaceChildren();
    state.data.groups.forEach(function (g) { var o = node('option','',g.icon + '  ' + g.name); o.value = g.id; target.append(o); });
  }
  function openEdit(item, preferredFolderId) {
    state.editingId = item ? item.id : null; groupSelect();
    $('dialogTitle').textContent = item ? '编辑网站' : '添加网站';
    $('linkName').value = item ? item.name : ''; $('linkUrl').value = item ? item.url : '';
    $('linkIcon').value = item ? item.icon : '✦'; $('linkColor').value = item ? item.color : '#708cfc';
    $('linkFav').checked = item ? item.favorite : state.activeGroup === 'favorites';
    var folder = !item && preferredFolderId ? state.data.folders.find(function(f){return f.id===preferredFolderId;}) : null;
    $('linkGroup').value = item ? item.groupId : folder ? folder.groupId : state.data.groups.some(function (g) { return g.id === state.activeGroup; }) ? state.activeGroup : state.data.groups[0].id;
    refreshFolderChoices(item ? item.folderId : folder ? folder.id : null);
    $('editError').textContent = ''; $('deleteLink').hidden = !item;
    $('editDialog').showModal(); $('linkName').focus();
  }
  function saveEdit(e) {
    e.preventDefault();
    var name = $('linkName').value.trim(), url = safeUrl($('linkUrl').value);
    if (!name || name.length > 40) { $('editError').textContent = '网站名称应为 1–40 个字符'; return; }
    if (!url) { $('editError').textContent = '请填写有效的 HTTP/HTTPS 地址'; return; }
    var data = { id: state.editingId || id(), name: name, url: url, icon: $('linkIcon').value.trim().slice(0,4) || '✦', color: $('linkColor').value, groupId: $('linkGroup').value, folderId: $('linkFolder').value || null, favorite: $('linkFav').checked };
    if (state.editingId) { var index = state.data.links.findIndex(function (l) { return l.id === state.editingId; }); if (index >= 0) state.data.links[index] = data; }
    else { if (state.data.links.length >= 500) { $('editError').textContent = '最多只能保存 500 个网站'; return; } state.data.links.push(data); }
    if (!save()) return;
    $('editDialog').close(); render(); toast(state.editingId ? '已更新网站' : '已添加网站');
  }
  function changeGroup(group) {
    editingGroupId = group ? group.id : null;
    $('groupDialogTitle').textContent = group ? '编辑分类' : '新增分类';
    $('groupName').value = group ? group.name : '';
    $('groupIcon').value = group ? group.icon : '✦';
    $('groupError').textContent = '';
    $('groupDialog').showModal();
    $('groupName').focus();
  }
  function saveGroup(e) {
    e.preventDefault();
    var name = $('groupName').value.trim();
    var icon = $('groupIcon').value.trim().slice(0,4) || '✦';
    if (!name || name.length > 32) { $('groupError').textContent = '分类名称应为 1 至 32 个字符'; return; }
    var group = state.data.groups.find(function(g){ return g.id === editingGroupId; });
    if (editingGroupId && !group) { $('groupError').textContent = '分类已不存在，请重新打开'; return; }
    if (group) { group.name = name; group.icon = icon; }
    else {
      if (state.data.groups.length >= 50) { $('groupError').textContent = '最多支持 50 个分类'; return; }
      var fresh = {id:id(),name:name,icon:icon};
      state.data.groups.push(fresh);
      state.activeGroup = fresh.id;
    }
    if (!save()) return;
    $('groupDialog').close(); render(); toast('分类已保存');
  }
  function deleteGroup(group) {
    if (state.data.groups.length === 1) { toast('至少保留一个分类'); return; }
    if (!confirm('删除「' + group.name + '」分类？网站会移至其他分类，不会丢失。')) return;
    var replacement = state.data.groups.find(function (g) { return g.id !== group.id; }).id;
    state.data.links.forEach(function (l) { if (l.groupId === group.id) l.groupId = replacement; });
    state.data.folders.forEach(function (f) { if (f.groupId === group.id) f.groupId = replacement; });
    state.data.groups = state.data.groups.filter(function (g) { return g.id !== group.id; });
    if (state.activeGroup === group.id) state.activeGroup = 'all';
    if (!save()) return;
    render(); toast('分类已删除，网站已保留');
  }
  function refreshClock() {
    var now = new Date(), h = now.getHours(), min = String(now.getMinutes()).padStart(2,'0');
    $('clock').replaceChildren(document.createTextNode(String(h).padStart(2,'0')),node('span','',':'),document.createTextNode(min));
    $('date').textContent = now.getFullYear() + '年' + (now.getMonth()+1) + '月' + now.getDate() + '日 · 星期' + '日一二三四五六'[now.getDay()];
    $('greeting').textContent = h < 5 ? '夜深了，愿你有个好梦' : h < 11 ? '早上好，开启美好的一天' : h < 14 ? '中午好，享受片刻自在' : h < 19 ? '下午好，专注每个当下' : '晚上好，给生活一点灵感';
  }
  function drawCalendar() {
    var now = new Date(), base = new Date(now.getFullYear(),now.getMonth()+state.monthOffset,1);
    var year = base.getFullYear(), month = base.getMonth(), offset = (new Date(year,month,1).getDay()+6)%7;
    var count = new Date(year,month+1,0).getDate(), previous = new Date(year,month,0).getDate();
    $('monthTitle').textContent = year + '年' + (month+1) + '月';
    var box = $('calendar'); box.replaceChildren();
    ['一','二','三','四','五','六','日'].forEach(function (d) { box.append(node('span','',d)); });
    var slots = offset+count > 35 ? 42 : 35;
    for(var i=0;i<slots;i++) {
      var n = i-offset+1, d = n <= 0 ? previous+n : n>count ? n-count : n;
      var current = n>0 && n<=count, isToday = current && d===now.getDate() && month===now.getMonth() && year===now.getFullYear();
      box.append(node('span',(current?'day':'')+(isToday?' today':''),String(d)));
    }
  }
  function exportBackup() {
    var file = new Blob([JSON.stringify(state.data,null,2)],{type:'application/json'}), url=URL.createObjectURL(file), a=document.createElement('a');
    a.href=url; a.download='记住网站-备份-'+new Date().toISOString().slice(0,10)+'.json'; a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); },1500);
  }
  async function importBackup(e) {
    var file = e.target.files && e.target.files[0]; e.target.value='';
    if(!file) return;
    if(file.size>4000000) { toast('备份文件不能超过 4MB'); return; }
    try {
      var incoming = validate(JSON.parse(await file.text()));
      if (!confirm('导入会覆盖当前导航内容。建议先导出备份，确定继续吗？')) return;
      state.data = incoming; state.activeGroup='all'; if (!save()) return; render(); $('notes').value=state.data.notes; $('noteCount').textContent=state.data.notes.length+' / 10000'; $('settingsDialog').close(); toast('导入成功');
    } catch (err) { toast(err && err.message ? err.message : '文件无法导入'); }
  }
  function setWallpaperFromFile(e) {
    var file=e.target.files && e.target.files[0]; e.target.value=''; if(!file) return;
    if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>2000000){ toast('请上传 2MB 内的 PNG/JPG/WebP 图片'); return; }
    var reader=new FileReader();
    reader.onload=function(){ var result=String(reader.result||''); if(!/^data:image\/(?:png|jpeg|webp);base64,/.test(result)){ toast('壁纸格式无效'); return; } state.data.settings.customWallpaper=result; state.data.settings.wallpaper='custom'; if(save()){ applySettings(); toast('壁纸已更换'); } };
    reader.onerror=function(){ toast('壁纸读取失败'); };
    reader.readAsDataURL(file);
  }
  function bind() {
    $('menu').addEventListener('click',openSidebar);
    $('closeSidebar').addEventListener('click',function(){closeMobile(true);});
    $('mobileScrim').addEventListener('click',function(){closeMobile(true);});
    $('settingsButton').addEventListener('click',function(){hideContext(false);closeMobile(false);$('settingsDialog').showModal();});
    $('settingsClose').addEventListener('click',function(){ $('settingsDialog').close(); });
    $('filterToggle').addEventListener('click',function(){
      var filter = $('filter'), opening = filter.hidden;
      filter.hidden = !opening;
      $('filterToggle').setAttribute('aria-expanded',String(opening));
      if(opening)filter.focus();
      else {filter.value=''; renderTiles();}
    });
    $('editClose').addEventListener('click',function(){ $('editDialog').close(); }); $('cancelEdit').addEventListener('click',function(){ $('editDialog').close(); });
    $('editForm').addEventListener('submit',saveEdit);
    $('linkGroup').addEventListener('change',function(){refreshFolderChoices(null);});
    $('manageAddFolder').addEventListener('click',function(){
      $('settingsDialog').close();changeFolder();
    });
    $('folderEditForm').addEventListener('submit',saveFolder);
    $('folderEditClose').addEventListener('click',function(){$('folderEditDialog').close();});
    $('folderCancel').addEventListener('click',function(){$('folderEditDialog').close();});
    $('folderDelete').addEventListener('click',function(){
      var folder=state.data.folders.find(function(f){return f.id===state.editingFolderId;});
      if(folder)deleteFolder(folder);
    });
    $('folderClose').addEventListener('click',function(){$('folderDialog').close();});
    $('folderAddLink').addEventListener('click',function(){
      var folderId=state.activeFolderId;
      $('folderDialog').close();openEdit(null,folderId);
    });
    $('folderManage').addEventListener('click',function(){
      var folder=state.data.folders.find(function(f){return f.id===state.activeFolderId;});
      $('folderDialog').close();if(folder)changeFolder(folder);
    });
    $('deleteLink').addEventListener('click',function(){
      if (!state.editingId || !confirm('确定删除这个网站吗？')) return;
      state.data.links=state.data.links.filter(function(l){return l.id!==state.editingId;}); if(!save())return; $('editDialog').close(); render(); toast('网站已删除');
    });
    $('manageAddGroup').addEventListener('click',function(){changeGroup();});
    $('groupForm').addEventListener('submit',saveGroup);
    $('groupClose').addEventListener('click',function(){ $('groupDialog').close(); });
    $('groupCancel').addEventListener('click',function(){ $('groupDialog').close(); });
    $('downloadBrokenData').addEventListener('click',exportOriginal);
    $('resetBrokenData').addEventListener('click',resetOriginal);
    $('filter').addEventListener('input',renderTiles);
    $('sortMode').addEventListener('click',function(){
      state.sortMode=!state.sortMode;
      this.classList.toggle('active',state.sortMode);
      this.textContent=state.sortMode?'✓ 完成排序':'⇅ 图标排序';
      if(state.focus){state.focus=false;applySettings();}
      hideContext(false);applySettings();renderTiles();
      $('settingsDialog').close();
      if(state.sortMode)toast('排序已开启：可用鼠标或触屏拖动，键盘使用 Alt + 方向键');
      $('linksSection').scrollIntoView({behavior:'smooth',block:'start'});
    });
    $('searchForm').addEventListener('submit',function(e){ e.preventDefault();var q=$('searchInput').value.trim();if(!q){$('searchInput').focus();return;} window.open(searchEngines[state.data.settings.engine]+encodeURIComponent(q),'_blank','noopener,noreferrer'); });
    $('engine').addEventListener('change',function(){ state.data.settings.engine=this.value; if(!save())applySettings();});
    $('notes').addEventListener('input',function(){state.data.notes=this.value; $('noteCount').textContent=this.value.length+' / 10000'; save();});
    $('prevMonth').addEventListener('click',function(){ state.monthOffset--;drawCalendar(); });
    $('nextMonth').addEventListener('click',function(){ state.monthOffset++;drawCalendar(); });
    $('today').addEventListener('click',function(){state.monthOffset=0;drawCalendar();});
    $('focusButton').addEventListener('click',function(){
      state.focus=!state.focus;applySettings();
      $('settingsDialog').close();
    });
    document.querySelectorAll('#themeButtons button').forEach(function(b){ b.addEventListener('click',function(){state.data.settings.theme=b.dataset.theme; if(save())applySettings();}); });
    document.querySelectorAll('#wallpaperButtons button').forEach(function(b){b.addEventListener('click',function(){state.data.settings.wallpaper=b.dataset.wallpaper; if(save())applySettings();});});
    $('showWidgets').addEventListener('change',function(){state.data.settings.showWidgets=this.checked; if(save())applySettings();});
    $('compact').addEventListener('change',function(){state.data.settings.compact=this.checked; if(save())applySettings();});
    $('wallpaperUpload').addEventListener('change',setWallpaperFromFile);
    $('export').addEventListener('click',exportBackup); $('import').addEventListener('change',importBackup);
    $('reset').addEventListener('click',function(){
      if(!confirm('确定恢复默认吗？这将清除自定义分类、网址和笔记。建议先导出备份。')) return;
      state.data=clone(defaults); state.activeGroup='all'; if(!save())return; $('notes').value=state.data.notes;render();$('settingsDialog').close();toast('已恢复默认');
    });
    $('contextOpen').addEventListener('click',function(){
      var item=contextItem(); if (!item)return;
      hideContext(false); window.open(item.url,'_blank','noopener,noreferrer');
    });
    $('contextFav').addEventListener('click',function(){
      var item=contextItem(); if (!item)return;
      item.favorite=!item.favorite;
      var message = item.favorite ? '已加入常用' : '已取消常用';
      hideContext(false); if(save()){render();toast(message);}
    });
    $('contextEdit').addEventListener('click',function(){
      var item=contextItem(); if (!item)return;
      hideContext(false);openEdit(item);
    });
    $('contextDelete').addEventListener('click',function(){
      var item=contextItem(); if (!item)return;
      hideContext(false);
      if (!confirm('确定删除「'+item.name+'」吗？'))return;
      state.data.links=state.data.links.filter(function(l){return l.id!==item.id;});
      if(save()){render();toast('网站已删除');}
    });
    document.addEventListener('pointerdown',function(e){
      if(!$('linkContextMenu').hidden && !$('linkContextMenu').contains(e.target)) hideContext(false);
    });
    window.addEventListener('scroll',function(){hideContext(false);},true);
    window.addEventListener('resize',function(){hideContext(false);});
    window.addEventListener('keydown',function(e){
      if(e.key==='Escape'){
        if(!$('linkContextMenu').hidden){e.preventDefault();hideContext(true);return;}
        if($('sidebar').classList.contains('mobile-open')){e.preventDefault();closeMobile(true);return;}
      }
      if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();$('searchInput').focus();$('searchInput').select();}
      if(e.key==='/' && !e.ctrlKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)){e.preventDefault();$('searchInput').focus();}
    });
  }
  state.data=load(); lastSavedData=clone(state.data); bind(); $('storageRecovery').hidden = brokenRaw === null; $('notes').value=state.data.notes; $('noteCount').textContent=state.data.notes.length+' / 10000';
  refreshClock(); drawCalendar(); render(); setInterval(function(){refreshClock();if(new Date().getHours()===0 && new Date().getMinutes()===0)drawCalendar();},10000);
})();