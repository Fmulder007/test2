/* Единый интерфейс связи chat1.
 * Новые протоколы подключаются отдельным адаптером в этом файле.
 * Бизнес-логика чата не должна самостоятельно вызывать fetch.
 */
(() => {
'use strict';

const meta = document.querySelector('meta[name="chat-protocol"]');
const rawProtocol = String(meta?.content || '2').trim();
const protocol = Number(rawProtocol);
if (!/^[1-9][0-9]*$/.test(rawProtocol) || ![1, 2].includes(protocol)) {
  throw new Error('Неизвестный протокол chat1: ' + rawProtocol + '. Проверьте [communication] protocol в settings.cfg.');
}

async function parseJsonResponse(response, fallback) {
  let data;
  try { data = await response.json(); }
  catch { data = {ok:false, error: fallback + ' (HTTP ' + response.status + ').'}; }
  if (!response.ok && data.ok !== false) data.ok = false;
  return data;
}

async function nativeGetJson(url) {
  const response = await fetch(url, {
    method: 'GET', credentials: 'same-origin', cache: 'no-store',
    headers: {'Accept': 'application/json'}
  });
  return parseJsonResponse(response, 'Сервер вернул не JSON');
}

async function nativePost(url, body, csrfHeader = '') {
  const headers = {'Accept': 'application/json'};
  if (csrfHeader) headers['X-CSRF-Token'] = csrfHeader;
  const response = await fetch(url, {
    method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers
  });
  return parseJsonResponse(response, 'Сервер вернул не JSON');
}

function addCsrf(formData, csrfToken) {
  if (csrfToken && !formData.has('csrf_token')) formData.append('csrf_token', csrfToken);
  return formData;
}

function toUrlEncoded(formData) {
  const body = new URLSearchParams();
  for (const [key, value] of formData.entries()) {
    if (typeof value !== 'string') {
      throw new Error('В протоколе 2 файлы передаются отдельными блоками, а не текстовой формой.');
    }
    body.append(key, value);
  }
  return body;
}

const adapters = {
  1: {
    name: 'classic-formdata',
    supportsChunkedUploads: false,
    postForm(url, formData, csrfToken) {
      // Классический режим: весь POST — FormData; вложения идут вместе с сообщением.
      // Content-Type и boundary браузер выставляет самостоятельно.
      return nativePost(url, addCsrf(formData, csrfToken), csrfToken);
    },
    getJson: nativeGetJson,
    download(event, url, filename, anchor) {
      // В протоколе 1 сохраняется обычная браузерная загрузка по URL.
      // Вернуть true будущий адаптер может, если обработает загрузку самостоятельно.
      void event; void url; void filename; void anchor;
      return false;
    },
    setImageSource(image, url) { image.src = url; }
  },
  2: {
    name: 'urlencoded-chunks',
    supportsChunkedUploads: true,
    postForm(url, formData, csrfToken) {
      // Все обычные POST-запросы — URL-кодированные текстовые поля.
      // Вложения передаются через api/upload-chunk.php отдельными блоками.
      return nativePost(url, toUrlEncoded(addCsrf(formData, csrfToken)));
    },
    getJson: nativeGetJson,
    download(event, url, filename, anchor) {
      // В протоколе 2 оставляем штатную браузерную загрузку по URL.
      void event; void url; void filename; void anchor;
      return false;
    },
    setImageSource(image, url) { image.src = url; }
  }
};

const adapter = adapters[protocol];
if (!adapter) throw new Error('Для протокола ' + protocol + ' не зарегистрирован транспортный адаптер.');

window.ChatTransport = Object.freeze({
  protocol,
  name: adapter.name,
  supportsChunkedUploads: adapter.supportsChunkedUploads,
  postForm(url, formData, csrfToken) {
    const fd = formData instanceof FormData ? formData : new FormData();
    return adapter.postForm(url, fd, String(csrfToken || ''));
  },
  getJson(url) { return adapter.getJson(url); },
  bindDownloadLink(anchor, url, filename) {
    if (!anchor || anchor.dataset.chatTransportBound === '1') return;
    anchor.dataset.chatTransportBound = '1';
    anchor.addEventListener('click', event => {
      const handled = adapter.download(event, url, filename, anchor);
      if (handled === true) event.preventDefault();
    });
  },
  setImageSource(image, url) { adapter.setImageSource(image, url); }
});
})();
