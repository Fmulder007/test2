import { createVLC } from './vendor/libvlc-wasm/src/index.js';

const $ = (id) => document.getElementById(id);
const ui = {
  isolation: $('isolation'),
  url: $('mediaUrl'),
  open: $('openButton'),
  canvas: $('videoCanvas'),
  overlay: $('overlay'),
  play: $('playButton'),
  stop: $('stopButton'),
  seek: $('seek'),
  current: $('currentTime'),
  duration: $('duration'),
  volume: $('volume'),
  fullscreen: $('fullscreenButton'),
  log: $('log')
};

let vlc = null;
let player = null;
let seeking = false;
let timer = null;

function log(message) {
  const stamp = new Date().toLocaleTimeString();
  ui.log.textContent += `\n[${stamp}] ${message}`;
  ui.log.scrollTop = ui.log.scrollHeight;
}

function formatTime(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

function setControls(enabled) {
  ui.play.disabled = !enabled;
  ui.stop.disabled = !enabled;
  ui.seek.disabled = !enabled;
  ui.volume.disabled = !enabled;
}

ui.isolation.textContent = `secureContext: ${String(globalThis.isSecureContext)}; crossOriginIsolated: ${String(globalThis.crossOriginIsolated)}; SharedArrayBuffer: ${typeof SharedArrayBuffer}`;
if (!globalThis.isSecureContext) {
  ui.isolation.textContent += ' — НЕТ SECURE CONTEXT: HTTP по IP не подходит; нужен HTTPS или тестовая настройка браузера.';
  ui.open.disabled = true;
  log('Эта страница открыта в небезопасном контексте. SharedArrayBuffer недоступен даже при COOP/COEP.');
} else if (!globalThis.crossOriginIsolated || typeof SharedArrayBuffer === 'undefined') {
  ui.isolation.textContent += ' — НЕТ ИЗОЛЯЦИИ: проверьте COOP/COEP в Nginx.';
  ui.open.disabled = true;
  log('Браузер не получил cross-origin isolation. VLC запускать нельзя.');
} else {
  ui.isolation.textContent += ' — secure context и изоляция включены.';
  log('Secure context, COOP/COEP и SharedArrayBuffer подтверждены браузером.');
}

async function startEngine() {
  if (vlc) return;
  const wasmUrl = new URL('./vendor/libvlc-wasm/wasm/libvlc.wasm', import.meta.url).href;
  log('Загрузка libvlc-wasm 0.2.0 с локального сервера TALI…');
  log(`WASM: ${wasmUrl}`);
  vlc = await createVLC({
    threads: 2,
    decoderThreads: 1,
    wasmUrl,
    httpCache: 8 * 1024 * 1024,
    fonts: false,
    logLevel: 'warn'
  });
  log(`Движок VLC готов: ${vlc.version?.version ?? 'версия не сообщена'}; запуск ${Math.round(vlc.startupMs)} мс.`);
  player = await vlc.createPlayer({ canvas: ui.canvas, fit: 'contain' });
  player.on('statechange', (state) => {
    ui.play.textContent = state === 'playing' ? 'Ⅱ' : '▶';
    log(`Состояние VLC: ${state}`);
  });
  player.on('error', (error) => log(`Ошибка VLC: ${error?.message ?? error}`));
  player.on('durationchange', (duration) => {
    ui.seek.max = duration || 1;
    ui.duration.textContent = formatTime(duration);
  });
  player.on('timeupdate', (time) => {
    if (!seeking) ui.seek.value = time;
    ui.current.textContent = formatTime(time);
  });
  setControls(true);
}

ui.open.addEventListener('click', async () => {
  const raw = ui.url.value.trim();
  if (!raw) {
    log('Укажите путь к видеофайлу.');
    return;
  }
  const url = new URL(raw, location.origin);
  if (url.origin !== location.origin) {
    log('Для теста укажите файл на этом же сервере TALI.');
    return;
  }
  ui.open.disabled = true;
  ui.overlay.textContent = 'Загрузка VLC и открытие файла…';
  try {
    await startEngine();
    log(`Открываю: ${url.pathname}`);
    await player.open(url.href, { autoplay: true });
    ui.overlay.hidden = true;
    log('Команда открытия передана VLC.');
  } catch (error) {
    ui.overlay.hidden = false;
    ui.overlay.textContent = error?.message ?? String(error);
    log(`ОШИБКА: ${error?.stack ?? error}`);
  } finally {
    ui.open.disabled = false;
  }
});

ui.play.addEventListener('click', async () => {
  if (!player) return;
  try {
    if (player.state === 'playing') await player.pause();
    else await player.play();
  } catch (error) { log(`Ошибка управления: ${error.message}`); }
});

ui.stop.addEventListener('click', async () => {
  if (!player) return;
  await player.stop();
  ui.overlay.hidden = false;
  ui.overlay.textContent = 'Воспроизведение остановлено';
});

ui.seek.addEventListener('input', () => {
  seeking = true;
  ui.current.textContent = formatTime(Number(ui.seek.value));
});
ui.seek.addEventListener('change', async () => {
  if (player) {
    try { await player.seek(Number(ui.seek.value)); }
    catch (error) { log(`Ошибка перемотки: ${error.message}`); }
  }
  seeking = false;
});
ui.volume.addEventListener('input', () => {
  if (player) player.volume = Number(ui.volume.value);
});
ui.fullscreen.addEventListener('click', async () => {
  const frame = ui.canvas.parentElement;
  if (document.fullscreenElement) await document.exitFullscreen();
  else await frame.requestFullscreen();
});

window.addEventListener('beforeunload', () => {
  if (timer) clearInterval(timer);
  player?.destroy();
  vlc?.destroy();
});
