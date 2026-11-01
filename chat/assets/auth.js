(() => {
'use strict';
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const base = (document.querySelector('meta[name="chat-base"]')?.content || '').replace(/\/$/, '');
const app = $('chat-app');

async function postForm(url, formData) {
  if (!window.ChatTransport) throw new Error('Не загружен модуль связи chat1.');
  return window.ChatTransport.postForm(url, formData, csrf);
}
function setResult(el, message, ok=false) { if(!el) return; el.textContent=message||''; el.classList.toggle('error', !!message && !ok); el.classList.toggle('success', !!message && ok); }
if (!app) {
  const login = $('login-form');
  if(login) login.addEventListener('submit', async (e)=>{e.preventDefault(); const b=login.querySelector('button[type=submit]');b.disabled=true;setResult($('login-result'),'Выполняется вход…');try{const d=await postForm(base+'/auth/login.php',new FormData(login));if(d.ok)location.href=d.redirect||base+'/';else setResult($('login-result'),d.error||'Ошибка входа.');}catch{setResult($('login-result'),'Не удалось связаться с сервером.');}finally{b.disabled=false;}});
  const reg=$('register-form');
  if(reg) reg.addEventListener('submit', async (e)=>{e.preventDefault();const b=reg.querySelector('button[type=submit]');b.disabled=true;setResult($('register-result'),'Проверка данных и отправка письма…');try{const d=await postForm(base+'/auth/register.php',new FormData(reg));setResult($('register-result'),d.message||d.error||'',!!d.ok);if(d.ok)reg.reset();}catch{setResult($('register-result'),'Не удалось связаться с сервером.');}finally{b.disabled=false;}});
  const resend=$('resend-form');
  if(resend) resend.addEventListener('submit',async(e)=>{e.preventDefault();const b=resend.querySelector('button[type=submit]');b.disabled=true;setResult($('resend-result'),'Отправка запроса…');try{const d=await postForm(base+'/auth/resend-registration.php',new FormData(resend));setResult($('resend-result'),d.message||d.error||'',!!d.ok);resend.reset();}catch{setResult($('resend-result'),'Не удалось связаться с сервером.');}finally{b.disabled=false;}});
  const reset=$('reset-form');
  if(reset) reset.addEventListener('submit',async(e)=>{e.preventDefault();const b=reset.querySelector('button[type=submit]');b.disabled=true;setResult($('reset-result'),'Отправка запроса…');try{const d=await postForm(base+'/auth/request-reset.php',new FormData(reset));setResult($('reset-result'),d.message||d.error||'',!!d.ok);reset.reset();}catch{setResult($('reset-result'),'Не удалось связаться с сервером.');}finally{b.disabled=false;}});
  return;
}

})();
