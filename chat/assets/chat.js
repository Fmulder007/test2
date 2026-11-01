(() => {
'use strict';
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const base = (document.querySelector('meta[name="chat-base"]')?.content || '').replace(/\/$/, '');
const app = $('chat-app');
if (!app) return;
async function postForm(url, formData) {
  if (!window.ChatTransport) throw new Error('Не загружен модуль связи chat1.');
  return window.ChatTransport.postForm(url, formData, csrf);
}
async function getJson(url) {
  if (!window.ChatTransport) throw new Error('Не загружен модуль связи chat1.');
  return window.ChatTransport.getJson(url);
}
function setResult(el, message, ok=false) { if(!el) return; el.textContent=message||''; el.classList.toggle('error', !!message && !ok); el.classList.toggle('success', !!message && !!ok); }
const timeline=$('timeline'), composer=$('composer'), sendButton=$('send-button'), userList=$('users-list');
const searchForm=$('search-form'), searchInput=$('search-query');
const darkThemeToggle=$('dark-theme-toggle');
const isAdmin=app.dataset.admin==='1';
const userId=Number(app.dataset.userId||0);
let currentBan=app.dataset.banMode||'none';
let currentNotificationMode='MINE';
let mode='chat', adminPage=null, sidebarPane='users', journalUserId=0, currentDate=null, oldestId=0, newestId=0, notificationCursorId=0, notificationCursorReady=false, hasOlder=false, loadingOlder=false, loadingLatest=false, pollTimer=null, presenceTimer=null, lastJournalId=0, composerRange=null, selectedQuote=null, pendingAttachments=[], storageCleanupTimer=null, storageCleanupInFlight=false, storageCleanupGeneration=0, cleanupProtectMessageId=0, mobileMenuHistoryPushed=false, mobileSmilesHistoryPushed=false;
let allUsers=[], searchState=null, pinnedOnlySearch=false, searchRequestSerial=0, quoteDragBody=null, blockedListState=null, lastTopSearchContext='';
let smileItems=[], smileCodeMap=new Map(), smilePattern=null, smileColumns=3, smileImageSize=24, smileLoadError='', smileCatalogWarning='', mobileStatusToastTimer=null;
let sendInFlight=false, sendCooldownUntil=0, composerPlaceholderTimer=null;
const defaultComposerPlaceholder=composer?.dataset.placeholder||'Напишите сообщение…';

function displaySystem(text,type='info'){
  const message=String(text||'Готово');
  const el=$('status-message');
  if(el){el.textContent=message;el.classList.toggle('status-error',type==='error');el.classList.toggle('status-success',type==='success');el.classList.toggle('status-info',type==='info');}
  const toast=$('mobile-status-toast');
  if(!toast)return;
  if(mobileStatusToastTimer!==null){window.clearTimeout(mobileStatusToastTimer);mobileStatusToastTimer=null;}
  toast.textContent=message;
  toast.classList.toggle('status-error',type==='error');toast.classList.toggle('status-success',type==='success');toast.classList.toggle('status-info',type==='info');
  if(isMobileLayout()&&String(text||'').trim()){
    toast.classList.remove('hidden');
    mobileStatusToastTimer=window.setTimeout(()=>{toast.classList.add('hidden');mobileStatusToastTimer=null;},4200);
  }else toast.classList.add('hidden');
}
function updateSearchClearButton(){const button=$('search-clear');if(!button)return;const visible=!!((searchInput?.value||'').trim()||searchState||pinnedOnlySearch);button.classList.toggle('hidden',!visible);button.disabled=!visible;}
function clearSearchState(clearInput=false){if(searchState||pinnedOnlySearch)searchRequestSerial++;searchState=null;pinnedOnlySearch=false;if(clearInput&&searchInput){searchInput.value='';searchInput.placeholder=mode==='journal'?'Поиск по журналу…':'Поиск по истории…';}updateSearchClearButton();}
function setModeTitle(text){
  const label=$('mode-title');if(!label)return;
  label.textContent=text;
  label.removeAttribute('href');label.removeAttribute('title');label.removeAttribute('aria-label');
}
function setSidebarPane(pane){
  const allowed=new Set(['none','users','smiles','settings']);if(isAdmin)allowed.add('admin');
  sidebarPane=allowed.has(pane)?pane:'users';
  const panes=[
    ['users',$('users-list'),$('users-accordion-toggle')],
    ['smiles',$('smiles-list'),$('smiles-accordion-toggle')],
    ['settings',$('settings-list'),$('settings-accordion-toggle')],
    ['admin',$('admin-menu-list'),$('admin-accordion-toggle')]
  ];
  panes.forEach(([name,content,toggle])=>{
    if(!toggle)return;
    const expanded=sidebarPane===name;
    content?.classList.toggle('hidden',!expanded);
    toggle.setAttribute('aria-expanded',expanded?'true':'false');
    const chev=toggle.querySelector('.accordion-chevron');if(chev)chev.textContent=expanded?'▾':'▸';
    toggle.classList.toggle('accordion-heading-active',expanded);
    toggle.classList.toggle('accordion-heading-collapsed',!expanded);
  });
  syncTopSearchContext({clearOnChange:true});
}
function toggleSidebarPane(pane){setSidebarPane(sidebarPane===pane?'none':pane);}
function isMobileLayout(){return !!(window.matchMedia&&window.matchMedia('(max-width:650px)').matches);}
function getTopSearchContext(){
  if(isMobileLayout()&&document.body.classList.contains('mobile-menu-open'))return sidebarPane==='users'?'users':'none';
  if(mode==='journal')return 'journal';
  if(mode==='admin'){if(adminPage==='blocked_nicks')return 'blocked_nicks';if(adminPage==='blocked_emails')return 'blocked_emails';return 'none';}
  return 'messages';
}
function syncTopSearchContext({clearOnChange=true}={}){
  const context=getTopSearchContext();
  const changed=lastTopSearchContext!==''&&lastTopSearchContext!==context;
  if(changed&&clearOnChange){
    if(searchInput)searchInput.value='';
    if(searchState||pinnedOnlySearch){searchRequestSerial++;searchState=null;pinnedOnlySearch=false;}
  }
  lastTopSearchContext=context;
  const labels={messages:'Поиск по истории…',journal:'Поиск по журналу…',users:'Фильтр пользователей…',blocked_nicks:'Фильтр по никам…',blocked_emails:'Фильтр по email…',none:'Поиск недоступен в этом разделе'};
  const disabled=context==='none';
  if(searchInput){searchInput.placeholder=labels[context]||labels.messages;searchInput.disabled=disabled;searchInput.setAttribute('aria-label',labels[context]||'Поиск');}
  const button=$('search-button');if(button)button.disabled=disabled;
  updateSearchClearButton();
  if(context==='users')applyUsersFilter(searchInput?.value||'');
  if(context==='blocked_nicks'||context==='blocked_emails')applyBlockedListFilter();
}
function placeMenuToggleInHeader(inHeader){
  const toggle=$('mobile-menu-toggle');if(!toggle)return;
  // Keep the control permanently in the topbar so its position and size never change between screens.
  toggle.setAttribute('aria-label',inHeader?'Вернуться в чат':'Открыть меню');toggle.title=inHeader?'Вернуться в чат':'Меню';
}
function openMobileMenu(){
  if(!isMobileLayout())return;
  const smilePanel=$('mobile-smiles-popover');
  const replacingSmileOverlay=!!(smilePanel&&!smilePanel.classList.contains('hidden'));
  if(replacingSmileOverlay){smilePanel.classList.add('hidden');$('mobile-smiles-button')?.setAttribute('aria-expanded','false');mobileSmilesHistoryPushed=false;}
  document.body.classList.add('mobile-menu-open');
  placeMenuToggleInHeader(true);
  syncTopSearchContext({clearOnChange:true});
  try{const state={...(history.state||{}),__lightChatOverlay:'menu'};if(replacingSmileOverlay){history.replaceState(state,'');mobileMenuHistoryPushed=true;}else{history.pushState(state,'');mobileMenuHistoryPushed=true;}}catch{mobileMenuHistoryPushed=false;}
  $('mobile-menu-toggle')?.focus({preventScroll:true});
}
function closeMobileMenu(fromPopState=false){
  const wasOpen=document.body.classList.contains('mobile-menu-open');
  document.body.classList.remove('mobile-menu-open');
  placeMenuToggleInHeader(false);
  syncTopSearchContext({clearOnChange:true});
  if(mobileMenuHistoryPushed){mobileMenuHistoryPushed=false;if(!fromPopState){try{history.back();}catch{}}}
  if(wasOpen&&!fromPopState&&!isMobileLayout())$('mobile-menu-toggle')?.focus({preventScroll:true});
}
function openMobileSmilesPopover(){
  if(!isMobileLayout()||currentBan!=='none')return;
  const panel=$('mobile-smiles-popover');if(!panel)return;
  if(!panel.classList.contains('hidden')){closeMobileSmilesPopover();return;}
  closeMobileMenu(false);
  panel.classList.remove('hidden');$('mobile-smiles-button')?.setAttribute('aria-expanded','true');
  try{history.pushState({...history.state,__lightChatOverlay:'smiles'},'');mobileSmilesHistoryPushed=true;}catch{mobileSmilesHistoryPushed=false;}
}
function closeMobileSmilesPopover(fromPopState=false){
  const panel=$('mobile-smiles-popover');if(!panel||panel.classList.contains('hidden'))return;
  panel.classList.add('hidden');$('mobile-smiles-button')?.setAttribute('aria-expanded','false');
  if(mobileSmilesHistoryPushed){mobileSmilesHistoryPushed=false;if(!fromPopState){try{history.back();}catch{}}}
}
window.addEventListener('popstate',()=>{
  if(document.body.classList.contains('mobile-menu-open')){closeMobileMenu(true);return;}
  if(!$('mobile-smiles-popover')?.classList.contains('hidden')){closeMobileSmilesPopover(true);requestAnimationFrame(()=>focusComposerAtRange());return;}
});
function moveResponsiveControls(){
  const mobile=isMobileLayout();
  const dest=$('mobile-settings-content'), topbar=app.querySelector('.topbar'), mainLayout=app.querySelector('.main-layout');
  const account=$('account-info'), profile=$('profile-panel'), composerBottom=app.querySelector('.composer-bottom');
  const themeLabel=darkThemeToggle?.closest('.dark-theme-setting'), notifyLabel=$('notification-mode')?.closest('.notify-setting');
  if(!dest||!topbar||!composerBottom)return;
  if(mobile){
    if(themeLabel)dest.append(themeLabel);
    if(notifyLabel)dest.append(notifyLabel);
    if(account)dest.append(account);
    if(profile)dest.append(profile);
    closeMobileMenu(false);closeMobileSmilesPopover(false);
  }else{
    if(account)topbar.insertBefore(account,$('mobile-menu-toggle'));
    const themeAnchor=$('dark-theme-anchor'),notifyAnchor=$('notify-setting-anchor');
    if(themeLabel&&themeAnchor?.parentNode)themeAnchor.parentNode.insertBefore(themeLabel,themeAnchor.nextSibling);
    if(notifyLabel&&notifyAnchor?.parentNode)notifyAnchor.parentNode.insertBefore(notifyLabel,notifyAnchor.nextSibling);
    if(profile&&mainLayout)app.insertBefore(profile,mainLayout);
    closeMobileMenu(false);closeMobileSmilesPopover(false);
  }
}
function syncMobileViewport(){
  const shell=app.querySelector('.app-shell'),topbar=app.querySelector('.topbar');if(!shell)return;
  if(!isMobileLayout()){shell.style.removeProperty('--mobile-app-height');shell.style.removeProperty('--mobile-viewport-top');shell.style.removeProperty('--mobile-topbar-height');return;}
  const vv=window.visualViewport;
  const height=Math.round(vv?.height||window.innerHeight||0);
  if(height>0)shell.style.setProperty('--mobile-app-height',height+'px');
  shell.style.setProperty('--mobile-viewport-top',Math.max(0,Math.round(vv?.offsetTop||0))+'px');
  if(topbar)shell.style.setProperty('--mobile-topbar-height',Math.round(topbar.getBoundingClientRect().height)+'px');
}
const responsiveQuery=window.matchMedia?window.matchMedia('(max-width:650px)'):null;
if(responsiveQuery){const onResponsiveChange=()=>{moveResponsiveControls();syncMobileViewport();showModes();};if(responsiveQuery.addEventListener)responsiveQuery.addEventListener('change',onResponsiveChange);else if(responsiveQuery.addListener)responsiveQuery.addListener(onResponsiveChange);}
window.addEventListener('resize',syncMobileViewport,{passive:true});
window.visualViewport?.addEventListener('resize',syncMobileViewport,{passive:true});
function timelineNearBottom(){return timeline.scrollHeight-timeline.scrollTop-timeline.clientHeight<80;}
function adminPageTitle(){return ({storage:'Хранилище',blocked_nicks:'Заблокированные ники',blocked_emails:'Заблокированные Email'})[adminPage]||'Администрирование';}
function returnToLiveChat({focusComposer=true}={}){clearSearchState(true);mode='chat';adminPage=null;journalUserId=0;currentDate=null;$('history-date').value='';showModes();return loadInitial().then(()=>{schedulePolling();if(focusComposer)focusComposerAtEnd();});}
function focusComposerAtEnd(){if(!composer||currentBan!=='none'||$('composer-wrap')?.classList.contains('hidden'))return;try{composer.focus({preventScroll:true});const range=document.createRange();range.selectNodeContents(composer);range.collapse(false);const sel=window.getSelection();if(sel){sel.removeAllRanges();sel.addRange(range);composerRange=range.cloneRange();}}catch{try{composer.focus();}catch{}}}
function focusComposerAtRange(){if(!composer||currentBan!=='none'||$('composer-wrap')?.classList.contains('hidden'))return;let range=composerRange;if(!range||!composer.contains(range.startContainer)||!composer.contains(range.endContainer)){focusComposerAtEnd();return;}try{composer.focus({preventScroll:true});const sel=window.getSelection();if(sel){sel.removeAllRanges();sel.addRange(range);composerRange=range.cloneRange();}}catch{focusComposerAtEnd();}}
function setComposerPlaceholder(text,state=''){
  if(!composer)return;
  composer.dataset.placeholder=String(text||defaultComposerPlaceholder);
  if(state)composer.dataset.placeholderState=state;else delete composer.dataset.placeholderState;
}
function updateSendButtonState(){if(sendButton)sendButton.disabled=sendInFlight||currentBan!=='none'||Date.now()<sendCooldownUntil;}
function clearComposerPlaceholderTimer(){if(composerPlaceholderTimer!==null){clearTimeout(composerPlaceholderTimer);composerPlaceholderTimer=null;}}
function resetComposerPlaceholder(){clearComposerPlaceholderTimer();sendCooldownUntil=0;setComposerPlaceholder(defaultComposerPlaceholder);updateSendButtonState();}
function setComposerCooldown(durationMs,label='Отправлено…'){
  clearComposerPlaceholderTimer();
  const ms=Math.max(0,Number(durationMs)||0);
  if(ms<=0){resetComposerPlaceholder();return;}
  sendCooldownUntil=Date.now()+ms;setComposerPlaceholder(label,'success');
  composerPlaceholderTimer=window.setTimeout(()=>{composerPlaceholderTimer=null;sendCooldownUntil=0;setComposerPlaceholder(defaultComposerPlaceholder);updateSendButtonState();},ms);
  updateSendButtonState();
}
function antifloodWaitMs(message){const m=String(message||'').match(/через\s+(\d+)\s*сек/iu);return m?Math.max(0,Number(m[1])||0)*1000:0;}
function preserveComposerFocusOnMouseDown(event){if(isMobileLayout()&&composer&&document.activeElement===composer)event.preventDefault();}

function isHistorical(){return !!currentDate || mode==='journal';}
function updateComposerAccess(){
  const wrap=$('composer-wrap'), banner=$('blocked-banner');
  if(currentBan!=='none')closeMobileSmilesPopover();
  if(!wrap||!banner)return;
  if(currentBan==='BAN'){wrap.classList.add('hidden');banner.textContent='Вы не можете читать и писать сообщения!';banner.classList.remove('hidden');}
  else if(currentBan==='RO'){wrap.classList.add('hidden');banner.textContent='Заблокировано администратором';banner.classList.remove('hidden');}
  else {wrap.classList.remove('hidden');banner.classList.add('hidden');}
}
function scrollBottom(){timeline.scrollTop=timeline.scrollHeight;}
function rememberComposerRange(){try{const sel=window.getSelection();if(sel&&sel.rangeCount){const r=sel.getRangeAt(0);if(composer.contains(r.startContainer)&&composer.contains(r.endContainer))composerRange=r.cloneRange();}}catch{}}
document.addEventListener('selectionchange',rememberComposerRange);
composer?.addEventListener('keyup',rememberComposerRange);composer?.addEventListener('mouseup',rememberComposerRange);composer?.addEventListener('focus',rememberComposerRange);
function addTextLinks(parent,text){
  const re=/((https?:\/\/|www\.)[^\s<>"']+)/giu;let last=0,m;
  while((m=re.exec(text))!==null){if(m.index>last)parent.append(document.createTextNode(text.slice(last,m.index)));let raw=m[0],trimmed=raw;while(/[),.!?;:]$/.test(trimmed))trimmed=trimmed.slice(0,-1);if(!trimmed){parent.append(document.createTextNode(raw));last=re.lastIndex;continue;}const a=document.createElement('a');a.textContent=trimmed;a.href=/^www\./i.test(trimmed)?'https://'+trimmed:trimmed;a.target='_blank';a.rel='noopener noreferrer';parent.append(a);if(trimmed.length<raw.length)parent.append(document.createTextNode(raw.slice(trimmed.length)));last=re.lastIndex;}
  if(last<text.length)parent.append(document.createTextNode(text.slice(last)));
}
function escapeSmileRegex(value){return String(value).replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}
function appendPlainChunk(parent,text,linkify=true){if(!text)return;if(linkify)addTextLinks(parent,text);else parent.append(document.createTextNode(text));}
function appendTextWithSmiles(parent,text,linkify=true){
  text=String(text||'');
  if(!text)return;
  if(!smilePattern||smileCodeMap.size===0){appendPlainChunk(parent,text,linkify);return;}
  smilePattern.lastIndex=0;let last=0,match;
  while((match=smilePattern.exec(text))!==null){
    if(match.index>last)appendPlainChunk(parent,text.slice(last,match.index),linkify);
    const item=smileCodeMap.get(match[0]);
    if(!item)appendPlainChunk(parent,match[0],linkify);
    else{
      const img=document.createElement('img');img.className='chat-smile';img.alt=match[0];img.title=(item.codes||[]).join(', ');img.loading='lazy';img.decoding='async';img.width=smileImageSize;img.height=smileImageSize;img.dataset.lightSrc=item.light_url;img.dataset.darkSrc=item.dark_url;img.src=document.body.classList.contains('theme-dark')?item.dark_url:item.light_url;img.draggable=false;parent.append(img);
    }
    last=match.index+match[0].length;
    if(match[0].length===0)smilePattern.lastIndex++;
  }
  if(last<text.length)appendPlainChunk(parent,text.slice(last),linkify);
}
function updateSmilesTheme(){
  const dark=document.body.classList.contains('theme-dark');
  document.querySelectorAll('img.chat-smile[data-light-src][data-dark-src]').forEach(img=>{const src=dark?img.dataset.darkSrc:img.dataset.lightSrc;if(src&&img.getAttribute('src')!==src)img.src=src;});
}
function applySmilesToExistingMessages(){
  timeline.querySelectorAll('.message-body').forEach(body=>{if(Array.isArray(body._segments)){body.replaceChildren();renderSegments(body,body._segments);}});
  document.querySelectorAll('#pinned-list .pinned-row').forEach(row=>{if(typeof row._pinnedPreview==='string'){row.replaceChildren(document.createTextNode(row._pinnedPrefix||''));appendTextWithSmiles(row,row._pinnedPreview,false);}});
}
function renderSmilesPanelInto(panel,isMobilePopover=false){
  if(!panel)return;
  panel.replaceChildren();panel.style.setProperty('--smiles-columns',String(smileColumns));app.style.setProperty('--smile-image-size',smileImageSize+'px');
  if(smileLoadError){const msg=document.createElement('div');msg.className='smiles-message';msg.textContent=smileLoadError;panel.append(msg);return;}
  if(smileCatalogWarning){const warning=document.createElement('div');warning.className='smiles-warning';warning.textContent=smileCatalogWarning;panel.append(warning);}
  if(!smileItems.length){const msg=document.createElement('div');msg.className='smiles-message';msg.textContent='В каталоге не найдено смайликов.';panel.append(msg);return;}
  const grid=document.createElement('div');grid.className='smiles-grid';
  smileItems.forEach(item=>{
    const codes=Array.isArray(item.codes)?item.codes:[];
    const button=document.createElement('button');button.type='button';button.className='smile-button';button.title=codes.join(', ');button.setAttribute('aria-label','Вставить смайл '+String(codes[0]||''));button.disabled=currentBan!=='none';
    const img=document.createElement('img');img.className='chat-smile';img.alt=String(codes[0]||'смайл');img.loading='lazy';img.decoding='async';img.width=smileImageSize;img.height=smileImageSize;img.dataset.lightSrc=item.light_url;img.dataset.darkSrc=item.dark_url;img.src=document.body.classList.contains('theme-dark')?item.dark_url:item.light_url;img.draggable=false;button.append(img);
    button.addEventListener('pointerdown',preserveComposerFocusOnMouseDown);
    button.addEventListener('mousedown',preserveComposerFocusOnMouseDown);
    button.addEventListener('click',()=>{
      if(currentBan!=='none'||!codes[0])return;
      insertNodeAtComposer(document.createTextNode(String(codes[0])));
      if(isMobileLayout()&&document.body.classList.contains('mobile-menu-open'))closeMobileMenu();
      focusComposerAtRange();
    });grid.append(button);
  });
  panel.append(grid);
}
function renderSmilesPanel(){
  renderSmilesPanelInto($('smiles-list'),false);
  renderSmilesPanelInto($('mobile-smiles-list'),true);
}
async function loadSmiles(){
  try{
    const d=await getJson(base+'/api/smiles.php');
    if(!d.ok)throw new Error(d.error||'Не удалось загрузить каталог смайлов.');
    smileItems=Array.isArray(d.items)?d.items:[];smileColumns=Math.max(2,Math.min(3,Number(d.columns)||3));smileImageSize=Math.max(12,Math.min(48,Number(d.image_size_px)||24));smileCatalogWarning=String(d.warning||'');smileCodeMap=new Map();
    for(const item of smileItems){for(const code of (item.codes||[])){const c=String(code||'');if(c&&!smileCodeMap.has(c))smileCodeMap.set(c,item);}}
    const codes=Array.from(smileCodeMap.keys()).sort((a,b)=>b.length-a.length);
    smilePattern=codes.length?new RegExp(codes.map(escapeSmileRegex).join('|'),'gu'):null;
    renderSmilesPanel();updateSmilesTheme();applySmilesToExistingMessages();
  }catch(e){smileLoadError=String(e?.message||'Не удалось загрузить набор смайликов.');renderSmilesPanel();}
}
function jumpToMessage(id){
  id=Number(id);if(!id)return;
  getJson(base+'/api/messages.php?target_id='+encodeURIComponent(id)).then(d=>{
    if(!d.ok){displaySystem(d.error||'Не удалось открыть исходное сообщение.','error');return;}
    clearSearchState(true);mode='chat';adminPage=null;journalUserId=0;currentDate=d.date||null;$('history-date').value=currentDate||'';showModes();
    renderMessages(d.messages||[],true);hasOlder=!!d.has_older;oldestId=timeline.querySelector('.message')?Number(timeline.querySelector('.message').dataset.id):0;newestId=Math.max(0,...(d.messages||[]).map(x=>Number(x.id)));
    const target=timeline.querySelector('[data-id="'+id+'"]');if(target){target.scrollIntoView({block:'center'});target.classList.add('jump-highlight');setTimeout(()=>target.classList.remove('jump-highlight'),1800);}
    loadPinned();loadUsers();schedulePolling();
  }).catch(()=>displaySystem('Не удалось открыть исходное сообщение.','error'));
}
function makeQuoteElement(q){const a=document.createElement('a');a.href='#message-'+q.message_id;a.className='quote-link';a.dataset.jumpId=String(q.message_id);a.title='Перейти к исходному сообщению';appendTextWithSmiles(a,String(q.quote_display||('|'+(q.preview||'Цитата'))),false);a.addEventListener('click',e=>{e.preventDefault();jumpToMessage(q.message_id);});return a;}
function renderSegments(container,segments){
  (segments||[]).forEach(seg=>{
    if(seg.type==='text')appendTextWithSmiles(container,String(seg.text||''),true);
    else if(seg.type==='mention'){const span=document.createElement('span');span.className='mention-token';span.textContent='@'+String(seg.nick||'');container.append(span);}
    else if(seg.type==='quote'){container.append(document.createTextNode('\n'));container.append(makeQuoteElement(seg));container.append(document.createTextNode('\n'));}
  });
}
function renderMessage(msg){
  const row=document.createElement('div');row.className='message';if(msg.notify_me&&!msg.is_deleted&&currentBan!=='BAN')row.classList.add('message-for-me');row.dataset.id=String(msg.id);row.dataset.quoteTimestamp=String(msg.created_at_quote_display||msg.created_at_display||'—');row.dataset.authorNick=String(msg.author_nick||'');row.id='message-'+msg.id;
  if(isAdmin){
    const del=document.createElement('button');del.type='button';del.className='admin-action';del.textContent='[DEL]';del.title=msg.is_deleted?'Сообщение уже удалено':'Удалить сообщение';del.disabled=!!msg.is_deleted;if(!msg.is_deleted)del.addEventListener('click',()=>deleteMessage(msg.id));row.append(del);
    const pinControl=document.createElement('button');pinControl.type='button';pinControl.className='admin-action';pinControl.textContent=msg.is_pinned?'[UNPIN]':'[PIN]';pinControl.title=msg.is_pinned?'Снять закрепление':'Закрепить сообщение';pinControl.disabled=!!msg.is_deleted;if(!msg.is_deleted)pinControl.addEventListener('click',()=>pinMessage(msg.id,!msg.is_pinned));row.append(pinControl);
  }
  const time=document.createElement('button');time.type='button';time.className='message-time';time.textContent='['+String(msg.created_at_display||'')+']';time.title='Ответить на сообщение';time.addEventListener('click',()=>insertQuoteToken(Number(msg.id),'',row.dataset.quoteTimestamp,row.dataset.authorNick,body.textContent||''));row.append(time);row.append(document.createTextNode(', '));
  const nick=document.createElement('button');nick.type='button';nick.className='message-nick';nick.textContent=String(msg.author_nick||'');nick.title='Вставить обращение';nick.addEventListener('click',()=>insertMentionToken(Number(msg.user_id),String(msg.author_nick||'')));row.append(nick);row.append(document.createTextNode(', '));
  const body=document.createElement('span');body.className='message-body';body._segments=Array.isArray(msg.body_segments)?msg.body_segments:[];renderSegments(body,body._segments);row.append(body);
  if(msg.attachments&&msg.attachments.length){const wrap=document.createElement('div');wrap.className='message-attachments';msg.attachments.forEach(a=>{
    if(a.thumbnail_url){
      const preview=document.createElement('a');preview.className='attachment-preview-link';
      // Только клик по миниатюре открывает оригинал изображения в отдельной вкладке.
      // Текстовая ссылка ниже по-прежнему скачивает файл обычным способом.
      const previewUrl=new URL(a.download_url,window.location.href);previewUrl.searchParams.set('view','1');
      preview.href=previewUrl.href;preview.target='_blank';preview.rel='noopener noreferrer';
      preview.title='Открыть изображение в новой вкладке: '+a.original_name;preview.setAttribute('aria-label','Открыть изображение в новой вкладке: '+a.original_name);
      window.ChatTransport.bindDownloadLink(preview,preview.href,a.original_name);
      const image=document.createElement('img');image.className='attachment-thumbnail';window.ChatTransport.setImageSource(image,a.thumbnail_url);image.alt=a.original_name;image.loading='lazy';image.decoding='async';image.draggable=false;
      if(Number(a.thumbnail_width)>0)image.width=Number(a.thumbnail_width);if(Number(a.thumbnail_height)>0)image.height=Number(a.thumbnail_height);
      preview.append(image);wrap.append(preview);
    }
    const link=document.createElement('a');link.className='attachment-link';link.href=a.download_url;link.textContent='[Скачать: '+a.original_name+' · '+formatBytes(a.size_bytes)+']';link.setAttribute('download','');window.ChatTransport.bindDownloadLink(link,link.href,a.original_name);wrap.append(link);
  });row.append(wrap);}
  return row;
}
function formatBytes(n){if(n<1024)return n+' Б';if(n<1024*1024)return (n/1024).toFixed(1)+' КБ';return (n/1024/1024).toFixed(1)+' МБ';}
function renderMessages(messages,replace=false,prepend=false){
  if(replace)timeline.replaceChildren();else {const loading=timeline.querySelector('.loading');if(loading)loading.remove();}
  const frag=document.createDocumentFragment();(messages||[]).forEach(m=>frag.append(renderMessage(m)));if(prepend)timeline.insertBefore(frag,timeline.firstChild);else timeline.append(frag);
  const els=[...timeline.querySelectorAll('.message')];if(els.length){oldestId=Number(els[0].dataset.id);newestId=Number(els[els.length-1].dataset.id);}if(replace)scrollBottom();
}
function showModes(){
  const inJournal=mode==='journal';
  const inAdminSection=mode==='admin';
  const inHistory=mode==='chat'&&!!currentDate;
  const back=$('back-chat');
  if(back){back.classList.toggle('hidden',!(inJournal||inAdminSection||inHistory)||isMobileLayout());back.textContent='Вернуться в чат';}
  $('all-journal').classList.toggle('hidden',!(inJournal&&journalUserId));
  $('date-picker-label').classList.toggle('hidden',inAdminSection);
  $('search-form').classList.remove('hidden');
  $('date-picker-caption').textContent=inJournal?'Дата журнала':'История';
  $('pinned-block').classList.toggle('hidden',mode!=='chat'||currentDate!==null);
  if(inJournal){setModeTitle('Журнал Админа');}
  else if(inAdminSection){setModeTitle(adminPageTitle());}
  else{setModeTitle(currentDate?'История':'Чат');}
  // Keep the composer visible in all viewing modes; only RO/BAN hide it.
  updateComposerAccess();
  syncTopSearchContext({clearOnChange:true});
}
function loadPinned(){if(mode!=='chat'||currentDate)return;getJson(base+'/api/pinned.php').then(d=>{if(!d.ok)return;const list=$('pinned-list');list.replaceChildren();for(const p of d.pinned||[]){const b=document.createElement('button');b.type='button';b.className='pinned-row';if(p.notify_me&&currentBan!=='BAN')b.classList.add('message-for-me');b._pinnedPrefix='['+String(p.created_at_display||'')+'], '+String(p.author_nick||'')+', ';b._pinnedPreview=(String(p.preview||'').trim()||'[Пустое сообщение]');b.append(document.createTextNode(b._pinnedPrefix));appendTextWithSmiles(b,b._pinnedPreview,false);b.title='Перейти к сообщению '+p.created_at_display+' · '+p.author_nick;b.addEventListener('click',()=>jumpToMessage(p.id));list.append(b);} $('pinned-block').classList.toggle('hidden',!(d.pinned||[]).length);}).catch(()=>{});}
$('pinned-search-toggle')?.addEventListener('click',()=>{if(mode==='journal')return;if(searchState?.pinnedOnly){restoreSearchView({returnToLiveChat:true,focusComposer:true});displaySystem('Возвращено отображение чата.','success');return;}if(searchInput)searchInput.value='';pinnedOnlySearch=true;updateSearchClearButton();executeSearch(false);});
function renderUsers(users,preserveAllUsers=false){
  if(!preserveAllUsers)allUsers=users||[];
  const shownUsers=users||[];userList.replaceChildren();let lastGroup='';
  if(preserveAllUsers&&!shownUsers.length){const empty=document.createElement('div');empty.className='user-list-empty';empty.textContent=allUsers.length?'Совпадений не найдено.':'Список пользователей пуст.';userList.append(empty);return;}
  for(const u of shownUsers){
    const group=u.ban_mode==='BAN'?'Полный бан':u.ban_mode==='RO'?'Только чтение':u.online?'Онлайн':'Остальные';
    if(group!==lastGroup){const h=document.createElement('div');h.className='user-group-title';h.textContent=group;userList.append(h);lastGroup=group;}
    const row=document.createElement('div');row.className='user-row';
    const btn=document.createElement('button');btn.type='button';btn.className='user-name '+(u.online?'online':'offline');btn.textContent=u.nick;btn.title=u.online?'В сети':'Последняя активность: '+u.last_seen_relative;
    btn.addEventListener('click',()=>{if(mode==='journal'){journalUserId=Number(u.id);loadJournal(true);if(isMobileLayout())closeMobileMenu();}else if(Number(u.id)!==userId){insertMentionToken(Number(u.id),u.nick);if(isMobileLayout())closeMobileMenu();focusComposerAtRange();}});row.append(btn);
    if(u.ban_mode==='none'&&!u.online){const tm=document.createElement('span');tm.className='user-time';tm.textContent=u.last_seen_relative;row.append(tm);}else if(u.ban_mode!=='none'){const st=document.createElement('span');st.className='user-status';st.textContent=u.ban_mode==='RO'?'RO':'BAN';row.append(st);}
    if(isAdmin && u.ban_mode!=='none'){const meta=document.createElement('span');meta.className='user-admin-meta';meta.textContent=(u.banned_at_display||'')+' · '+(u.banned_by||'');row.append(meta);}
    if(isAdmin){
      const acts=document.createElement('span');acts.className='user-admin-actions';
      if(u.is_admin_account){const tag=document.createElement('span');tag.className='admin-tag';tag.textContent='[ADMIN]';tag.title='Права администратора задаются в settings.cfg';acts.append(tag);}
      else {
        if(u.ban_mode==='none'){
          const ro=document.createElement('button');ro.textContent='RO';ro.title='Только чтение';ro.addEventListener('click',()=>banUser(u.id,'RO'));acts.append(ro);
          const ban=document.createElement('button');ban.textContent='BAN';ban.title='Полный бан';ban.addEventListener('click',()=>banUser(u.id,'BAN'));acts.append(ban);
        } else if(u.ban_mode==='RO') {
          const unro=document.createElement('button');unro.textContent='UNRO';unro.title='Снять режим только чтение';unro.addEventListener('click',()=>unroUser(u.id));acts.append(unro);
          const ban=document.createElement('button');ban.textContent='BAN';ban.title='Заменить RO полным баном';ban.addEventListener('click',()=>banUser(u.id,'BAN'));acts.append(ban);
        } else {
          const un=document.createElement('button');un.textContent='UNBAN';un.title='Снять полный бан';un.addEventListener('click',()=>unbanUser(u.id));acts.append(un);
        }
        const delUser=document.createElement('button');delUser.textContent='[DEL]';delUser.title='Удалить учётную запись';delUser.addEventListener('click',()=>openDeleteUserDialog(u));acts.append(delUser);
      }
      row.append(acts);
    }
    userList.append(row);
  }
}

function applyUsersFilter(query=''){
  const q=String(query||'').trim().toLocaleLowerCase();
  const filtered=!q?allUsers:allUsers.filter(u=>String(u.nick||'').toLocaleLowerCase().includes(q));
  renderUsers(filtered,true);
}
async function loadUsers(){try{const d=await getJson(base+'/api/users.php');if(d.ok){currentBan=d.current_ban_mode||'none';currentNotificationMode=d.notification_mode||'MINE';const select=$('notification-mode');if(select&&select.value!==currentNotificationMode)select.value=currentNotificationMode;updateComposerAccess();renderUsers(d.users||[]);if(getTopSearchContext()==='users')applyUsersFilter(searchInput?.value||'');}}catch{}}
function insertNodeAtComposer(node){if(!composer||currentBan!=='none')return;let r=composerRange;if(!r||!composer.contains(r.startContainer)||!composer.contains(r.endContainer)){r=document.createRange();r.selectNodeContents(composer);r.collapse(false);}try{composer.focus({preventScroll:true});}catch{composer.focus();}r.deleteContents();r.insertNode(node);const spacer=document.createTextNode(' ');node.after(spacer);r.setStartAfter(spacer);r.collapse(true);const sel=window.getSelection();if(sel){sel.removeAllRanges();sel.addRange(r);}composerRange=r.cloneRange();}
function insertMentionToken(uid,nick){if(currentBan!=='none')return;const span=document.createElement('span');span.className='compose-mention';span.contentEditable='false';span.dataset.type='mention';span.dataset.userId=String(uid);span.dataset.nick=nick;span.textContent='@'+nick;insertNodeAtComposer(span);}
function removeQuoteToken(span){
  if(!composer||!span||!composer.contains(span))return;
  const before=span.previousSibling,next=span.nextSibling;
  const hasLeadingBreak=before?.nodeType===Node.TEXT_NODE&&/\n$/.test(before.nodeValue||'');
  const hasTrailingBreak=next?.nodeType===Node.TEXT_NODE&&/^\n/.test(next.nodeValue||'');
  const previousBefore=hasLeadingBreak?before.previousSibling:before;
  const afterTarget=hasTrailingBreak&&!(next.nodeValue||'').slice(1)?next.nextSibling:next;
  let afterContent=false;
  if(next?.nodeType===Node.TEXT_NODE&&hasTrailingBreak&&(next.nodeValue||'').length>1)afterContent=true;
  for(let n=afterTarget;n&&!afterContent;n=n.nextSibling){
    if(String(n.textContent||'').length)afterContent=true;
  }
  // If there is no content after the quote, remove its preceding line break too.
  // Otherwise keep the preceding break and remove only the quote's trailing break.
  if(hasLeadingBreak&&!afterContent)before.nodeValue=(before.nodeValue||'').slice(0,-1);
  if(hasTrailingBreak)next.nodeValue=(next.nodeValue||'').slice(1);
  span.remove();
  [before,next].forEach(n=>{if(n?.parentNode===composer&&n.nodeType===Node.TEXT_NODE&&n.nodeValue==='')n.remove();});
  try{composer.focus({preventScroll:true});}catch{composer.focus();}
  const range=document.createRange();
  if(before?.parentNode===composer){
    if(before.nodeType===Node.TEXT_NODE)range.setStart(before,(before.nodeValue||'').length);
    else range.setStartAfter(before);
  }else if(afterContent&&afterTarget?.parentNode===composer){
    if(afterTarget.nodeType===Node.TEXT_NODE)range.setStart(afterTarget,0);
    else range.setStartBefore(afterTarget);
  }else if(previousBefore?.parentNode===composer){
    if(previousBefore.nodeType===Node.TEXT_NODE)range.setStart(previousBefore,(previousBefore.nodeValue||'').length);
    else range.setStartAfter(previousBefore);
  }else{range.selectNodeContents(composer);range.collapse(false);}
  range.collapse(true);
  const selection=window.getSelection();
  if(selection){selection.removeAllRanges();selection.addRange(range);}
  composerRange=range.cloneRange();
}
function insertQuoteToken(id,selectedText,sourceTimestamp='',sourceNick='',fallbackText=''){
  if(currentBan!=='none')return;
  const span=document.createElement('span');span.className='compose-quote';span.contentEditable='false';span.dataset.type='quote';span.dataset.messageId=String(id);if(selectedText)span.dataset.selectedText=selectedText;
  const excerpt=truncate(selectedText||fallbackText||'[сообщение без текста]',Number(app.dataset.quoteMaxChars||80));span.textContent='|['+(sourceTimestamp||'—')+'], '+(sourceNick||'Пользователь')+' писал: '+excerpt;
  const remove=document.createElement('button');remove.type='button';remove.className='compose-quote-remove';remove.textContent='×';remove.title='Удалить цитату';remove.setAttribute('aria-label','Удалить цитату');
  remove.addEventListener('pointerdown',e=>{if(isMobileLayout())e.preventDefault();});
  remove.addEventListener('mousedown',preserveComposerFocusOnMouseDown);
  span.append(remove);
  insertNodeAtComposer(span);
  // Не добавляем пустую первую строку, если цитата вставлена в пустой редактор.
  let hasContentBefore=false;for(let n=composer.firstChild;n&&n!==span;n=n.nextSibling){if(String(n.textContent||'').length){hasContentBefore=true;break;}}
  if(hasContentBefore){const prev=span.previousSibling;const endsWithNewline=prev?.nodeType===Node.TEXT_NODE&&/\n$/.test(prev.nodeValue||'');if(!endsWithNewline)span.before(document.createTextNode('\n'));}
  const spacer=span.nextSibling;if(spacer?.nodeType===Node.TEXT_NODE)spacer.nodeValue='\n';else span.after(document.createTextNode('\n'));
  rememberComposerRange();
}
function truncate(s,n){s=String(s||'').replace(/\s+/g,' ').trim();return s.length<=n?s:s.slice(0,Math.max(1,n-1))+'…';}
function nodeToSegments(node,out){if(node.nodeType===Node.TEXT_NODE){if(node.nodeValue)out.push({type:'text',text:node.nodeValue});return;}if(node.nodeType!==Node.ELEMENT_NODE)return;const el=node;if(el.dataset?.type==='quote'){out.push({type:'quote',message_id:Number(el.dataset.messageId),selected_text:el.dataset.selectedText||''});return;}if(el.dataset?.type==='mention'){out.push({type:'mention',user_id:Number(el.dataset.userId)});return;}if(el.tagName==='BR'){out.push({type:'text',text:'\n'});return;}for(const child of el.childNodes)nodeToSegments(child,out);if(['DIV','P'].includes(el.tagName)&&el.nextSibling)out.push({type:'text',text:'\n'});}
function composerSegments(){const out=[];for(const child of composer.childNodes)nodeToSegments(child,out);const merged=[];for(const s of out){if(s.type==='text'&&merged.length&&merged[merged.length-1].type==='text')merged[merged.length-1].text+=s.text;else merged.push(s);}return merged;}
function renderPendingAttachments(){
  const list=$('attach-names');if(!list)return;list.replaceChildren();
  pendingAttachments.forEach((file,index)=>{
    const item=document.createElement('span');item.className='attachment-pending-item';
    const label=document.createElement('span');label.textContent=file.name+' ('+formatBytes(file.size)+')';
    const remove=document.createElement('button');remove.type='button';remove.className='attachment-remove';remove.textContent='×';remove.title='Убрать вложение';remove.setAttribute('aria-label','Убрать вложение '+file.name);
    remove.addEventListener('click',()=>{pendingAttachments.splice(index,1);renderPendingAttachments();});
    item.append(label,remove);list.append(item);
  });
  list.classList.toggle('hidden',pendingAttachments.length===0);
}
const UPLOAD_CHUNK_FALLBACK_BYTES = 128 * 1024;

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  // Не передаём большой массив через один spread: это может превысить лимит аргументов JS.
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + 0x8000, bytes.length)));
  }
  return btoa(binary);
}

async function postUploadAction(action, fields = {}, retryNetwork = false) {
  const fd = new FormData();
  fd.set('action', action);
  for (const [key, value] of Object.entries(fields)) fd.set(key, String(value));

  let response;
  const attempts = retryNetwork ? 2 : 1;
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      response = await postForm(base + '/api/upload-chunk.php', fd);
      break;
    } catch (error) {
      if (attempt + 1 >= attempts) throw error;
      await new Promise(resolve => window.setTimeout(resolve, 300));
    }
  }
  if (!response || response.ok !== true) {
    throw new Error(response?.error || 'Сервер отклонил загрузку вложения.');
  }
  return response;
}

function createUploadId() {
  if (!window.crypto?.getRandomValues) throw new Error('Браузер не поддерживает безопасную генерацию идентификатора загрузки.');
  const bytes = window.crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
}

async function uploadAttachmentInChunks(file, fileIndex, fileCount, stagedUploadIds) {
  if (!(file instanceof File) || !Number.isFinite(file.size) || file.size <= 0) {
    throw new Error('Пустой или недоступный файл: ' + (file?.name || 'без имени'));
  }

  // ID создаётся в браузере заранее, поэтому можно безопасно повторить даже запрос start,
  // если сервер успел создать временный файл, но ответ потерялся в сети.
  const uploadId = createUploadId();
  stagedUploadIds.push(uploadId);
  const started = await postUploadAction('start', {
    upload_id: uploadId,
    file_name: file.name,
    file_size: file.size
  }, true);

  const confirmedId = String(started.upload_id || '');
  if (confirmedId !== uploadId) throw new Error('Сервер вернул другой идентификатор загрузки для файла «' + file.name + '».');

  const chunkSize = Math.max(16 * 1024, Math.min(256 * 1024, Number(started.chunk_size) || UPLOAD_CHUNK_FALLBACK_BYTES));
  let lastReportedPercent = Math.floor(Number(started.next_offset || 0) * 100 / file.size) - 10;
  for (let offset = Math.max(0, Number(started.next_offset || 0)); offset < file.size; offset += chunkSize) {
    const end = Math.min(offset + chunkSize, file.size);
    const buffer = await file.slice(offset, end).arrayBuffer();
    const chunkBase64 = arrayBufferToBase64(buffer);
    const chunkResponse = await postUploadAction('chunk', {
      upload_id: uploadId,
      offset,
      chunk_data: chunkBase64
    }, true);

    const expectedOffset = offset + buffer.byteLength;
    if (Number(chunkResponse.next_offset) !== expectedOffset) {
      throw new Error('Нарушен порядок блоков при загрузке файла «' + file.name + '».');
    }

    const percent = Math.floor(expectedOffset * 100 / file.size);
    if (percent === 100 || percent >= lastReportedPercent + 10) {
      lastReportedPercent = percent;
      const message = 'Загрузка вложения ' + (fileIndex + 1) + '/' + fileCount + ': ' + file.name + ' — ' + percent + '%';
      setComposerPlaceholder(message, 'success');
      displaySystem(message, 'info');
    }
  }
  return uploadId;
}

async function abortStagedUploads(uploadIds) {
  for (const uploadId of uploadIds) {
    try { await postUploadAction('abort', {upload_id: uploadId}); }
    catch { /* Временные файлы также удаляются серверной очисткой по сроку хранения. */ }
  }
}

function restoreDraftAfterSendFailure(draftNodes, submittedAttachments) {
  const newNodes = Array.from(composer.childNodes);
  const typedDuringSend = newNodes.length > 0 || pendingAttachments.length > 0;
  composer.replaceChildren();
  for (const node of draftNodes) composer.append(node);
  if (typedDuringSend && draftNodes.length && newNodes.length) composer.append(document.createTextNode('\n'));
  if (typedDuringSend) for (const node of newNodes) composer.append(node);
  pendingAttachments = [...submittedAttachments, ...pendingAttachments];
  renderPendingAttachments();
  focusComposerAtEnd();
}

async function sendMessage(){
  if(sendInFlight||Date.now()<sendCooldownUntil||currentBan!=='none')return;
  const segments=composerSegments();
  if(!segments.length&&!pendingAttachments.length)return;

  // Запоминаем режим отправки: при успехе уйдём в актуальный чат, при ошибке останемся здесь.
  const sentFromNonLiveView=(mode==='journal'||mode==='admin'||!!currentDate||!!searchState||pinnedOnlySearch);
  const sendStartedAt=Date.now();
  const submittedAttachments=[...pendingAttachments];
  const draftNodes=Array.from(composer.childNodes).map(n=>n.cloneNode(true));
  const stagedUploadIds=[];

  sendInFlight=true;setComposerPlaceholder('Подготовка сообщения…','success');updateSendButtonState();
  // Сразу освобождаем поле ввода для следующего черновика. Сам отправляемый черновик сохранён в draftNodes.
  composer.replaceChildren();composerRange=null;pendingAttachments=[];$('attachments').value='';renderPendingAttachments();focusComposerAtEnd();

  try{
    const transport = window.ChatTransport;
    if (!transport) throw new Error('Не загружен модуль связи chat1.');

    // Протокол 2 загружает файлы блоками. Протокол 1 прикрепляет исходные File
    // непосредственно к multipart/form-data запросу создания сообщения.
    if (transport.supportsChunkedUploads) {
      for(let i=0;i<submittedAttachments.length;i++){
        await uploadAttachmentInChunks(submittedAttachments[i],i,submittedAttachments.length,stagedUploadIds);
      }
    }

    setComposerPlaceholder('Отправка сообщения…','success');
    const fd=new FormData();
    fd.append('segments',JSON.stringify(segments));
    if (transport.protocol === 1) {
      for (const file of submittedAttachments) fd.append('attachments[]', file, file.name);
    } else if (transport.protocol === 2) {
      fd.append('upload_ids',JSON.stringify(stagedUploadIds));
    } else {
      throw new Error('Неподдерживаемый протокол связи: ' + transport.protocol);
    }
    const d=await postForm(base+'/api/send.php',fd);

    if(d.ok){
      // send.php удаляет временные файлы только после успешного коммита БД.
      stagedUploadIds.length=0;
      const intervalMs=Math.max(1,Number(app.dataset.messageIntervalSeconds)||5)*1000;
      setComposerCooldown(Math.max(0,intervalMs-(Date.now()-sendStartedAt)));
      if(d.cleanup_needed)scheduleStorageCleanupStep(Number(d.message_id)||0,0);
      // Если отправка началась из истории, поиска или журнала — после успеха возвращаем актуальную ленту.
      if(sentFromNonLiveView||mode==='journal'||mode==='admin'||currentDate||searchState||pinnedOnlySearch){
        clearSearchState(true);mode='chat';adminPage=null;currentDate=null;journalUserId=0;$('history-date').value='';showModes();
        await loadInitial();schedulePolling();focusComposerAtEnd();
      }else{void loadLatest();focusComposerAtEnd();}
    }
    else{
      if(d.cleanup_needed)scheduleStorageCleanupStep(0,0);
      await abortStagedUploads(stagedUploadIds);
      stagedUploadIds.length=0;
      restoreDraftAfterSendFailure(draftNodes,submittedAttachments);
      const waitMs=antifloodWaitMs(d.error);if(waitMs)setComposerCooldown(waitMs,'Подождите…');else resetComposerPlaceholder();
      displaySystem(d.error||'Не удалось отправить сообщение.','error');
    }
  }catch(error){
    await abortStagedUploads(stagedUploadIds);
    stagedUploadIds.length=0;
    restoreDraftAfterSendFailure(draftNodes,submittedAttachments);
    resetComposerPlaceholder();
    displaySystem(error?.message||'Не удалось связаться с сервером при загрузке вложения или отправке сообщения.','error');
  }finally{sendInFlight=false;updateSendButtonState();}
}
sendButton?.addEventListener('click',sendMessage);composer?.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.ctrlKey){e.preventDefault();sendMessage();}});
composer?.addEventListener('click',e=>{const button=e.target?.closest?.('.compose-quote-remove');if(!button||!composer.contains(button))return;e.preventDefault();e.stopPropagation();removeQuoteToken(button.closest('.compose-quote'));});
$('attach-button')?.addEventListener('click',()=>$('attachments').click());$('attachments')?.addEventListener('change',e=>{const added=Array.from(e.target.files||[]);if(added.length)pendingAttachments.push(...added);e.target.value='';renderPendingAttachments();});
function nodeMessageBody(node){const el=node?.nodeType===Node.ELEMENT_NODE?node:node?.parentElement;return el?.closest?.('.message-body')||null;}
function isNodeWithin(node,container){return !!node&&(node===container||container.contains(node));}
function hideQuoteSelection(){const button=$('quote-selection');if(button)button.classList.add('hidden');selectedQuote=null;quoteDragBody=null;}
// Build the selected source text from rendered content. Smile codes are stored as text,
// but displayed as <img> elements, so Range.toString() alone loses them.
function quoteTextFromRange(range){
  const fragment=range.cloneContents();let result='';
  const walk=node=>{
    if(node.nodeType===Node.TEXT_NODE){result+=node.nodeValue||'';return;}
    if(node.nodeType!==Node.ELEMENT_NODE&&node.nodeType!==Node.DOCUMENT_FRAGMENT_NODE)return;
    if(node.nodeType===Node.ELEMENT_NODE){
      const el=node;
      if(el.tagName==='IMG'&&el.classList.contains('chat-smile')){result+=el.getAttribute('alt')||'';return;}
      if(el.tagName==='BR'){result+='\n';return;}
    }
    for(const child of node.childNodes)walk(child);
  };
  walk(fragment);
  return result.replace(/\r\n/g,'\n').trim();
}
function selectionForQuote(){
  const sel=window.getSelection();
  if(!sel||sel.isCollapsed||!sel.rangeCount){hideQuoteSelection();return;}
  // The user must begin the drag inside one message body. We never expand the selection to a neighbouring row.
  const body=quoteDragBody||nodeMessageBody(sel.anchorNode);
  if(!body||!isNodeWithin(sel.anchorNode,body)){hideQuoteSelection();return;}
  const range=sel.getRangeAt(0).cloneRange();
  const bodyRange=document.createRange();bodyRange.selectNodeContents(body);
  try{
    if(range.compareBoundaryPoints(Range.START_TO_START,bodyRange)<0)range.setStart(bodyRange.startContainer,bodyRange.startOffset);
    if(range.compareBoundaryPoints(Range.END_TO_END,bodyRange)>0)range.setEnd(bodyRange.endContainer,bodyRange.endOffset);
  }catch{hideQuoteSelection();return;}
  if(range.collapsed||!isNodeWithin(range.startContainer,body)||!isNodeWithin(range.endContainer,body)){hideQuoteSelection();return;}
  // Replace the browser selection with its clipped range, so visually selected text never crosses message boundaries.
  sel.removeAllRanges();sel.addRange(range);
  const text=quoteTextFromRange(range);
  const row=body.closest('.message');
  if(!text||!row||!row.dataset.id){hideQuoteSelection();return;}
  selectedQuote={id:Number(row.dataset.id),text,createdAt:row.dataset.quoteTimestamp||'',nick:row.dataset.authorNick||'',fallbackText:body.textContent||''};
  $('quote-selection').classList.remove('hidden');quoteDragBody=null;
}
timeline?.addEventListener('mousedown',e=>{quoteDragBody=e.target?.closest?.('.message-body')||null;});
document.addEventListener('mouseup',selectionForQuote);document.addEventListener('keyup',selectionForQuote);
$('quote-selection')?.addEventListener('mousedown',e=>e.preventDefault());$('quote-selection')?.addEventListener('click',()=>{if(selectedQuote){insertQuoteToken(selectedQuote.id,selectedQuote.text,selectedQuote.createdAt,selectedQuote.nick,selectedQuote.fallbackText);focusComposerAtRange();selectedQuote=null;$('quote-selection').classList.add('hidden');}});
async function loadLatest(){
  if(loadingLatest||!notificationCursorReady)return;
  loadingLatest=true;const requestedAfter=notificationCursorId;
  try{
    const d=await getJson(base+'/api/messages.php?after_id='+requestedAfter);
    if(!d.ok)return;
    const fresh=(d.messages||[]).filter(m=>Number(m.id)>notificationCursorId).sort((a,b)=>Number(a.id)-Number(b.id));
    if(!fresh.length)return;
    notificationCursorId=Math.max(notificationCursorId,...fresh.map(m=>Number(m.id)));
    notifyForMessages(fresh);
    // Не меняем открытую историю или результаты поиска под пользователем.
    if(mode!=='chat')return;
    if(currentDate||searchState||pinnedOnlySearch)return;
    const visibleFresh=fresh.filter(m=>Number(m.id)>newestId);
    if(!visibleFresh.length)return;
    const wasNearBottom=timelineNearBottom();
    renderMessages(visibleFresh,false);
    if(wasNearBottom)scrollBottom();
  }catch{}finally{loadingLatest=false;}
}
function notifyForMessages(messages){
  if(currentBan==='BAN'||currentNotificationMode==='NONE')return;
  const incoming=messages.filter(m=>Number(m.user_id)!==userId&&!m.is_deleted);
  if(!incoming.length)return;
  const shouldNotify=currentNotificationMode==='ALL' ? true : currentNotificationMode==='MINE' ? incoming.some(m=>!!m.notify_me) : false;
  if(shouldNotify)window.chatPlaySound();
}
async function loadInitial(){clearSearchState(true);try{const d=await getJson(base+'/api/messages.php');if(d.ok){mode='chat';adminPage=null;currentDate=null;journalUserId=0;$('history-date').value='';showModes();renderMessages(d.messages||[],true);hasOlder=!!d.has_older;loadPinned();if(!notificationCursorReady){notificationCursorId=Math.max(0,...(d.messages||[]).map(m=>Number(m.id)));notificationCursorReady=true;}}}catch{timeline.textContent='Не удалось загрузить историю.';}}
async function loadOlder(){if(mode==='admin'||searchState||loadingOlder||!hasOlder)return;loadingOlder=true;const oldHeight=timeline.scrollHeight;const oldTop=timeline.scrollTop;try{let url;if(mode==='journal'){const qs=['before_id='+oldestId];if(currentDate)qs.push('date='+encodeURIComponent(currentDate));if(journalUserId)qs.push('user_id='+journalUserId);url=base+'/api/journal.php?'+qs.join('&');}else{const date=currentDate?('&date='+encodeURIComponent(currentDate)):'';url=base+'/api/messages.php?before_id='+oldestId+date;}const d=await getJson(url);if(d.ok){const items=mode==='journal'?(d.entries||[]):(d.messages||[]);if(mode==='journal')renderJournal(items,false,true);else renderMessages(items,false,true);hasOlder=!!d.has_older;timeline.scrollTop=oldTop+(timeline.scrollHeight-oldHeight);}}catch{}finally{loadingOlder=false;}}
async function loadDate(date,targetId=0){if(!date||!/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(date))return;clearSearchState(true);currentDate=date;mode='chat';adminPage=null;journalUserId=0;$('history-date').value=date;showModes();const qs=targetId?'target_id='+targetId:'date='+encodeURIComponent(date);try{const d=await getJson(base+'/api/messages.php?'+qs);if(d.ok){renderMessages(d.messages||[],true);hasOlder=!!d.has_older;if(targetId){const target=timeline.querySelector('[data-id="'+targetId+'"]');target?.scrollIntoView({block:'center'});}}}catch{displaySystem('Не удалось загрузить историю за выбранную дату.');}}
$('history-date')?.addEventListener('change',e=>{closeMobileMenu();const date=e.target.value;if(!date){if(mode==='journal'){currentDate=null;loadJournal(true);}else{currentDate=null;loadInitial();schedulePolling();}return;}if(mode==='journal')loadJournal(true,date);else loadDate(date);});timeline?.addEventListener('scroll',()=>{if(mode==='admin')return;if(searchState?.active){const inputMatches=(searchState.pinnedOnly&&!(searchInput?.value||'').trim())||((searchInput?.value||'').trim()===searchState.query);if(inputMatches&&timeline.scrollHeight-timeline.scrollTop-timeline.clientHeight<120&&searchState.hasMore)loadMoreSearchResults();return;}if(timeline.scrollTop<25&&hasOlder)loadOlder();});
$('back-chat')?.addEventListener('click',async()=>{await returnToLiveChat();});
$('mobile-menu-toggle')?.addEventListener('click',()=>{
  if(document.body.classList.contains('mobile-menu-open')){
    closeMobileMenu();
    // The same ellipsis has one predictable meaning inside the menu: return to the live chat.
    void returnToLiveChat({focusComposer:false});
  }else openMobileMenu();
});
$('mobile-smiles-button')?.addEventListener('pointerdown',preserveComposerFocusOnMouseDown);
$('mobile-smiles-button')?.addEventListener('mousedown',preserveComposerFocusOnMouseDown);
$('mobile-smiles-close')?.addEventListener('pointerdown',preserveComposerFocusOnMouseDown);
$('mobile-smiles-close')?.addEventListener('mousedown',preserveComposerFocusOnMouseDown);
$('send-button')?.addEventListener('pointerdown',preserveComposerFocusOnMouseDown);
$('send-button')?.addEventListener('mousedown',preserveComposerFocusOnMouseDown);
$('mobile-smiles-button')?.addEventListener('click',()=>{const panel=$('mobile-smiles-popover');const wasOpen=!!panel&&!panel.classList.contains('hidden');openMobileSmilesPopover();if(wasOpen)requestAnimationFrame(()=>focusComposerAtRange());});
$('mobile-smiles-close')?.addEventListener('click',()=>{closeMobileSmilesPopover();requestAnimationFrame(()=>focusComposerAtRange());});
$('settings-accordion-toggle')?.addEventListener('click',()=>toggleSidebarPane('settings'));

$('users-accordion-toggle')?.addEventListener('click',()=>toggleSidebarPane('users'));
$('smiles-accordion-toggle')?.addEventListener('click',()=>toggleSidebarPane('smiles'));
$('admin-accordion-toggle')?.addEventListener('click',()=>toggleSidebarPane('admin'));
$('admin-menu-storage')?.addEventListener('click',()=>{closeMobileMenu();openAdminSection('storage');});
$('admin-menu-blocked-nicks')?.addEventListener('click',()=>{closeMobileMenu();openAdminSection('blocked_nicks');});
$('admin-menu-blocked-emails')?.addEventListener('click',()=>{closeMobileMenu();openAdminSection('blocked_emails');});
$('admin-menu-journal')?.addEventListener('click',async()=>{if(!isAdmin)return;closeMobileMenu();clearSearchState(true);adminPage=null;mode='journal';journalUserId=0;currentDate=null;$('history-date').value='';setSidebarPane('admin');showModes();await loadJournal(true);focusComposerAtEnd();});
$('all-journal')?.addEventListener('click',()=>{journalUserId=0;loadJournal(true);});
function journalEventLabel(key){const m={registration_validation_failed:'Некорректные данные регистрации',registration_requested:'Запрошена регистрация',registration_confirmed:'Регистрация подтверждена',registration_mail_failed:'Ошибка отправки письма регистрации',registration_resend_requested:'Повторное письмо регистрации отправлено',registration_resend_mail_failed:'Ошибка повторной отправки регистрации',registration_resend_request_received:'Запрос повторного подтверждения получен',registration_resend_rate_limited:'Ограничена повторная отправка письма',registration_rate_limited:'Превышен лимит регистраций',registration_blocked_email:'Регистрация отклонена: email заблокирован',registration_blocked_nick:'Регистрация отклонена: ник заблокирован',registration_failed_email_exists:'Регистрация отклонена: email уже используется',registration_failed_nick_exists:'Регистрация отклонена: ник уже используется',registration_insert_conflict:'Конфликт при сохранении регистрации',registration_error:'Ошибка регистрации',nick_change_blocked_nick:'Смена ника отклонена: ник заблокирован',nick_change_failed_nick_exists:'Смена ника отклонена: ник занят',registration_confirmation_failed:'Неудачное подтверждение регистрации',registration_confirmation_error:'Ошибка подтверждения регистрации',login_success:'Успешный вход',login_failed:'Неудачная попытка входа',login_rate_limited:'Вход ограничен: слишком много попыток',login_validation_failed:'Некорректный запрос входа',logout:'Выход',password_reset_requested:'Запрошено восстановление пароля',password_reset_confirmed:'Пароль подтверждён',password_reset_mail_failed:'Ошибка отправки пароля',password_reset_request_received:'Запрос восстановления получен',password_reset_rate_limited:'Повторный запрос восстановления ограничен',password_reset_validation_failed:'Некорректный email для восстановления пароля',password_reset_confirmation_failed:'Ошибка подтверждения нового пароля',nick_change_validation_failed:'Некорректный запрос смены ника',nick_change_rate_limited:'Слишком частые запросы смены ника',admin_access_denied:'Отказано в доступе к администрированию',nick_change_requested:'Запрошена смена ника',nick_change_mail_failed:'Ошибка отправки подтверждения смены ника',nick_changed:'Ник изменён',user_ro_set:'Включён режим RO',user_ro_removed:'Режим RO снят',user_ban_set:'Установлен BAN',user_unbanned:'Блокировка снята',user_deleted:'Пользователь удалён',message_deleted:'Сообщение удалено',message_pinned:'Сообщение закреплено',message_unpinned:'Закрепление снято',notification_mode_changed:'Изменён режим уведомлений',php_notice:'Уведомление PHP',php_warning:'Предупреждение PHP',php_deprecation:'Устаревшая функция PHP',php_fatal_error:'Критическая ошибка PHP',php_uncaught_exception:'Необработанное исключение PHP',csrf_rejected:'Отклонён запрос CSRF-защитой',http_method_rejected:'Отклонён HTTP-метод',admin_action_error:'Ошибка административного действия',protected_admin_action_rejected:'Попытка изменить защищённую учётную запись администратора',message_send_error:'Ошибка отправки сообщения',messages_api_error:'Ошибка загрузки сообщений',api_error:'Ошибка API',confirmation_failed:'Не удалось подтвердить действие',confirmation_apply_error:'Ошибка применения подтверждённого действия'};return m[key]||key;}
function journalDetailsText(details){if(!details||typeof details!=='object')return '';const labels={unknown_email:'email не найден',account_not_confirmed:'регистрация не подтверждена',account_deleted_or_unavailable:'учётная запись удалена или недоступна',wrong_password:'неверный пароль',too_many_attempts:'превышен лимит попыток',missing_or_invalid_credentials:'не указаны или некорректны данные входа',registrations_per_ip_per_hour:'превышен лимит регистраций с IP',email_blocked_by_admin:'email заблокирован администратором',nick_blocked_by_admin:'ник заблокирован администратором',email_exists:'email уже используется',nick_exists:'ник уже используется',invalid_email:'неверный формат email',invalid_nick:'недопустимый ник',token_not_found:'ссылка не найдена или неверна',invalid_token_format:'неверный формат ссылки',account_not_active:'учётная запись не активна',invalid_confirmation_payload:'содержимое ссылки подтверждения повреждено',password_payload_missing:'нет данных нового пароля',token_already_used:'ссылка уже использована',token_expired:'срок действия ссылки истёк',blocked_before_confirmation:'ник заблокирован до подтверждения',email_blocked_before_confirmation:'email заблокирован до подтверждения',nick_blocked_before_confirmation:'ник заблокирован до подтверждения',nick_exists_at_confirmation:'ник успели занять до подтверждения',account_not_pending:'учётная запись уже не ожидает подтверждения'};const fieldLabels={reason:'Причина',result:'Результат',field:'Поле',message:'Сообщение PHP',exception:'Исключение',file:'Файл',line:'Строка',severity:'Код ошибки',action:'Действие',endpoint:'Обработчик',path:'Путь',method:'Метод',expected:'Ожидался метод',actual:'Получен метод',mode:'Режим',nick:'Ник',old_nick:'Старый ник',new_nick:'Новый ник',account_status:'Статус учётной записи',limit:'Лимит',window_minutes:'Окно ограничения, минут',email_blocked:'Email заблокирован',nick_blocked:'Ник заблокирован',target_nick:'Ник пользователя',attempted_action:'Попытка действия',message_author_nick:'Ник автора сообщения',email_blocked_before_confirmation:'Email заблокирован до подтверждения',nick_blocked_before_confirmation:'Ник заблокирован до подтверждения',result_code:'Код результата'};const out=[];const sensitive=/(password|pass_hash|token|secret|csrf|authorization)/i;for(const [key,value] of Object.entries(details)){if(sensitive.test(key))continue;if(value===null||value===undefined||value==='')continue;let shown=String(value);if(key==='reason')shown=labels[shown]||shown;const label=fieldLabels[key]||key;out.push(label+': '+shown);}return out.join(' · ');}

async function loadJournal(replace=true,date=null){if(!isAdmin)return;clearSearchState(true);mode='journal';adminPage=null;if(date!==null){currentDate=date;$('history-date').value=date;}showModes();const qs=[];if(currentDate)qs.push('date='+encodeURIComponent(currentDate));if(journalUserId)qs.push('user_id='+journalUserId);try{const d=await getJson(base+'/api/journal.php?'+qs.join('&'));if(!d.ok){displaySystem(d.error||'Не удалось загрузить журнал.');return;}renderJournal(d.entries||[],replace);hasOlder=!!d.has_older;lastJournalId=d.last_id||0;}catch{displaySystem('Не удалось загрузить журнал.');}}
async function restoreSearchView({returnToLiveChat=false,focusComposer=false}={}){const previousMode=mode;const previousDate=currentDate;const previousJournalUserId=journalUserId;clearSearchState(true);if(previousMode==='journal'){mode='journal';currentDate=previousDate;journalUserId=previousJournalUserId;showModes();await loadJournal(true,previousDate);}else if(returnToLiveChat||!previousDate){mode='chat';currentDate=null;journalUserId=0;$('history-date').value='';showModes();if(focusComposer)focusComposerAtEnd();await loadInitial();schedulePolling();}else{mode='chat';currentDate=previousDate;journalUserId=0;$('history-date').value=previousDate;showModes();await loadDate(previousDate);}updateSearchClearButton();if(focusComposer)focusComposerAtEnd();}
function renderJournal(entries,replace,prepend=false){if(replace)timeline.replaceChildren();const frag=document.createDocumentFragment();for(const e of entries){const row=document.createElement('div');row.className='journal-entry';row.dataset.id=String(e.id);const head=document.createElement('div');head.className='journal-head';head.textContent='['+e.created_at_display+'] ';const strong=document.createElement('span');strong.className='journal-event';strong.textContent=journalEventLabel(e.event_key);head.append(strong);row.append(head);const detail=document.createElement('div');detail.className='journal-details';const fields=[];if(e.subject_nick)fields.push('Пользователь: '+e.subject_nick);if(e.subject_email)fields.push('Email: '+e.subject_email);if(e.actor_nick||e.actor_email)fields.push('Инициатор: '+(e.actor_nick||e.actor_email));if(e.message_id)fields.push('Сообщение ID: '+e.message_id);if(e.details&&Object.keys(e.details).length)fields.push(journalDetailsText(e.details));detail.textContent=fields.join(' · ');row.append(detail);if(e.ip_address||e.user_agent){const priv=document.createElement('div');priv.className='journal-private';priv.textContent='IP: '+(e.ip_address||'—')+' · User-Agent: '+(e.user_agent||'—');row.append(priv);}frag.append(row);}if(prepend)timeline.insertBefore(frag,timeline.firstChild);else timeline.append(frag);if(replace)scrollBottom();const els=[...timeline.querySelectorAll('.journal-entry')];if(els.length)oldestId=Number(els[0].dataset.id||entries[0]?.id||0);}
function escapeSearchRegex(value){return String(value||'').replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}
function appendSearchHighlight(parent,text,query){
  text=String(text||'');query=String(query||'');
  if(!query){parent.append(document.createTextNode(text));return;}
  let re;try{re=new RegExp('('+escapeSearchRegex(query)+')','giu');}catch{parent.append(document.createTextNode(text));return;}
  let last=0,m;while((m=re.exec(text))!==null){if(m.index>last)parent.append(document.createTextNode(text.slice(last,m.index)));const mark=document.createElement('mark');mark.className='search-match';mark.textContent=m[0];parent.append(mark);last=m.index+m[0].length;if(m[0].length===0)re.lastIndex++;}if(last<text.length)parent.append(document.createTextNode(text.slice(last)));
}
function journalSearchDetailText(e){
  const fields=[];
  if(e.subject_nick)fields.push('Пользователь: '+e.subject_nick);
  if(e.subject_email)fields.push('Email: '+e.subject_email);
  if(e.actor_nick||e.actor_email)fields.push('Инициатор: '+(e.actor_nick||e.actor_email));
  if(e.message_id)fields.push('Сообщение ID: '+e.message_id);
  if(e.details&&Object.keys(e.details).length)fields.push(journalDetailsText(e.details));
  return fields.join(' · ');
}
function renderMessageSearchResult(r,query){
  const row=document.createElement('article');row.className='search-result';if(r.notify_me&&currentBan!=='BAN'&&!r.is_deleted)row.classList.add('message-for-me');row.dataset.id=String(r.id);
  const title=document.createElement('button');title.type='button';title.className='search-result-title';
  appendSearchHighlight(title,'['+(r.created_at_quote_display||r.created_at_display)+'], '+r.author_nick+(r.is_pinned?' · [PIN]':''),query);
  title.addEventListener('click',()=>jumpToMessage(r.id));row.append(title);
  const snippet=document.createElement('div');snippet.className='search-result-snippet';appendSearchHighlight(snippet,r.snippet||'',query);row.append(snippet);
  return row;
}
function renderJournalSearchResult(r,query){
  const row=document.createElement('article');row.className='search-result search-journal-result';row.dataset.id=String(r.id);
  const title=document.createElement('button');title.type='button';title.className='search-result-title';appendSearchHighlight(title,'['+(r.created_at_full_display||r.created_at_display)+'] '+journalEventLabel(r.event_key),query);title.addEventListener('click',()=>jumpToJournalEntry(r.id));row.append(title);
  const details=document.createElement('div');details.className='search-result-snippet';appendSearchHighlight(details,journalSearchDetailText(r),query);row.append(details);
  if(r.ip_address||r.user_agent){const priv=document.createElement('div');priv.className='journal-private';appendSearchHighlight(priv,'IP: '+(r.ip_address||'—')+' · User-Agent: '+(r.user_agent||'—'),query);row.append(priv);}
  return row;
}
function updateSearchMoreButton(){const old=timeline.querySelector('.search-more-wrap');if(old)old.remove();if(!searchState?.hasMore)return;const wrap=document.createElement('div');wrap.className='search-more-wrap';const button=document.createElement('button');button.type='button';button.className='search-more-button';button.textContent='Показать ещё результаты';button.addEventListener('click',()=>loadMoreSearchResults());wrap.append(button);timeline.append(wrap);}
function renderSearchResults(d,append=false){
  let list=timeline.querySelector('.search-results-list');
  if(!append||!list){timeline.replaceChildren();list=document.createElement('div');list.className='search-results-list';list.id='search-results-list';timeline.append(list);}
  const results=Array.isArray(d.results)?d.results:[];
  if(!append&&results.length===0){const empty=document.createElement('div');empty.className='search-empty';empty.textContent=searchState.pinnedOnly?'Закреплённых сообщений не найдено.':'По запросу «'+searchState.query+'» ничего не найдено.';list.append(empty);}
  for(const result of results){list.append(searchState.scope==='journal'?renderJournalSearchResult(result,searchState.query):renderMessageSearchResult(result,searchState.query));}
  searchState.offset=Number(d.offset||0)+results.length;searchState.total=Number(d.total||0);searchState.hasMore=!!d.has_more;
  updateSearchMoreButton();
  if(searchState.pinnedOnly){displaySystem('Найдено закреплённых сообщений: '+searchState.total+'.',searchState.total?'success':'info');}
  else if(searchState.total===0){displaySystem('По запросу «'+searchState.query+'» ничего не найдено.','info');}
  else{displaySystem('Найдено '+searchState.total+' совпадений. Показано '+Math.min(searchState.offset,searchState.total)+'.','success');}
}
async function executeSearch(append=false){
  if(mode==='admin'){displaySystem('Поиск недоступен в разделе администрирования.','info');return;}
  if(append){if(!searchState?.active||!searchState.hasMore||searchState.loading)return;searchState.loading=true;}
  else{
    const query=(searchInput?.value||'').trim();const onlyPinned=pinnedOnlySearch&&mode!=='journal';
    if(!query&&!onlyPinned){displaySystem('Введите текст для поиска.','info');return;}
    if(mode==='journal'&&!isAdmin){displaySystem('Поиск по журналу доступен только администраторам.','error');return;}
    searchState={active:true,scope:mode==='journal'?'journal':'messages',query,pinnedOnly:onlyPinned,userId:mode==='journal'?journalUserId:0,offset:0,total:0,hasMore:false,loading:false};
    updateSearchClearButton();
    timeline.replaceChildren();const loading=document.createElement('div');loading.className='loading';loading.textContent='Поиск…';timeline.append(loading);
  }
  const state=searchState;if(!state)return;
  const serial=++searchRequestSerial;const params=new URLSearchParams({scope:state.scope,q:state.query,offset:String(state.offset)});
  if(state.pinnedOnly)params.set('pinned','1');if(state.scope==='journal'&&state.userId)params.set('user_id',String(state.userId));
  try{
    const d=await getJson(base+'/api/search.php?'+params.toString());
    if(serial!==searchRequestSerial||state!==searchState)return;
    if(!d.ok){if(!append)timeline.replaceChildren();displaySystem(d.error||'Не удалось выполнить поиск.','error');if(!append){const error=document.createElement('div');error.className='search-empty';error.textContent=d.error||'Не удалось выполнить поиск.';timeline.append(error);}state.hasMore=false;return;}
    renderSearchResults(d,append);
  }catch(e){if(state===searchState){state.hasMore=false;displaySystem('Не удалось выполнить поиск из-за ошибки соединения.','error');}}
  finally{if(state===searchState)state.loading=false;}
}
function loadMoreSearchResults(){if(!searchState?.active||!searchState.hasMore||searchState.loading)return;const entered=(searchInput?.value||'').trim();const inputMatches=(searchState.pinnedOnly&&!entered)||(entered===searchState.query);if(!inputMatches){displaySystem('Нажмите «Найти» или Enter, чтобы выполнить поиск по новому запросу.','info');return;}executeSearch(true);}
async function jumpToJournalEntry(id){
  id=Number(id);if(!id||!isAdmin)return;
  const params=new URLSearchParams({target_id:String(id)});if(journalUserId)params.set('user_id',String(journalUserId));
  try{
    const d=await getJson(base+'/api/journal.php?'+params.toString());
    if(!d.ok){displaySystem(d.error||'Не удалось открыть запись журнала.','error');return;}
    clearSearchState(true);mode='journal';currentDate=d.date||null;if(currentDate)$('history-date').value=currentDate;showModes();renderJournal(d.entries||[],true);hasOlder=!!d.has_older;
    const target=timeline.querySelector('.journal-entry[data-id="'+id+'"]');if(target){target.scrollIntoView({block:'center'});target.classList.add('jump-highlight');setTimeout(()=>target.classList.remove('jump-highlight'),1800);}displaySystem('Открыта запись журнала.','success');
  }catch{displaySystem('Не удалось открыть запись журнала.','error');}
}
function submitTopSearch(){
  const context=getTopSearchContext();
  if(context==='users'){applyUsersFilter(searchInput?.value||'');return;}
  if(context==='blocked_nicks'||context==='blocked_emails'){applyBlockedListFilter();return;}
  if(context==='messages'||context==='journal'){executeSearch(false);return;}
}
searchForm?.addEventListener('submit',e=>{e.preventDefault();submitTopSearch();});
searchInput?.addEventListener('input',()=>{
  const context=getTopSearchContext();updateSearchClearButton();
  if(context==='users'){applyUsersFilter(searchInput.value);return;}
  if(context==='blocked_nicks'||context==='blocked_emails'){applyBlockedListFilter();return;}
  if(context==='none')return;
  if(pinnedOnlySearch){pinnedOnlySearch=false;}
  if(searchState&&!searchInput.value.trim()){
    const wasJournal=mode==='journal';
    restoreSearchView({returnToLiveChat:!wasJournal,focusComposer:!wasJournal}).then(()=>displaySystem('Поиск очищен.','info'));
  }
});
$('search-clear')?.addEventListener('click',async()=>{
  const context=getTopSearchContext();
  if(searchInput)searchInput.value='';
  if(context==='users'){applyUsersFilter('');updateSearchClearButton();return;}
  if(context==='blocked_nicks'||context==='blocked_emails'){applyBlockedListFilter();updateSearchClearButton();return;}
  if(context==='none'){updateSearchClearButton();return;}
  const activeSearch=!!(searchState||pinnedOnlySearch);const wasJournal=mode==='journal';
  if(activeSearch){await restoreSearchView({returnToLiveChat:!wasJournal,focusComposer:!wasJournal});displaySystem('Поиск очищен.','info');}
  else{updateSearchClearButton();if(mode==='chat'&&!currentDate)focusComposerAtEnd();}
});

function adminElement(tag,className,text){const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=String(text);return el;}
function addStorageMetric(parent,label,value){const row=adminElement('div','admin-storage-metric');row.append(adminElement('span','admin-storage-label',label),adminElement('span','admin-storage-value',value));parent.append(row);}
function renderAdminStoragePage(){
  timeline.replaceChildren();
  const page=adminElement('section','admin-tool-page');
  page.append(adminElement('h2','admin-page-title','Хранилище'));
  page.append(adminElement('p','admin-page-description','Логический объём считается по содержимому сообщений и вложений. Размер таблиц MySQL включает выделенное пространство и индексы и может не уменьшаться сразу после удаления данных.'));
  const metrics=adminElement('div','admin-storage-metrics','Загрузка состояния хранилища…');metrics.id='admin-storage-metrics';page.append(metrics);
  const actions=adminElement('div','admin-tool-actions');
  const recalc=adminElement('button','admin-tool-button','Пересчитать размер');recalc.type='button';recalc.id='storage-recalculate';
  recalc.addEventListener('click',async()=>{if(!confirm('Пересчитать объём всех сообщений и вложений? На большой базе это может занять некоторое время.'))return;recalc.disabled=true;displaySystem('Пересчёт размера хранилища…','info');try{const fd=new FormData();fd.set('action','storage_recalculate');const d=await postForm(base+'/api/admin-tools.php',fd);displaySystem(d.message||d.error,d.ok?'success':'error');if(d.ok)await refreshStorageStatus();}catch{displaySystem('Не удалось пересчитать размер хранилища.','error');}finally{recalc.disabled=false;}});
  const start=adminElement('button','admin-tool-button','Начать очистку');start.type='button';start.id='storage-cleanup-start';
  start.addEventListener('click',async()=>{const fd=new FormData();fd.set('action','storage_cleanup_start');start.disabled=true;try{const d=await postForm(base+'/api/admin-tools.php',fd);displaySystem(d.message||d.error,d.ok?'success':'error');if(d.ok&&d.active){storageCleanupGeneration++;scheduleStorageCleanupStep(0,0);}await refreshStorageStatus();}catch{displaySystem('Не удалось запустить очистку.','error');}finally{start.disabled=false;}});
  const stop=adminElement('button','admin-tool-button','Остановить очистку');stop.type='button';stop.id='storage-cleanup-stop';
  stop.addEventListener('click',async()=>{if(!confirm('Остановить дальнейшие проходы очистки хранилища? Уже удалённые сообщения восстановить нельзя.'))return;storageCleanupGeneration++;if(storageCleanupTimer){clearTimeout(storageCleanupTimer);storageCleanupTimer=null;}storageCleanupRequested=false;const fd=new FormData();fd.set('action','storage_cleanup_stop');stop.disabled=true;try{const d=await postForm(base+'/api/admin-tools.php',fd);displaySystem(d.message||d.error,d.ok?'success':'error');await refreshStorageStatus();}catch{displaySystem('Не удалось остановить очистку.','error');}finally{stop.disabled=false;}});
  actions.append(recalc,start,stop);page.append(actions);timeline.append(page);void refreshStorageStatus();
}
async function refreshStorageStatus(){
  const metrics=$('admin-storage-metrics');if(!metrics||mode!=='admin'||adminPage!=='storage')return;
  try{
    const d=await getJson(base+'/api/admin-tools.php?action=storage_status');
    if(!d.ok){metrics.textContent=d.error||'Не удалось загрузить состояние хранилища.';return;}
    const s=d.status||{};metrics.replaceChildren();metrics.dataset.quotaBytes=String(s.quota_bytes||0);
    if(s.cleanup_active&&!storageCleanupTimer&&!storageCleanupInFlight)scheduleStorageCleanupStep(0,0);
    const used=Number(s.used_bytes||0),quota=Number(s.quota_bytes||0),pct=Number(s.used_percent||0);
    addStorageMetric(metrics,'Занято по счётчику',formatBytes(used)+' из '+formatBytes(quota)+' ('+pct.toFixed(2).replace('.',',')+'%)');
    const progress=adminElement('progress','admin-storage-progress');progress.max=100;progress.value=Math.max(0,Math.min(100,pct));progress.setAttribute('aria-label','Заполнение логической квоты');metrics.append(progress);
    addStorageMetric(metrics,'Объём вложений',formatBytes(Number(s.attachment_bytes||0)));
    addStorageMetric(metrics,'Выделено MySQL (данные + индексы)',formatBytes(Number(s.database_allocated_bytes||0)));
    addStorageMetric(metrics,'Последняя сверка счётчика',s.last_recalculated_at_display||'—');
    addStorageMetric(metrics,'Последний проход очистки',s.last_cleanup_at_display||'—');
    addStorageMetric(metrics,'Результат последнего прохода',(Number(s.last_cleanup_deleted_count||0))+' сообщений · освобождено '+formatBytes(Number(s.last_cleanup_freed_bytes||0)));
    let cleanupText=s.cleanup_active?'Выполняется небольшими порциями':(s.cleanup_paused?'Остановлена администратором':'Не выполняется');
    if(pct>=Number(s.cleanup_start_percent||90)&&!s.cleanup_active&&!s.cleanup_paused)cleanupText+=' · ожидает следующего шага';
    addStorageMetric(metrics,'Очистка',cleanupText);
    addStorageMetric(metrics,'Пороги очистки','старт '+s.cleanup_start_percent+'% · цель '+s.cleanup_target_percent+'%');
    const start=$('storage-cleanup-start'),stop=$('storage-cleanup-stop'),recalc=$('storage-recalculate');
    if(start)start.disabled=!!s.cleanup_active||used<=Math.floor(quota*Number(s.cleanup_target_percent||80)/100);
    if(stop)stop.disabled=!s.cleanup_active&&!s.cleanup_paused;
    if(recalc)recalc.disabled=false;
  }catch{if(metrics)metrics.textContent='Ошибка связи при загрузке состояния хранилища.';}
}
function renderBlockedListPage(kind){
  const isNick=(kind==='nicks'||kind==='blocked_nicks');const action=isNick?'blocked_nicks':'blocked_emails';
  blockedListState=null;timeline.replaceChildren();const page=adminElement('section','admin-tool-page');
  page.append(adminElement('h2','admin-page-title',isNick?'Заблокированные ники':'Заблокированные Email'));
  page.append(adminElement('p','admin-page-description',isNick?'Эти ники запрещены для повторной регистрации. Снятие запрета не удаляет историю сообщений.':'Эти адреса запрещены для повторной регистрации. Снятие запрета не удаляет историю сообщений.'));
  const searchRow=adminElement('div','admin-blocked-filter');const count=adminElement('span','admin-blocked-count','Загрузка…');searchRow.append(count);page.append(searchRow);
  const list=adminElement('div','admin-blocked-list','Загрузка…');list.id='admin-blocked-list';page.append(list);timeline.append(page);
  const state={kind,isNick,items:[],list,count,draw:null};
  state.draw=function(query=''){
    const q=String(query||'').trim().toLocaleLowerCase();list.replaceChildren();const filtered=state.items.filter(item=>String(item.value||'').toLocaleLowerCase().includes(q));count.textContent=filtered.length+' из '+state.items.length;
    if(!filtered.length){list.append(adminElement('div','admin-empty',state.items.length?'Совпадений нет.':'Запрещённых записей нет.'));return;}
    for(const item of filtered){
      const row=adminElement('div','admin-blocked-row');const main=adminElement('div','admin-blocked-main');
      main.append(adminElement('div','admin-blocked-value',item.value));
      main.append(adminElement('div','admin-blocked-meta','Добавлено: '+(item.blocked_at_display||'—')+(item.blocked_by?' · Администратор: '+item.blocked_by:'')));
      const remove=adminElement('button','admin-tool-button','Снять запрет');remove.type='button';
      remove.addEventListener('click',async()=>{
        const what=state.isNick?'ник «'+item.value+'»':'email «'+item.value+'»';
        if(!confirm('Снять запрет на регистрацию для '+what+'?'))return;
        remove.disabled=true;const fd=new FormData();fd.set('action',state.isNick?'remove_blocked_nick':'remove_blocked_email');fd.set('key',item.key);
        try{const result=await postForm(base+'/api/admin-tools.php',fd);displaySystem(result.message||result.error,result.ok?'success':'error');if(result.ok){state.items=state.items.filter(x=>x.key!==item.key);state.draw(searchInput?.value||'');}}
        catch{displaySystem('Не удалось снять запрет.','error');}finally{remove.disabled=false;}
      });
      row.append(main,remove);list.append(row);
    }
  };
  blockedListState=state;
  getJson(base+'/api/admin-tools.php?action='+action).then(d=>{if(!d.ok){list.textContent=d.error||'Не удалось получить список.';return;}state.items=Array.isArray(d.items)?d.items:[];state.draw(searchInput?.value||'');}).catch(()=>{list.textContent='Ошибка соединения при загрузке списка.';});
}
function applyBlockedListFilter(){if(blockedListState&&typeof blockedListState.draw==='function')blockedListState.draw(searchInput?.value||'');}

async function openAdminSection(page){
  if(!isAdmin)return;
  clearSearchState(true);adminPage=page;mode='admin';journalUserId=0;currentDate=null;$('history-date').value='';setSidebarPane('admin');showModes();timeline.replaceChildren();
  if(page==='storage'){renderAdminStoragePage();return;}
  if(page==='blocked_nicks'||page==='blocked_emails'){renderBlockedListPage(page);return;}
  if(page==='journal'){adminPage=null;mode='journal';showModes();await loadJournal(true);return;}
}
function updateStorageUsedFast(bytes){
  const metrics=$('admin-storage-metrics');if(!metrics||mode!=='admin'||adminPage!=='storage')return;
  const first=metrics.querySelector('.admin-storage-metric .admin-storage-value');if(!first)return;
  const quota=Number(metrics.dataset.quotaBytes||0);const used=Math.max(0,Number(bytes||0));const percent=quota?used*100/quota:0;
  first.textContent=formatBytes(used)+' из '+formatBytes(quota)+' ('+percent.toFixed(2).replace('.',',')+'%)';
  const progress=metrics.querySelector('.admin-storage-progress');if(progress)progress.value=Math.max(0,Math.min(100,percent));
}
let storageCleanupRequested=false;
function scheduleStorageCleanupStep(protectMessageId=0,delay=0){
  if(Number(protectMessageId)>0)cleanupProtectMessageId=Number(protectMessageId);
  if(storageCleanupInFlight){storageCleanupRequested=true;return;}
  if(storageCleanupTimer)clearTimeout(storageCleanupTimer);
  const generation=storageCleanupGeneration;
  storageCleanupTimer=setTimeout(async()=>{
    storageCleanupTimer=null;if(generation!==storageCleanupGeneration)return;
    if(storageCleanupInFlight){storageCleanupRequested=true;return;}
    storageCleanupInFlight=true;let continueCleanup=false,intervalSeconds=3;
    try{
      const fd=new FormData();fd.set('protect_message_id',String(cleanupProtectMessageId||0));
      const d=await postForm(base+'/api/cleanup-step.php',fd);
      if(d.ok){if(Number.isFinite(Number(d.used_bytes)))updateStorageUsedFast(Number(d.used_bytes));continueCleanup=!!d.active&&generation===storageCleanupGeneration;intervalSeconds=Math.max(1,Number(d.interval_seconds||3));
        if(!d.active&&mode==='admin'&&adminPage==='storage')await refreshStorageStatus();
      }else{console.warn('Light Chat cleanup:',d.error||'ошибка фоновой очистки');}
    }catch(e){console.warn('Light Chat cleanup request failed',e);}
    finally{
      storageCleanupInFlight=false;
      if(generation!==storageCleanupGeneration)return;
      if(storageCleanupRequested){storageCleanupRequested=false;scheduleStorageCleanupStep(cleanupProtectMessageId,0);}
      else if(continueCleanup)scheduleStorageCleanupStep(cleanupProtectMessageId,intervalSeconds*1000);
    }
  },Math.max(0,Number(delay)||0));
}
async function banUser(id,mode){if(!confirm(mode==='RO'?'Установить пользователю режим только чтение?':'Установить пользователю полный BAN?'))return;const fd=new FormData();fd.set('action','ban');fd.set('user_id',id);fd.set('mode',mode);const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok)loadUsers();}
async function unbanUser(id){if(!confirm('Снять полный бан с пользователя?'))return;const fd=new FormData();fd.set('action','unban');fd.set('user_id',id);const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok)loadUsers();}
async function unroUser(id){const fd=new FormData();fd.set('action','unro');fd.set('user_id',id);const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok)loadUsers();}
function closeDeleteUserDialog(){const overlay=$('user-delete-overlay');overlay?.classList.add('hidden');}
function openDeleteUserDialog(u){$('delete-user-id').value=String(u.id);$('delete-block-email').checked=false;$('delete-block-nick').checked=false;$('user-delete-title').textContent='Удалить пользователя «'+u.nick+'»?';$('user-delete-description').textContent='История сообщений, цитаты и закрепления останутся в чате.';$('user-delete-overlay').classList.remove('hidden');$('delete-block-email').focus();}
$('cancel-user-delete')?.addEventListener('click',closeDeleteUserDialog);$('user-delete-overlay')?.addEventListener('click',e=>{if(e.target===$('user-delete-overlay'))closeDeleteUserDialog();});document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDeleteUserDialog();closeMobileSmilesPopover();closeMobileMenu();}});
$('user-delete-form')?.addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData();fd.set('action','delete_user');fd.set('user_id',$('delete-user-id').value);if($('delete-block-email').checked)fd.set('block_email','1');if($('delete-block-nick').checked)fd.set('block_nick','1');const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok){closeDeleteUserDialog();await loadUsers();}});
async function deleteMessage(id){if(!confirm('Удалить сообщение и все вложения?'))return;const fd=new FormData();fd.set('action','delete');fd.set('message_id',id);const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok)await loadInitial();}
async function pinMessage(id,pin){const fd=new FormData();fd.set('action',pin?'pin':'unpin');fd.set('message_id',id);const d=await postForm(base+'/api/admin.php',fd);displaySystem(d.message||d.error);if(d.ok){await loadInitial();loadPinned();}}
$('notification-mode')?.addEventListener('change',async e=>{const fd=new FormData();fd.set('notification_mode',e.target.value);const d=await postForm(base+'/api/preferences.php',fd);if(d.ok){currentNotificationMode=d.notification_mode;displaySystem('Режим уведомлений сохранён.');}else{displaySystem(d.error||'Не удалось сохранить настройки.','error');loadUsers();}});
function applyDarkTheme(enabled){const on=!!enabled;document.documentElement.classList.toggle('theme-dark',on);document.body.classList.toggle('theme-dark',on);if(darkThemeToggle)darkThemeToggle.checked=on;updateSmilesTheme();}
darkThemeToggle?.addEventListener('change',async e=>{
  const previous=document.body.classList.contains('theme-dark');
  const requested=!!e.target.checked;
  applyDarkTheme(requested);
  const fd=new FormData();fd.set('dark_theme',requested?'1':'0');
  try{
    const d=await postForm(base+'/api/preferences.php',fd);
    if(d.ok){applyDarkTheme(Number(d.dark_theme)===1);displaySystem(requested?'Тёмная тема включена и сохранена.':'Светлая тема включена и сохранена.','success');}
    else{applyDarkTheme(previous);displaySystem(d.error||'Не удалось сохранить тему оформления.','error');}
  }catch{applyDarkTheme(previous);displaySystem('Не удалось сохранить тему оформления.','error');}
});
async function loadPreferences(){try{const d=await getJson(base+'/api/preferences.php');if(d.ok){currentNotificationMode=d.notification_mode||'MINE';$('notification-mode').value=currentNotificationMode;applyDarkTheme(Number(d.dark_theme)===1);}}catch{}}
async function presence(){if(document.hidden)return;const fd=new FormData();try{await postForm(base+'/api/presence.php',fd);}catch{}}
function schedulePolling(){if(pollTimer)clearInterval(pollTimer);pollTimer=setInterval(()=>{if(!document.hidden)loadLatest();},Math.max(1,Number(app.dataset.pollSeconds||2))*1000);}
document.addEventListener('visibilitychange',()=>{if(!document.hidden){presence();loadUsers();loadLatest();}});
$('profile-toggle')?.addEventListener('click',()=>$('profile-panel').classList.toggle('hidden'));$('profile-close')?.addEventListener('click',()=>$('profile-panel').classList.add('hidden'));
$('nick-form')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);setResult($('nick-result'),'Отправка подтверждения…');const d=await postForm(base+'/auth/request-nick.php',f);setResult($('nick-result'),d.message||d.error,!!d.ok);displaySystem(d.message||d.error||'Запрос смены ника обработан.');if(d.ok)e.currentTarget.reset();});
$('logout-form')?.addEventListener('submit',async e=>{e.preventDefault();const d=await postForm(base+'/auth/logout.php',new FormData());if(d.ok)location.href=base+'/';else displaySystem(d.error||'Не удалось выйти.');});
// Локальный QIP-сигнал. Один Audio-объект переиспользуется; браузер кэширует MP3.
let qipAudio=null,qipAudioPrimed=false,qipPrimeInProgress=false,qipPendingPlayback=false;
function getQipAudio(){
  if(qipAudio)return qipAudio;
  qipAudio=new Audio(base+'/assets/sounds/qip-oh-ou.mp3');
  qipAudio.preload='auto';qipAudio.volume=0.68;
  qipAudio.addEventListener('error',()=>console.warn('Light Chat: не удалось загрузить assets/sounds/qip-oh-ou.mp3. Проверьте путь и доступность MP3.'));
  return qipAudio;
}
function primeQipAudio(){
  if(qipAudioPrimed||qipPrimeInProgress)return;
  const audio=getQipAudio();qipPrimeInProgress=true;const oldMuted=audio.muted;audio.muted=true;
  try{
    const playResult=audio.play();
    if(playResult&&typeof playResult.then==='function'){
      playResult.then(()=>{audio.pause();try{audio.currentTime=0;}catch{}audio.muted=oldMuted;qipAudioPrimed=true;qipPrimeInProgress=false;document.removeEventListener('pointerdown',primeQipAudio);document.removeEventListener('keydown',primeQipAudio);if(qipPendingPlayback){qipPendingPlayback=false;playQipNotificationSound();}})
        .catch(()=>{audio.muted=oldMuted;qipPrimeInProgress=false;qipPendingPlayback=false;});
    }else{audio.pause();try{audio.currentTime=0;}catch{}audio.muted=oldMuted;qipAudioPrimed=true;qipPrimeInProgress=false;}
  }catch{audio.muted=oldMuted;qipPrimeInProgress=false;}
}
document.addEventListener('pointerdown',primeQipAudio,{passive:true});document.addEventListener('keydown',primeQipAudio);
function playQipNotificationSound(){
  const audio=getQipAudio();
  if(qipPrimeInProgress){qipPendingPlayback=true;return;}
  try{audio.pause();audio.muted=false;audio.currentTime=0;const playResult=audio.play();if(playResult&&typeof playResult.catch==='function')playResult.catch(()=>{/* Браузер может запретить звук до первого действия пользователя. */});}catch{}
}
window.chatPlaySound=playQipNotificationSound;
const serverClock=$('server-clock');
const clockServerEpoch=Number(app.dataset.serverEpoch||Math.floor(Date.now()/1000));
const clockStart=performance.now();
const clockZone=app.dataset.timezone||'UTC';
let clockFormatter=null;
try{clockFormatter=new Intl.DateTimeFormat('ru-RU',{timeZone:clockZone,day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});}catch{}
function tickServerClock(){if(!serverClock)return;const epoch=clockServerEpoch+(performance.now()-clockStart)/1000;const dt=new Date(epoch*1000);serverClock.textContent=clockFormatter?clockFormatter.format(dt):dt.toISOString().slice(0,19).replace('T',' ');}
tickServerClock();setInterval(tickServerClock,1000);
moveResponsiveControls();syncMobileViewport();
const composerWrap=$('composer-wrap'), centerPanel=app.querySelector('.center-panel');
function syncComposerHeight(){if(composerWrap&&centerPanel)centerPanel.style.setProperty('--composer-wrap-height',composerWrap.offsetHeight+'px');}
if(typeof ResizeObserver!=='undefined'&&composerWrap){new ResizeObserver(syncComposerHeight).observe(composerWrap);}else{window.addEventListener('resize',syncComposerHeight,{passive:true});}
syncComposerHeight();
updateComposerAccess();updateSendButtonState();setSidebarPane('users');showModes();updateSearchClearButton();loadPreferences();loadSmiles();loadInitial();loadUsers();presence();presenceTimer=setInterval(presence,Math.max(15,Number(app.dataset.presenceSeconds||30))*1000);schedulePolling();setInterval(loadUsers,30000);
})();

/* Light Chat v1.1.9: synchronize root viewport theme with dark mode. */
