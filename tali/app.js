'use strict';

(() => {
  const API = '/api.php';
  const $ = (id) => document.getElementById(id);

  const el = {
    loginPanel: $('loginPanel'), appPanel: $('appPanel'), loginForm: $('loginForm'),
    nickname: $('nicknameInput'), remember: $('rememberInput'), loginError: $('loginError'),
    profileInfo: $('profileInfo'), logout: $('logoutButton'), theme: $('themeButton'),
    navButtons: [...document.querySelectorAll('.nav-button')],
    playerPanel: $('playerPanel'), stage: $('videoStage'), video: $('videoPlayer'), nowPlaying: $('nowPlaying'),
    resumeLabel: $('resumeLabel'), restart: $('restartButton'), previous: $('previousButton'),
    playPause: $('playPauseButton'), next: $('nextButton'), seek: $('seekInput'),
    currentTime: $('currentTime'), durationTime: $('durationTime'),
    mute: $('volumeButton'), volume: $('volumeInput'), fullscreen: $('fullscreenButton'), rotate: $('rotateButton'),
    download: $('downloadButton'),
    browseToolbar: $('browseToolbar'), breadcrumbs: $('breadcrumbs'), listHeader: $('listHeader'),
    list: $('itemList'), empty: $('emptyMessage'), loading: $('loadingMessage'),
    broadcastPanel: $('broadcastPanel'), broadcastSummary: $('broadcastSummary'),
    broadcastRefresh: $('broadcastRefresh'), broadcastStatusDot: $('broadcastStatusDot'),
    broadcastState: $('broadcastState'), broadcastFlags: $('broadcastFlags'),
    broadcastPlay: $('broadcastPlay'), broadcastPause: $('broadcastPause'),
    broadcastStop: $('broadcastStop'), broadcastPrevious: $('broadcastPrevious'),
    broadcastNext: $('broadcastNext'), broadcastLoop: $('broadcastLoop'),
    broadcastEnable: $('broadcastEnable'), broadcastCount: $('broadcastCount'),
    broadcastList: $('broadcastList'), broadcastRaw: $('broadcastRaw'),
    playlistDialog: $('playlistDialog'), playlistDialogForm: $('playlistDialogForm'),
    playlistDialogFile: $('playlistDialogFile'), playlistSelect: $('playlistSelect'),
    playlistDialogError: $('playlistDialogError'), closePlaylistDialog: $('closePlaylistDialog'),
    createPlaylistFromDialog: $('createPlaylistFromDialog'), toast: $('toast')
  };

  const state = {
    user: null,
    view: 'browse',
    path: sessionStorage.getItem('tali_path') || '',
    directoryItems: [],
    playlists: [],
    activePlaylist: null,
    queue: [],
    queueIndex: -1,
    currentItem: null,
    playbackEnded: false,
    lastSaveAt: 0,
    ignoreNextPauseSave: false,
    saveChain: Promise.resolve(),
    rotation: 0,
    fullscreenActive: false,
    fullscreenEntryPending: false,
    orientationLocked: false,
    nativeFullscreenActive: false,
    lastTouchAt: 0,
    pendingPlaylistItem: null,
    pendingPlaylistBatch: null,
    toastTimer: null,
    preferences: { sort_by: 'name', sort_direction: 'asc' },
    playlistSort: null,
    broadcastInfo: null,
    broadcastSelectedIndex: null,
    preferenceSaveChain: Promise.resolve()
  };

  async function api(action, data = {}, method = 'POST') {
    const options = {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {}
    };

    let url = API;
    if (method === 'GET') {
      url += '?action=' + encodeURIComponent(action);
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({ ...data, action });
    }

    const response = await fetch(url, options);
    let result;
    try {
      result = await response.json();
    } catch (_) {
      throw new Error('Сервер вернул некорректный ответ');
    }

    if (!response.ok || !result || result.ok !== true) {
      if (response.status === 401) {
        showLogin();
      }
      throw new Error(result && result.error ? result.error : `Ошибка HTTP ${response.status}`);
    }
    return result;
  }

  function showToast(message) {
    if (el.toast.classList.contains('toast-error')) return;
    clearTimeout(state.toastTimer);
    el.toast.classList.remove('toast-error');
    el.toast.replaceChildren(document.createTextNode(String(message)));
    el.toast.setAttribute('role', 'status');
    el.toast.classList.add('visible');
    state.toastTimer = setTimeout(() => el.toast.classList.remove('visible'), 2600);
  }

  function showError(message) {
    clearTimeout(state.toastTimer);
    state.toastTimer = null;

    const text = document.createElement('span');
    text.className = 'toast-message';
    text.textContent = String(message);

    const close = document.createElement('button');
    close.className = 'toast-error-close';
    close.type = 'button';
    close.textContent = '×';
    close.setAttribute('aria-label', 'Закрыть сообщение об ошибке');
    close.addEventListener('click', () => {
      el.toast.classList.remove('visible', 'toast-error');
      el.toast.replaceChildren();
      el.toast.setAttribute('role', 'status');
    });

    el.toast.replaceChildren(text, close);
    el.toast.setAttribute('role', 'alert');
    el.toast.classList.add('toast-error', 'visible');
  }

  function setLoading(loading) {
    el.loading.hidden = !loading;
    if (loading) {
      el.empty.hidden = true;
      el.list.replaceChildren();
    }
  }

  function showLogin(message = '') {
    state.user = null;
    el.appPanel.hidden = true;
    el.profileInfo.hidden = true;
    el.logout.hidden = true;
    el.loginPanel.hidden = false;
    el.loginError.textContent = message;
    el.nickname.focus();
    stopPlayer();
  }

  function showApp() {
    el.loginPanel.hidden = true;
    el.appPanel.hidden = false;
    el.profileInfo.hidden = false;
    el.logout.hidden = false;
    el.profileInfo.replaceChildren();
    const profileLabel = document.createElement('span');
    profileLabel.className = 'profile-label';
    profileLabel.textContent = 'Профиль: ';
    const profileName = document.createElement('strong');
    profileName.textContent = state.user.nickname;
    el.profileInfo.append(profileLabel, profileName);
  }

  function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#171a1e' : '#f4f5f7';
    localStorage.setItem('tali_theme', theme);
    el.theme.textContent = theme === 'dark' ? '☀' : '◐';
    el.theme.title = theme === 'dark' ? 'Включить дневную тему' : 'Включить ночную тему';
  }

  function initTheme() {
    const saved = localStorage.getItem('tali_theme');
    setTheme(saved === 'dark' ? 'dark' : 'light');
  }

  function setView(view) {
    state.view = view;
    sessionStorage.setItem('tali_view', view);
    sessionStorage.removeItem('tali_playlist');
    state.activePlaylist = null;
    el.navButtons.forEach((button) => button.classList.toggle('active', button.dataset.view === view));
    el.playerPanel.hidden = !state.currentItem;
    el.browseToolbar.hidden = view !== 'browse';
    el.broadcastPanel.hidden = view !== 'broadcast';
    el.listHeader.hidden = view === 'broadcast';
    el.list.hidden = view === 'broadcast';
    if (view === 'broadcast') {
      el.empty.hidden = true;
      el.loading.hidden = true;
      loadBroadcast();
      return;
    }
    updateListHeader(view === 'browse' ? 'directory' : view);
    if (view === 'browse') {
      loadDirectory(state.path);
    } else if (view === 'history') {
      loadHistory();
    } else if (view === 'playlists') {
      loadPlaylists();
    }
  }

  function formatBytes(bytes) {
    if (!Number.isFinite(Number(bytes)) || Number(bytes) < 0) return '';
    const value = Number(bytes);
    if (value < 1024) return `${value} Б`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(0)} КБ`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} МБ`;
    return `${(value / 1024 ** 3).toFixed(2)} ГБ`;
  }

  function formatModified(timestamp) {
    const value = Number(timestamp);
    if (!Number.isFinite(value) || value <= 0) return '—';
    const date = new Date(value * 1000);
    return new Intl.DateTimeFormat('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    }).format(date);
  }

  function parseApiDate(value) {
    if (typeof value === 'number' && Number.isFinite(value)) {
      const numericDate = new Date(value * 1000);
      return Number.isNaN(numericDate.getTime()) ? null : numericDate;
    }

    const raw = String(value ?? '').trim();
    if (!raw) return null;

    let normalized = raw;
    if (/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(raw)) {
      normalized = raw.replace(' ', 'T') + 'Z';
    } else if (/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(raw)) {
      normalized = raw + 'Z';
    }

    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function appendDateCell(row, value, unixTimestamp = false) {
    const cell = document.createElement('time');
    cell.className = 'entry-date';
    const numericValue = Number(value);
    const date = unixTimestamp
      ? (numericValue > 0 ? parseApiDate(numericValue) : null)
      : parseApiDate(value);

    if (!date) {
      cell.textContent = '—';
      row.append(cell);
      return cell;
    }

    const datePart = document.createElement('span');
    datePart.className = 'entry-date-part';
    datePart.textContent = new Intl.DateTimeFormat('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric'
    }).format(date);

    const timePart = document.createElement('span');
    timePart.className = 'entry-time-part';
    timePart.textContent = new Intl.DateTimeFormat('ru-RU', {
      hour: '2-digit', minute: '2-digit'
    }).format(date);

    cell.dateTime = date.toISOString();
    cell.title = date.toLocaleString('ru-RU');
    cell.append(datePart, timePart);
    row.append(cell);
    return cell;
  }

  function itemPath(item) {
    return String(item?.path || item?.media_path || '');
  }

  function updateQueueControls() {
    el.previous.disabled = state.queueIndex <= 0;
    el.next.disabled = state.queueIndex < 0 || state.queueIndex >= state.queue.length - 1;
  }

  function updateVideoRotation() {
    const quarter = state.rotation === 90 || state.rotation === 270;
    if (quarter) {
      el.video.style.position='absolute'; el.video.style.width=el.stage.clientHeight+'px';
      el.video.style.height=el.stage.clientWidth+'px'; el.video.style.left='50%'; el.video.style.top='50%';
      el.video.style.transform=`translate(-50%, -50%) rotate(${state.rotation}deg)`;
    } else {
      el.video.style.position='relative'; el.video.style.width='100%'; el.video.style.height='100%';
      el.video.style.left=''; el.video.style.top='';
      el.video.style.transform=state.rotation===180?'rotate(180deg)':'';
    }
    el.rotate.textContent=state.rotation+'°';
    el.rotate.title='Поворот видео: '+state.rotation+'°';
    el.rotate.setAttribute('aria-label',el.rotate.title);
  }
  function rotateVideo() { state.rotation=(state.rotation+90)%360; updateVideoRotation(); }

  function updatePlaybackControls() {
    const duration = Number(el.video.duration);
    const position = Number(el.video.currentTime);
    const hasDuration = Number.isFinite(duration) && duration > 0;

    el.seek.disabled = !hasDuration;
    el.seek.value = hasDuration
      ? String(Math.min(100, Math.max(0, position / duration * 100)))
      : '0';
    el.currentTime.textContent = formatTime(Number.isFinite(position) ? position : 0);
    el.durationTime.textContent = hasDuration ? formatTime(duration) : '--:--';

    el.playPause.textContent = el.video.paused ? '▶' : 'Ⅱ';
    el.playPause.title = el.video.paused ? 'Воспроизвести' : 'Приостановить';
    el.playPause.setAttribute('aria-label', el.playPause.title);

    const muted = el.video.muted || el.video.volume === 0;
    el.mute.textContent = muted ? '🔇' : (el.video.volume < 0.5 ? '🔉' : '🔊');
    el.mute.title = muted ? 'Включить звук' : 'Выключить звук';
    el.mute.setAttribute('aria-label', el.mute.title);
    el.volume.value = String(el.video.volume);

    const fullscreen = document.fullscreenElement === el.stage ||
      document.webkitFullscreenElement === el.stage ||
      el.video.webkitDisplayingFullscreen === true;
    el.fullscreen.textContent = fullscreen ? '⤡' : '⛶';
    el.fullscreen.title = fullscreen ? 'Выйти из полноэкранного режима' : 'Во весь экран';
    el.fullscreen.setAttribute('aria-label', el.fullscreen.title);

    updateQueueControls();
    updateVideoRotation();
  }

  function normalizePreferences(value) {
    const preferences = value && typeof value === 'object' ? value : {};
    return {
      sort_by: ['name', 'modified', 'size'].includes(preferences.sort_by)
        ? preferences.sort_by : 'name',
      sort_direction: ['asc', 'desc'].includes(preferences.sort_direction)
        ? preferences.sort_direction : 'asc'
    };
  }

  const nameCollator = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });

  function sortDirectoryItems(items) {
    const direction = state.preferences.sort_direction === 'desc' ? -1 : 1;
    const field = state.preferences.sort_by;
    return [...items].sort((a, b) => {
      // Каталоги всегда остаются выше видеофайлов.
      const aDirectory = a.type === 'directory';
      const bDirectory = b.type === 'directory';
      if (aDirectory !== bDirectory) return aDirectory ? -1 : 1;

      let comparison = 0;
      if (field === 'modified') {
        comparison = (Number(a.modified) || 0) - (Number(b.modified) || 0);
      } else if (field === 'size') {
        comparison = (Number(a.size) || 0) - (Number(b.size) || 0);
      } else {
        comparison = nameCollator.compare(String(a.name || ''), String(b.name || ''));
      }

      if (comparison === 0) {
        comparison = nameCollator.compare(String(a.name || ''), String(b.name || ''));
      }
      return comparison * direction;
    });
  }

  function sortPlaylistItems(items) {
    if (!state.playlistSort) return [...items];

    const { sort_by: field, sort_direction: directionValue } = state.playlistSort;
    const direction = directionValue === 'desc' ? -1 : 1;

    return [...items].sort((a, b) => {
      const aName = String(a.name || basename(a.media_path || a.path || ''));
      const bName = String(b.name || basename(b.media_path || b.path || ''));
      let comparison = 0;

      if (field === 'modified') {
        comparison = (Number(a.modified) || 0) - (Number(b.modified) || 0);
      } else if (field === 'size') {
        comparison = (Number(a.size) || 0) - (Number(b.size) || 0);
      } else {
        comparison = nameCollator.compare(aName, bName);
      }

      if (comparison === 0) comparison = nameCollator.compare(aName, bName);
      return comparison * direction;
    });
  }

  function updateSortHeader() {
    const playlistSorting = state.view === 'playlists' && state.activePlaylist && state.playlistSort
      ? state.playlistSort : null;

    document.querySelectorAll('.sort-button').forEach((button) => {
      const field = button.dataset.sort;
      const directoryActive = state.view === 'browse' && field === state.preferences.sort_by;
      const playlistActive = Boolean(playlistSorting) && field === playlistSorting.sort_by;
      const active = directoryActive || playlistActive;
      const direction = playlistActive
        ? playlistSorting.sort_direction
        : state.preferences.sort_direction;
      const indicator = button.querySelector('.sort-indicator');

      button.classList.toggle('sort-button-active', active);
      button.setAttribute('aria-sort', active
        ? (direction === 'asc' ? 'ascending' : 'descending')
        : 'none');

      if (indicator) {
        indicator.textContent = active ? (direction === 'asc' ? '▲' : '▼') : '↕';
      }
    });
  }

  function updateListHeader(mode) {
    const isDirectory = mode === 'directory';
    const isPlaylist = mode === 'playlist';
    el.listHeader.hidden = false;
    el.listHeader.classList.toggle('list-header--directory', isDirectory);
    el.listHeader.classList.toggle('list-header--playlist', isPlaylist);

    const dateDesktop = el.listHeader.querySelector('.date-label-desktop');
    const dateMobileSecond = el.listHeader.querySelector('.date-label-second');
    const infoLabel = el.listHeader.querySelector('.info-label');

    const dateLabels = {
      directory: { desktop: 'Дата изменения', mobileSecond: 'ИЗМЕНЕНИЯ' },
      history: { desktop: 'Дата просмотра', mobileSecond: 'ПРОСМОТРА' },
      playlist: { desktop: 'Дата изменения', mobileSecond: 'ИЗМЕНЕНИЯ' },
      'playlist-index': { desktop: 'Дата изменения', mobileSecond: 'ИЗМЕНЕНИЯ' }
    };
    const labels = dateLabels[mode] || dateLabels.directory;

    if (dateDesktop) dateDesktop.textContent = labels.desktop;
    if (dateMobileSecond) dateMobileSecond.textContent = labels.mobileSecond;
    if (infoLabel) {
      infoLabel.textContent = mode === 'directory' || mode === 'playlist' ? 'Размер'
        : mode === 'playlist-index' ? 'Видео'
        : 'Статус';
    }

    // Three action slots also reserve enough room for the "В эфир" button
    // in the playlist heading row on narrow screens.
    const actionColumns = mode === 'playlist-index' ? 2
      : mode === 'playlist' ? 3
      : 1;
    el.listHeader.dataset.actionCount = String(actionColumns);
    el.list.dataset.actionCount = String(actionColumns);

    const dateButton = el.listHeader.querySelector('[data-sort="modified"]');
    if (dateButton) {
      dateButton.setAttribute('aria-label', 'Сортировать по: ' + labels.desktop.toLowerCase());
    }

    const actionsHeading = el.listHeader.querySelector('.actions-heading');
    if (actionsHeading) {
      actionsHeading.replaceChildren();

      if (isDirectory) {
        const addAll = document.createElement('button');
        addAll.type = 'button';
        addAll.className = 'small-button';
        addAll.innerHTML = '<span class="action-label-full">+ список</span><span class="action-label-mobile">＋</span>';
        addAll.title = 'Добавить все видео из этого каталога в плейлист';
        addAll.setAttribute('aria-label', addAll.title);
        addAll.addEventListener('click', openDirectoryAddToPlaylist);
        actionsHeading.append(addAll);
      } else if (isPlaylist && state.playlistSort) {
        const manualOrder = document.createElement('button');
        manualOrder.type = 'button';
        manualOrder.className = 'small-button';
        manualOrder.innerHTML = '<span class="action-label-full">Ручной порядок</span><span class="action-label-mobile">↩</span>';
        manualOrder.title = 'Вернуться к сохранённому ручному порядку плейлиста';
        manualOrder.setAttribute('aria-label', manualOrder.title);
        manualOrder.addEventListener('click', () => {
          state.playlistSort = null;
          renderPlaylist(state.activePlaylist);
        });
        actionsHeading.append(manualOrder);
      }
    }

    updateSortHeader();
  }

  function saveSortPreferences() {
    const preferences = { ...state.preferences };
    state.preferenceSaveChain = state.preferenceSaveChain
      .then(() => api('save_preferences', { preferences }))
      .catch((error) => showError(`Не удалось сохранить сортировку: ${error.message}`));
  }

  function changeSort(field) {
    if (!['name', 'modified', 'size'].includes(field)) return;
    if (state.preferences.sort_by === field) {
      state.preferences.sort_direction = state.preferences.sort_direction === 'asc' ? 'desc' : 'asc';
    } else {
      state.preferences.sort_by = field;
      state.preferences.sort_direction = field === 'name' ? 'asc' : 'desc';
    }
    updateSortHeader();
    renderItems(state.directoryItems, 'directory');
    saveSortPreferences();
  }

  function changePlaylistSort(field) {
    if (!state.activePlaylist || !['name', 'modified', 'size'].includes(field)) return;

    if (state.playlistSort && state.playlistSort.sort_by === field) {
      state.playlistSort.sort_direction = state.playlistSort.sort_direction === 'asc' ? 'desc' : 'asc';
    } else {
      state.playlistSort = {
        sort_by: field,
        sort_direction: field === 'name' ? 'asc' : 'desc'
      };
    }

    renderPlaylist(state.activePlaylist);
  }

  function formatTime(seconds) {
    const value = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(value / 3600);
    const m = Math.floor((value % 3600) / 60);
    const s = value % 60;
    return h > 0
      ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
      : `${m}:${String(s).padStart(2, '0')}`;
  }

  function basename(path) {
    const parts = String(path || '').split('/');
    return parts[parts.length - 1] || path;
  }

  function appendProgressTrack(item) {
    const watch = item.watch || (
      item.position_seconds
        ? { position: item.position_seconds, duration: item.duration_seconds }
        : null
    );
    if (!watch || Number(watch.duration) <= 0 || Number(watch.position) <= 0) return;

    const track = document.createElement('div');
    track.className = 'progress-track';
    track.dataset.mediaPath = itemPath(item);
    const fill = document.createElement('div');
    fill.className = 'progress-fill';
    fill.style.width = `${Math.min(100, Number(watch.position) / Number(watch.duration) * 100)}%`;
    track.append(fill);
    el.list.append(track);
  }

  function updateVisibleProgress() {
    if (!state.currentItem) return;
    const path = itemPath(state.currentItem);
    const duration = Number(el.video.duration), position = Number(el.video.currentTime);
    if (!(duration > 0) || !Number.isFinite(position)) return;
    const percent = Math.min(100, Math.max(0, position / duration * 100));
    el.list.querySelectorAll('.item-row[data-media-path]').forEach((row) => {
      if (row.dataset.mediaPath !== path) return;
      let track = row.nextElementSibling;
      if (!track || !track.classList.contains('progress-track')) {
        track = document.createElement('div'); track.className = 'progress-track';
        track.dataset.mediaPath = path; row.after(track);
      }
      let fill = track.querySelector('.progress-fill');
      if (!fill) { fill = document.createElement('div'); fill.className = 'progress-fill'; track.append(fill); }
      fill.style.width = percent + '%';
    });
  }

  function enableNameDrag(element) {
    let active = false, startX = 0, startScroll = 0, moved = false;
    element.addEventListener('pointerdown', (event) => {
      if (element.scrollWidth <= element.clientWidth || (event.pointerType === 'mouse' && event.button !== 0)) return;
      active = true; moved = false; startX = event.clientX; startScroll = element.scrollLeft;
      element.setPointerCapture(event.pointerId); element.classList.add('name-dragging');
    });
    element.addEventListener('pointermove', (event) => {
      if (!active) return;
      const delta = event.clientX - startX;
      if (Math.abs(delta) > 3) moved = true;
      element.scrollLeft = startScroll - delta;
    });
    const finish = () => {
      if (!active) return;
      active = false; element.classList.remove('name-dragging');
      element.scrollTo({left:0, behavior:'smooth'});
    };
    element.addEventListener('pointerup', finish);
    element.addEventListener('pointercancel', finish);
    element.addEventListener('lostpointercapture', finish);
    element.addEventListener('click', (event) => {
      if (moved) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
  }

  function setBreadcrumbs(path) {
    el.breadcrumbs.replaceChildren();
    const parts = path ? path.split('/') : [];
    const root = document.createElement('button');
    root.type = 'button';
    root.className = 'crumb' + (parts.length === 0 ? ' crumb-current' : '');
    root.textContent = 'Видеотека';
    root.addEventListener('click', () => loadDirectory(''));
    el.breadcrumbs.append(root);

    let accumulated = '';
    parts.forEach((part, index) => {
      const separator = document.createElement('span');
      separator.className = 'crumb-separator';
      separator.textContent = '›';
      el.breadcrumbs.append(separator);

      accumulated = accumulated ? `${accumulated}/${part}` : part;
      const crumb = document.createElement('button');
      crumb.type = 'button';
      crumb.className = 'crumb' + (index === parts.length - 1 ? ' crumb-current' : '');
      crumb.textContent = part;
      const targetPath = accumulated;
      crumb.addEventListener('click', () => loadDirectory(targetPath));
      el.breadcrumbs.append(crumb);
    });
  }

  async function loadDirectory(path) {
    state.path = path;
    sessionStorage.setItem('tali_path', path);
    sessionStorage.setItem('tali_view', 'browse');
    sessionStorage.removeItem('tali_playlist');
    state.view = 'browse';
    state.activePlaylist = null;
    el.navButtons.forEach((button) => button.classList.toggle('active', button.dataset.view === 'browse'));
    el.broadcastPanel.hidden = true;
    el.listHeader.hidden = false;
    el.list.hidden = false;
    el.browseToolbar.hidden = false;
    updateListHeader('directory');
    setBreadcrumbs(path);
    setLoading(true);
    try {
      const result = await api('browse', { path });
      state.directoryItems = result.items || [];
      renderItems(state.directoryItems, 'directory');
    } catch (error) {
      renderError(error.message);
    } finally {
      el.loading.hidden = true;
    }
  }

  function renderError(message) {
    el.list.replaceChildren();
    el.empty.hidden = false;
    el.empty.textContent = message;
  }

  function updateCurrentRowHighlight() {
    const currentPath = state.currentItem && !state.playbackEnded
      ? itemPath(state.currentItem) : '';
    el.list.querySelectorAll('.item-row[data-media-path]').forEach((row) => {
      row.classList.toggle('item-row--current', Boolean(currentPath) && row.dataset.mediaPath === currentPath);
    });
  }

  function renderItems(items, mode, options = {}) {
    el.list.replaceChildren();
    el.empty.hidden = items.length > 0;
    el.empty.textContent = items.length ? '' : 'В этом разделе пока ничего нет.';

    const displayItems = mode === 'directory' ? sortDirectoryItems(items) : items;
    if (mode === 'directory') {
      state.directoryItems = items;
      // Очередь воспроизведения соответствует видимому порядку файлов.
      state.queue = displayItems.filter((item) => item.type === 'video');
      updateSortHeader();
    } else if (mode === 'history') {
      state.queue = items;
    } else if (mode === 'playlist') {
      state.queue = items;
    }

    if (state.currentItem) {
      const currentPath = itemPath(state.currentItem);
      state.queueIndex = state.queue.findIndex((entry) => itemPath(entry) === currentPath);
    }
    updateQueueControls();

    displayItems.forEach((item) => {
      const isDirectory = item.type === 'directory';
      const row = document.createElement('div');
      row.className = `item-row item-row--${mode}`;
      if (!isDirectory) {
        row.dataset.mediaPath = itemPath(item);
        if (!state.playbackEnded && state.currentItem && itemPath(state.currentItem) === itemPath(item)) {
          row.classList.add('item-row--current');
        }
      }

      const main = document.createElement('button');
      main.type = 'button';
      main.className = 'entry-main';
      const icon = document.createElement('span');
      icon.className = 'entry-icon';
      icon.textContent = isDirectory ? '▰' : '▶';
      const name = document.createElement('span');
      name.className = 'entry-name';
      name.textContent = item.name || basename(item.media_path || item.path);
      enableNameDrag(name);
      main.append(icon, name);
      main.addEventListener('click', () => {
        if (isDirectory) {
          loadDirectory(item.path);
        } else {
          const queue = mode === 'directory' ? state.queue : items;
          const selectedPath = itemPath(item);
          const queueIndex = queue.findIndex((entry) => itemPath(entry) === selectedPath);
          // Не подменяем не найденный элемент индексом строки: каталоги не входят в очередь.
          if (queueIndex >= 0) playFromQueue(queue, queueIndex);
        }
      });
      row.append(main);

      if (mode === 'directory') {
        appendDateCell(row, Number(item.modified) || 0, true);
      } else if (mode === 'history') {
        appendDateCell(row, item.last_watched_at);
      }

      const size = document.createElement('span');
      size.className = 'entry-size';
      if (mode === 'directory') {
        size.textContent = isDirectory ? '—' : formatBytes(item.size);
      } else if (item.size !== undefined && item.size !== null) {
        size.textContent = formatBytes(item.size);
      } else {
        const completed = Boolean(item.completed);
        const position = Number(item.position_seconds) || 0;
        size.textContent = completed ? '✓' : position > 0 ? formatTime(position) : '—';
        size.title = completed ? 'Просмотрено'
          : position > 0 ? 'Позиция просмотра: ' + formatTime(position)
          : 'Просмотр ещё не начат';
        size.setAttribute('aria-label', size.title);
      }
      row.append(size);

      const actions = document.createElement('div');
      actions.className = 'row-actions';
      if (!isDirectory) {
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'small-button';
        add.innerHTML = '<span class="action-label-full">+ список</span><span class="action-label-mobile">＋</span>';
        add.title = 'Добавить в плейлист';
        add.setAttribute('aria-label', 'Добавить в плейлист');
        add.addEventListener('click', (event) => {
          event.stopPropagation();
          openAddToPlaylist(item);
        });
        actions.append(add);

        if (mode === 'playlist') {
          const itemId = item.id;
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'small-button';
          remove.textContent = '×';
          remove.title = 'Убрать из плейлиста';
          remove.addEventListener('click', () => removePlaylistItem(state.activePlaylist.id, itemId));
          actions.append(remove);
        }
      }
      row.append(actions);
      el.list.append(row);

      if (!isDirectory) appendProgressTrack(item);
    });
  }

  async function loadHistory() {
    sessionStorage.setItem('tali_view', 'history');
    sessionStorage.removeItem('tali_playlist');
    state.view = 'history';
    state.activePlaylist = null;
    el.broadcastPanel.hidden = true;
    el.listHeader.hidden = false;
    el.list.hidden = false;
    el.browseToolbar.hidden = true;
    updateListHeader('history');
    setLoading(true);
    try {
      const result = await api('history', { limit: 200 });
      const items = (result.items || []).map((item) => ({
        ...item,
        name: basename(item.media_path),
        path: item.media_path,
        type: 'video'
      }));
      renderItems(items, 'history');
    } catch (error) {
      renderError(error.message);
    } finally {
      el.loading.hidden = true;
    }
  }

  async function loadPlaylists(openId = null) {
    const samePlaylist = openId !== null && state.activePlaylist &&
      Number(state.activePlaylist.id) === Number(openId);
    if (!samePlaylist) state.playlistSort = null;

    sessionStorage.setItem('tali_view', 'playlists');
    if (openId === null) sessionStorage.removeItem('tali_playlist');
    else sessionStorage.setItem('tali_playlist', String(openId));
    state.view = 'playlists';
    el.broadcastPanel.hidden = true;
    el.listHeader.hidden = false;
    el.list.hidden = false;
    el.browseToolbar.hidden = true;
    updateListHeader('playlist-index');
    setLoading(true);
    try {
      const result = await api('playlists');
      state.playlists = result.items || [];
      if (openId !== null) {
        const playlist = state.playlists.find((item) => Number(item.id) === Number(openId));
        if (playlist) {
          renderPlaylist(playlist);
          return;
        }
      }
      renderPlaylistIndex();
    } catch (error) {
      renderError(error.message);
    } finally {
      el.loading.hidden = true;
    }
  }


  function broadcastDisplayName(value) {
    let path = String(value || '');
    path = path.replace(/^file:\/\//i, '');
    try { path = decodeURIComponent(path); } catch (_) { /* оставляем исходное имя */ }
    path = path.replace(/[?#].*$/, '');
    const parts = path.split('/');
    return parts[parts.length - 1] || path || 'Неизвестный файл';
  }

  function broadcastStateLabel(value) {
    const labels = {
      playing: 'Воспроизводится',
      paused: 'Пауза',
      stopped: 'Остановлено',
      opening: 'Открывается',
      error: 'Ошибка'
    };
    return labels[String(value || '').toLowerCase()] || 'Состояние VLC не определено';
  }

  function renderBroadcast(info) {
    state.broadcastInfo = info || null;
    const inputs = info && Array.isArray(info.inputs) ? info.inputs : [];
    const current = Number(info && info.current_index) > 0
      ? Number(info.current_index) : state.broadcastSelectedIndex;
    if (current && current <= inputs.length) state.broadcastSelectedIndex = current;

    const isPlaying = String(info && info.state || '').toLowerCase() === 'playing';
    el.broadcastState.textContent = broadcastStateLabel(info && info.state);
    el.broadcastStatusDot.classList.toggle('is-live', isPlaying);
    el.broadcastStatusDot.classList.toggle('is-idle', !isPlaying);

    const flags = [];
    if (info && typeof info.enabled === 'boolean') {
      flags.push(info.enabled ? 'Канал включён' : 'Канал выключен');
    } else {
      flags.push('Канал: состояние неизвестно');
    }
    if (info && typeof info.loop === 'boolean') {
      flags.push(info.loop ? 'Повтор включён' : 'Повтор выключен');
    } else {
      flags.push('Повтор: состояние неизвестно');
    }
    el.broadcastFlags.textContent = flags.join(' · ');
    el.broadcastLoop.textContent = info && info.loop === true
      ? 'Повтор: ВКЛ' : info && info.loop === false ? 'Повтор: ВЫКЛ' : 'Повтор: —';
    el.broadcastLoop.disabled = !(info && typeof info.loop === 'boolean');
    el.broadcastEnable.textContent = info && info.enabled === true
      ? 'Выключить эфир' : info && info.enabled === false ? 'Включить эфир' : 'Эфир: —';
    el.broadcastEnable.disabled = !(info && typeof info.enabled === 'boolean');

    el.broadcastCount.textContent = inputs.length ? String(inputs.length) + ' файлов' : '';
    el.broadcastList.replaceChildren();
    if (!inputs.length) {
      const empty = document.createElement('div');
      empty.className = 'empty-message';
      empty.textContent = 'VLC не вернул список входных файлов. Ниже доступен исходный ответ show tali.';
      el.broadcastList.append(empty);
    } else {
      inputs.forEach((input, index) => {
        const number = index + 1;
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'broadcast-item';
        const selected = Number(state.broadcastSelectedIndex || current) === number;
        if (selected) row.classList.add('broadcast-item-current');

        const numberEl = document.createElement('span');
        numberEl.className = 'broadcast-item-number';
        numberEl.textContent = String(number);

        const textWrap = document.createElement('span');
        textWrap.className = 'broadcast-item-text';
        const name = document.createElement('strong');
        name.className = 'broadcast-item-name';
        name.textContent = broadcastDisplayName(input);
        const path = document.createElement('span');
        path.className = 'broadcast-item-path';
        path.textContent = String(input);
        textWrap.append(name, path);
        const play = document.createElement('span');
        play.className = 'broadcast-item-play';
        play.textContent = '▶';
        row.append(numberEl, textWrap, play);
        row.addEventListener('click', () => {
          state.broadcastSelectedIndex = number;
          sendBroadcastCommand('play', number);
        });
        el.broadcastList.append(row);
      });
    }

    el.broadcastRaw.textContent = info && info.raw ? info.raw : 'VLC не вернул текст ответа.';
    el.broadcastSummary.textContent = 'Текущий канал VLC: tali · список читается непосредственно из VLM';
    const hasInputs = inputs.length > 0;
    el.broadcastPlay.disabled = !hasInputs;
    el.broadcastPrevious.disabled = !hasInputs || Number(state.broadcastSelectedIndex || current || 1) <= 1;
    el.broadcastNext.disabled = !hasInputs ||
      Number(state.broadcastSelectedIndex || current || 1) >= inputs.length;
  }

  async function loadBroadcast() {
    sessionStorage.setItem('tali_view', 'broadcast');
    sessionStorage.removeItem('tali_playlist');
    state.view = 'broadcast';
    state.activePlaylist = null;
    el.broadcastPanel.hidden = false;
    el.browseToolbar.hidden = true;
    el.listHeader.hidden = true;
    el.list.hidden = true;
    el.empty.hidden = true;
    el.loading.hidden = true;
    await refreshBroadcast();
  }

  async function refreshBroadcast() {
    el.broadcastState.textContent = 'Запрашиваем show tali…';
    el.broadcastStatusDot.classList.remove('is-live', 'is-idle');
    try {
      const result = await api('broadcast_status');
      renderBroadcast(result.status);
    } catch (error) {
      el.broadcastState.textContent = 'Нет связи с VLC';
      el.broadcastStatusDot.classList.remove('is-live');
      el.broadcastStatusDot.classList.add('is-idle');
      el.broadcastFlags.textContent = error.message;
      el.broadcastList.replaceChildren();
      const empty = document.createElement('div');
      empty.className = 'empty-message';
      empty.textContent = error.message;
      el.broadcastList.append(empty);
      el.broadcastRaw.textContent = '';
      el.broadcastCount.textContent = '';
      el.broadcastLoop.disabled = true;
      el.broadcastEnable.disabled = true;
      el.broadcastPlay.disabled = true;
      el.broadcastPrevious.disabled = true;
      el.broadcastNext.disabled = true;
    }
  }

  async function sendBroadcastCommand(command, input = null) {
    const data = { command };
    if (input !== null) data.input = input;
    try {
      const result = await api('broadcast_command', data);
      if (result.status) renderBroadcast(result.status);
      else await refreshBroadcast();
      const messages = {
        play: 'Команда воспроизведения отправлена',
        pause: 'Пауза отправлена',
        stop: 'Остановка отправлена',
        enable: 'Канал включён',
        disable: 'Канал выключен',
        loop_on: 'Повтор включён',
        loop_off: 'Повтор выключен'
      };
      showToast(messages[command] || 'Команда отправлена');
    } catch (error) {
      showError(error.message);
    }
  }

  function moveBroadcastSelection(delta) {
    const info = state.broadcastInfo;
    const count = info && Array.isArray(info.inputs) ? info.inputs.length : 0;
    if (!count) return;
    const current = Number(state.broadcastSelectedIndex || (info && info.current_index) || 1);
    const next = Math.max(1, Math.min(count, current + delta));
    if (next === current) return;
    state.broadcastSelectedIndex = next;
    sendBroadcastCommand('play', next);
  }

  function renderPlaylistIndex() {
    sessionStorage.removeItem('tali_playlist');
    state.activePlaylist = null;
    updateListHeader('playlist-index');
    el.list.replaceChildren();
    el.empty.hidden = state.playlists.length > 0;
    el.empty.textContent = 'Плейлистов пока нет. Создай первый.';

    const createRow = document.createElement('div');
    createRow.className = 'item-row item-row--playlist';
    const createButton = document.createElement('button');
    createButton.type = 'button';
    createButton.className = 'entry-main';
    createButton.innerHTML = '<span class="entry-icon">＋</span><span class="entry-name">Создать плейлист</span>';
    createButton.addEventListener('click', createPlaylist);
    createRow.append(createButton);
    appendDateCell(createRow, null);
    const createInfo = document.createElement('span');
    createInfo.className = 'entry-size';
    createRow.append(createInfo);
    const createActions = document.createElement('div');
    createActions.className = 'row-actions';
    createRow.append(createActions);
    el.list.append(createRow);

    state.playlists.forEach((playlist) => {
      const row = document.createElement('div');
      row.className = 'item-row item-row--playlist';
      const main = document.createElement('button');
      main.type = 'button';
      main.className = 'entry-main';
      const icon = document.createElement('span');
      icon.className = 'entry-icon';
      icon.textContent = '☷';
      const name = document.createElement('span');
      name.className = 'entry-name';
      name.textContent = playlist.name;
      main.append(icon, name);
      main.addEventListener('click', () => loadPlaylists(playlist.id));
      row.append(main);
      appendDateCell(row, playlist.updated_at);

      const count = document.createElement('span');
      count.className = 'entry-size';
      const videoCount = (playlist.items || []).length;
      count.textContent = String(videoCount);
      count.title = videoCount + ' видео';
      count.setAttribute('aria-label', count.title);
      row.append(count);

      const actions = document.createElement('div');
      actions.className = 'row-actions';
      const rename = document.createElement('button');
      rename.className = 'small-button';
      rename.type = 'button';
      rename.innerHTML = '<span class="action-label-full">Имя</span><span class="action-label-mobile">✎</span>';
      rename.title = 'Переименовать';
      rename.setAttribute('aria-label', 'Переименовать');
      rename.addEventListener('click', () => renamePlaylist(playlist));
      const remove = document.createElement('button');
      remove.className = 'small-button';
      remove.type = 'button';
      remove.textContent = '×';
      remove.title = 'Удалить плейлист';
      remove.addEventListener('click', () => deletePlaylist(playlist));
      actions.append(rename, remove);
      row.append(actions);
      el.list.append(row);
    });
  }

  function renderPlaylist(playlist) {
    state.activePlaylist = playlist;

    // Ручной порядок берём из базы, а displayItems — из текущей сортировки интерфейса.
    const manualItems = (playlist.items || []).map((item) => ({
      ...item,
      path: item.media_path,
      name: basename(item.media_path),
      type: 'video'
    }));
    const displayItems = sortPlaylistItems(manualItems);

    updateListHeader('playlist');
    el.list.replaceChildren();
    el.empty.hidden = (playlist.items || []).length > 0;
    el.empty.textContent = 'В этом плейлисте пока нет видео.';

    const heading = document.createElement('div');
    heading.className = 'item-row item-row--playlist';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'entry-main';
    back.innerHTML = '<span class="entry-icon">‹</span><span class="entry-name">Все плейлисты</span>';
    back.addEventListener('click', renderPlaylistIndex);
    const headingMain = document.createElement('div');
    headingMain.className = 'playlist-heading-main';
    headingMain.append(back);
    const playlistName = document.createElement('strong');
    playlistName.className = 'playlist-heading-name';
    playlistName.textContent = playlist.name;
    headingMain.append(playlistName);
    heading.append(headingMain);
    appendDateCell(heading, null);
    const headingInfo = document.createElement('span');
    headingInfo.className = 'entry-size';
    heading.append(headingInfo);
    const headingActions = document.createElement('div');
    headingActions.className = 'row-actions';
    if ((playlist.items || []).length > 0) {
      const broadcast = document.createElement('button');
      broadcast.type = 'button';
      broadcast.className = 'primary-button';
      broadcast.textContent = 'В эфир';
      broadcast.title = 'Отправить этот плейлист в общий эфир';
      broadcast.addEventListener('click', () => sendPlaylistToStream(playlist, broadcast, displayItems));
      headingActions.append(broadcast);
    }
    heading.append(headingActions);
    el.list.append(heading);

    state.queue = displayItems;
    if (state.currentItem) {
      const currentPath = itemPath(state.currentItem);
      state.queueIndex = state.queue.findIndex((entry) => itemPath(entry) === currentPath);
    }
    updateQueueControls();

    displayItems.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'item-row item-row--playlist';
      row.dataset.mediaPath = itemPath(item);
      if (!state.playbackEnded && state.currentItem && itemPath(state.currentItem) === itemPath(item)) {
        row.classList.add('item-row--current');
      }

      const main = document.createElement('button');
      main.type = 'button';
      main.className = 'entry-main';
      const icon = document.createElement('span');
      icon.className = 'entry-icon';
      icon.textContent = '▶';
      const name = document.createElement('span');
      name.className = 'entry-name';
      name.textContent = item.name;
      enableNameDrag(name);
      main.append(icon, name);
      main.addEventListener('click', () => playFromQueue(displayItems, index));
      row.append(main);
      appendDateCell(row, Number(item.modified) || 0, true);

      const itemSize = document.createElement('span');
      itemSize.className = 'entry-size';
      itemSize.textContent = item.size !== null && item.size !== undefined
        ? formatBytes(item.size) : '—';
      row.append(itemSize);

      const actions = document.createElement('div');
      actions.className = 'row-actions';

      // Перемещение вверх/вниз доступно только в сохранённом ручном порядке.
      if (!state.playlistSort) {
        const up = document.createElement('button');
        up.className = 'small-button';
        up.type = 'button';
        up.textContent = '↑';
        up.title = 'Выше';
        if (index > 0) {
          up.addEventListener('click', () => reorderPlaylist(playlist, manualItems, index, index - 1));
        } else {
          up.disabled = true;
          up.style.visibility = 'hidden';
          up.setAttribute('aria-hidden', 'true');
        }
        actions.append(up);

        const down = document.createElement('button');
        down.className = 'small-button';
        down.type = 'button';
        down.textContent = '↓';
        down.title = 'Ниже';
        if (index < manualItems.length - 1) {
          down.addEventListener('click', () => reorderPlaylist(playlist, manualItems, index, index + 1));
        } else {
          down.disabled = true;
          down.style.visibility = 'hidden';
          down.setAttribute('aria-hidden', 'true');
        }
        actions.append(down);
      }

      const remove = document.createElement('button');
      remove.className = 'small-button';
      remove.type = 'button';
      remove.textContent = '×';
      remove.title = 'Убрать из плейлиста';
      remove.addEventListener('click', () => removePlaylistItem(playlist.id, item.id));
      actions.append(remove);

      row.append(actions);
      el.list.append(row);
      appendProgressTrack(item);
    });
  }

  async function sendPlaylistToStream(playlist, button, orderedItems) {
    if (!playlist || !Array.isArray(playlist.items) || !playlist.items.length) {
      showToast('Плейлист пуст');
      return;
    }

    const itemIds = Array.isArray(orderedItems)
      ? orderedItems.map((item) => Number(item.id))
      : [];
    if (itemIds.length !== playlist.items.length ||
        itemIds.some((id) => !Number.isSafeInteger(id) || id < 1)) {
      showError('Не удалось определить текущий порядок элементов плейлиста');
      return;
    }

    if (button.disabled) return;
    const oldLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Отправляем…';
    try {
      await api('playlist_broadcast', {
        playlist_id: playlist.id,
        item_ids: itemIds
      });
      showToast('Плейлист «' + playlist.name + '» отправлен в эфир');
    } catch (error) {
      showError(error.message);
    } finally {
      button.disabled = false;
      button.textContent = oldLabel;
    }
  }

  function normalizeQueueItem(item) {
    const path = item.path || item.media_path;
    return {
      ...item,
      path,
      name: item.name || basename(path),
      url: item.url || mediaUrl(path),
      type: 'video'
    };
  }

  function mediaUrl(path) {
    return '/video/' + String(path).split('/').map((part) => encodeURIComponent(part)).join('/');
  }

  function playFromQueue(queue, index) {
    if (!Array.isArray(queue) || !queue.length || index < 0 || index >= queue.length) return;
    state.queue = queue.map(normalizeQueueItem);
    state.queueIndex = index;
    playCurrent();
  }

  function playCurrent() {
    const item = state.queue[state.queueIndex];
    if (!item) return;
    saveCurrentPosition(false);
    if (!el.video.paused) {
      state.ignoreNextPauseSave = true;
      el.video.pause();
    }
    state.currentItem = item;
    state.playbackEnded = false;
    updateCurrentRowHighlight();
    state.lastSaveAt = 0;
    el.playerPanel.hidden = false;
    el.nowPlaying.textContent = item.name;
    el.nowPlaying.scrollLeft = 0;
    el.resumeLabel.hidden = true;
    el.restart.hidden = true;
    el.download.href = item.url;
    el.download.download = basename(item.path);
    el.download.hidden = false;
    updatePlaybackControls();

    el.video.src = item.url;
    el.video.load();
    const resumePosition = Number(item.watch?.position ?? item.position_seconds ?? 0);
    const durationHint = Number(item.watch?.duration ?? item.duration_seconds ?? 0);

    const onMetadata = () => {
      el.video.removeEventListener('loadedmetadata', onMetadata);
      const actualDuration = Number.isFinite(el.video.duration) ? el.video.duration : durationHint;
      if (!item.completed && resumePosition > 15 && (!actualDuration || resumePosition < actualDuration - 10)) {
        el.video.currentTime = Math.min(resumePosition, actualDuration > 0 ? actualDuration - 1 : resumePosition);
        el.resumeLabel.textContent = `Продолжение с ${formatTime(resumePosition)} · можно начать сначала`;
        el.resumeLabel.hidden = false;
        el.restart.hidden = false;
      }
      updatePlaybackControls();
    };
    el.video.addEventListener('loadedmetadata', onMetadata);
    const playPromise = el.video.play();
    if (playPromise && typeof playPromise.catch === 'function') playPromise.catch(() => {});
  }

  function stopPlayer() {
    try { el.video.pause(); } catch (_) { /* ignore */ }
    el.video.removeAttribute('src');
    el.video.load();
    el.download.removeAttribute('href');
    el.download.removeAttribute('download');
    el.download.hidden = true;
    state.currentItem = null;
    state.playbackEnded = false;
    updateCurrentRowHighlight();
    state.queue = [];
    state.queueIndex = -1;
    el.playerPanel.hidden = true;
  }

  async function saveCurrentPosition(showFeedback = false) {
    if (!state.user || !state.currentItem || !state.currentItem.path) return;
    const position = Number(el.video.currentTime);
    const duration = Number(el.video.duration);
    if (!Number.isFinite(position) || position < 0) return;

    const payload = {
      path: state.currentItem.path,
      position,
      duration: Number.isFinite(duration) && duration > 0 ? duration : 0
    };
    state.lastSaveAt = Date.now();
    const save = state.saveChain.catch(() => {}).then(() => api('save_position', payload));
    state.saveChain = save;
    try {
      await save;
      if (showFeedback) showToast('Позиция просмотра сохранена');
    } catch (error) {
      if (showFeedback) showError(error.message);
    }
  }

  function savePositionBeacon() {
    if (!state.user || !state.currentItem || !state.currentItem.path) return;
    const position = Number(el.video.currentTime);
    const duration = Number(el.video.duration);
    if (!Number.isFinite(position) || position < 0) return;
    const body = new Blob([JSON.stringify({
      action: 'save_position',
      path: state.currentItem.path,
      position,
      duration: Number.isFinite(duration) && duration > 0 ? duration : 0
    })], { type: 'application/json' });
    navigator.sendBeacon(`${API}?action=save_position`, body);
  }

  async function createPlaylist() {
    const name = window.prompt('Название нового плейлиста:');
    if (!name || !name.trim()) return;
    try {
      await api('playlist_create', { name: name.trim() });
      await loadPlaylists();
      showToast('Плейлист создан');
    } catch (error) {
      showError(error.message);
    }
  }

  async function renamePlaylist(playlist) {
    const name = window.prompt('Новое название плейлиста:', playlist.name);
    if (!name || !name.trim() || name.trim() === playlist.name) return;
    try {
      await api('playlist_rename', { playlist_id: playlist.id, name: name.trim() });
      await loadPlaylists();
      showToast('Название изменено');
    } catch (error) {
      showError(error.message);
    }
  }

  async function deletePlaylist(playlist) {
    if (!window.confirm(`Удалить плейлист «${playlist.name}»?`)) return;
    try {
      await api('playlist_delete', { playlist_id: playlist.id });
      await loadPlaylists();
      showToast('Плейлист удалён');
    } catch (error) {
      showError(error.message);
    }
  }

  async function openAddToPlaylist(item) {
    state.pendingPlaylistBatch = null;
    state.pendingPlaylistItem = normalizeQueueItem(item);
    el.playlistDialogFile.textContent = state.pendingPlaylistItem.name;
    el.playlistDialogError.textContent = '';
    try {
      const result = await api('playlists');
      state.playlists = result.items || [];
      if (!state.playlists.length) {
        const create = window.confirm('Плейлистов пока нет. Создать новый?');
        if (create) await createPlaylist();
        const refreshed = await api('playlists');
        state.playlists = refreshed.items || [];
      }
      if (!state.playlists.length) return;
      el.playlistSelect.replaceChildren();
      state.playlists.forEach((playlist) => {
        const option = document.createElement('option');
        option.value = String(playlist.id);
        option.textContent = playlist.name;
        el.playlistSelect.append(option);
      });
      if (typeof el.playlistDialog.showModal === 'function') el.playlistDialog.showModal();
      else await addToSelectedPlaylist();
    } catch (error) {
      showError(error.message);
    }
  }

  async function openDirectoryAddToPlaylist() {
    const items = sortDirectoryItems(state.directoryItems)
      .filter((item) => item.type === 'video')
      .map(normalizeQueueItem);
    if (!items.length) {
      showToast('В этом каталоге нет видеофайлов');
      return;
    }

    state.pendingPlaylistItem = null;
    state.pendingPlaylistBatch = items;
    el.playlistDialogFile.textContent = 'Все видеофайлы из каталога · ' + items.length;
    el.playlistDialogError.textContent = '';
    try {
      const result = await api('playlists');
      state.playlists = result.items || [];
      if (!state.playlists.length) {
        const create = window.confirm('Плейлистов пока нет. Создать новый?');
        if (create) await createPlaylist();
        const refreshed = await api('playlists');
        state.playlists = refreshed.items || [];
      }
      if (!state.playlists.length) return;
      el.playlistSelect.replaceChildren();
      state.playlists.forEach((playlist) => {
        const option = document.createElement('option');
        option.value = String(playlist.id);
        option.textContent = playlist.name;
        el.playlistSelect.append(option);
      });
      if (typeof el.playlistDialog.showModal === 'function') el.playlistDialog.showModal();
      else await addToSelectedPlaylist();
    } catch (error) {
      showError(error.message);
    }
  }

  async function addToSelectedPlaylist() {
    if (state.pendingPlaylistBatch) {
      const playlistId = Number(el.playlistSelect.value);
      if (!playlistId) {
        el.playlistDialogError.textContent = 'Сначала создай плейлист.';
        return;
      }
      try {
        for (const item of state.pendingPlaylistBatch) {
          await api('playlist_add', { playlist_id: playlistId, path: item.path });
        }
        const count = state.pendingPlaylistBatch.length;
        state.pendingPlaylistBatch = null;
        if (el.playlistDialog.open) el.playlistDialog.close();
        showToast('Добавлено в плейлист: ' + count + ' видео');
        if (state.view === 'playlists') await loadPlaylists();
      } catch (error) {
        el.playlistDialogError.textContent = error.message;
      }
      return;
    }
    if (!state.pendingPlaylistItem) return;
    const playlistId = Number(el.playlistSelect.value);
    if (!playlistId) {
      el.playlistDialogError.textContent = 'Сначала создай плейлист.';
      return;
    }
    try {
      await api('playlist_add', { playlist_id: playlistId, path: state.pendingPlaylistItem.path });
      if (el.playlistDialog.open) el.playlistDialog.close();
      showToast('Добавлено в плейлист');
      if (state.view === 'playlists') await loadPlaylists();
    } catch (error) {
      el.playlistDialogError.textContent = error.message;
    }
  }

  async function removePlaylistItem(playlistId, itemId) {
    try {
      await api('playlist_remove', { playlist_id: playlistId, item_id: itemId });
      await loadPlaylists(playlistId);
      showToast('Видео убрано из плейлиста');
    } catch (error) {
      showError(error.message);
    }
  }

  async function reorderPlaylist(playlist, items, from, to) {
    const reordered = [...items];
    const [moved] = reordered.splice(from, 1);
    reordered.splice(to, 0, moved);
    try {
      await api('playlist_reorder', {
        playlist_id: playlist.id,
        item_ids: reordered.map((item) => item.id)
      });
      await loadPlaylists(playlist.id);
    } catch (error) {
      showError(error.message);
    }
  }

  function isStageFullscreen() {
    return document.fullscreenElement === el.stage ||
      document.webkitFullscreenElement === el.stage;
  }

  function applyFullscreenFallbackRotation() {
    if (!isStageFullscreen()) return;
    const isMobile = window.matchMedia('(max-width: 760px)').matches;
    const orientation = window.screen && window.screen.orientation;
    const isPortrait = orientation && typeof orientation.type === 'string'
      ? orientation.type.startsWith('portrait')
      : window.innerHeight > window.innerWidth;
    const isLandscapeVideo = Number(el.video.videoWidth) > Number(el.video.videoHeight);

    state.rotation = isMobile && isPortrait && isLandscapeVideo ? 90 : 0;
    updateVideoRotation();
  }

  async function enterFullscreenOrientation(allowOrientationLock) {
    if (state.fullscreenActive) return;
    state.fullscreenActive = true;
    state.rotation = 0;
    updateVideoRotation();

    const isMobile = window.matchMedia('(max-width: 760px)').matches;
    const isLandscapeVideo = Number(el.video.videoWidth) > Number(el.video.videoHeight);
    const orientation = window.screen && window.screen.orientation;

    if (allowOrientationLock && isMobile && isLandscapeVideo &&
        orientation && typeof orientation.lock === 'function') {
      try {
        await orientation.lock('landscape');
        if (!isStageFullscreen()) {
          try { orientation.unlock(); } catch (_) { /* ignore late unlock */ }
          state.orientationLocked = false;
          state.fullscreenActive = false;
          state.rotation = 0;
          updateVideoRotation();
          return;
        }
        state.orientationLocked = true;
        state.rotation = 0;
        updateVideoRotation();
        return;
      } catch (_) {
        // Браузер не разрешил блокировку: повернём само видео средствами CSS.
      }
    }

    state.orientationLocked = false;
    applyFullscreenFallbackRotation();
  }

  function exitFullscreenOrientation() {
    const orientation = window.screen && window.screen.orientation;
    if (state.orientationLocked && orientation && typeof orientation.unlock === 'function') {
      try { orientation.unlock(); } catch (_) { /* ignore unsupported unlock */ }
    }
    state.orientationLocked = false;
    state.fullscreenActive = false;
    state.rotation = 0;
    updateVideoRotation();
  }

  function syncFullscreenState() {
    if (isStageFullscreen()) {
      if (!state.fullscreenActive && !state.fullscreenEntryPending) {
        enterFullscreenOrientation(false);
      }
    } else if (state.fullscreenActive) {
      exitFullscreenOrientation();
    }
    updatePlaybackControls();
  }

  function handleFullscreenViewportChange() {
    if (isStageFullscreen() && state.fullscreenActive && !state.orientationLocked) {
      applyFullscreenFallbackRotation();
    } else {
      updateVideoRotation();
    }
  }

  async function requestVideoFullscreen() {
    try {
      if (isStageFullscreen()) {
        if (document.exitFullscreen) {
          await document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
          document.webkitExitFullscreen();
        }
        return;
      }

      // iOS Safari открывает видео в собственном полноэкранном проигрывателе.
      if (el.video.webkitDisplayingFullscreen && el.video.webkitExitFullscreen) {
        el.video.webkitExitFullscreen();
        return;
      }

      if (el.stage.requestFullscreen) {
        state.fullscreenEntryPending = true;
        await el.stage.requestFullscreen();
        await enterFullscreenOrientation(true);
      } else if (el.stage.webkitRequestFullscreen) {
        state.fullscreenEntryPending = true;
        el.stage.webkitRequestFullscreen();
        await enterFullscreenOrientation(false);
      } else if (el.video.webkitEnterFullscreen) {
        el.video.controls = true;
        el.video.webkitEnterFullscreen();
      } else {
        showError('Полноэкранный режим недоступен в этом браузере');
      }
    } catch (_) {
      el.video.controls = false;
      showError('Полноэкранный режим недоступен');
    } finally {
      state.fullscreenEntryPending = false;
      if (!isStageFullscreen() && state.fullscreenActive) {
        exitFullscreenOrientation();
      }
    }
  }

  function bindEvents() {
    el.loginForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      el.loginError.textContent = '';
      const nickname = el.nickname.value.trim();
      if (!nickname) {
        el.loginError.textContent = 'Введи имя или позывной.';
        return;
      }
      const submit = el.loginForm.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await api('login', { nickname, remember: el.remember.checked });
        state.user = result.user;
        state.preferences = normalizePreferences(result.preferences);
        showApp();
        await restoreSavedView();
        showToast(`Привет, ${state.user.nickname}!`);
      } catch (error) {
        el.loginError.textContent = error.message;
      } finally {
        submit.disabled = false;
      }
    });

    el.logout.addEventListener('click', async () => {
      try { await api('logout'); } catch (_) { /* cookie may already be gone */ }
      sessionStorage.removeItem('tali_path');
      sessionStorage.removeItem('tali_view');
      sessionStorage.removeItem('tali_playlist');
      state.path = '';
      showLogin();
    });

    el.theme.addEventListener('click', () => {
      setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });

    document.querySelectorAll('.sort-button').forEach((button) => {
      button.addEventListener('click', () => {
        if (state.view === 'browse') {
          changeSort(button.dataset.sort);
        } else if (state.view === 'playlists' && state.activePlaylist) {
          changePlaylistSort(button.dataset.sort);
        }
      });
    });

    el.navButtons.forEach((button) => {
      button.addEventListener('click', () => setView(button.dataset.view));
    });

    el.broadcastRefresh.addEventListener('click', refreshBroadcast);
    el.broadcastPlay.addEventListener('click', () => {
      const index = Number(state.broadcastSelectedIndex ||
        (state.broadcastInfo && state.broadcastInfo.current_index) || 1);
      sendBroadcastCommand('play', index);
    });
    el.broadcastPause.addEventListener('click', () => sendBroadcastCommand('pause'));
    el.broadcastStop.addEventListener('click', () => sendBroadcastCommand('stop'));
    el.broadcastPrevious.addEventListener('click', () => moveBroadcastSelection(-1));
    el.broadcastNext.addEventListener('click', () => moveBroadcastSelection(1));
    el.broadcastLoop.addEventListener('click', () => {
      if (!state.broadcastInfo || typeof state.broadcastInfo.loop !== 'boolean') return;
      sendBroadcastCommand(state.broadcastInfo.loop ? 'loop_off' : 'loop_on');
    });
    el.broadcastEnable.addEventListener('click', () => {
      if (!state.broadcastInfo || typeof state.broadcastInfo.enabled !== 'boolean') return;
      sendBroadcastCommand(state.broadcastInfo.enabled ? 'disable' : 'enable');
    });

    el.previous.addEventListener('click', () => {
      if (state.queueIndex > 0) {
        state.queueIndex -= 1;
        playCurrent();
      }
    });

    el.next.addEventListener('click', () => {
      if (state.queueIndex >= 0 && state.queueIndex < state.queue.length - 1) {
        state.queueIndex += 1;
        playCurrent();
      }
    });

    el.restart.addEventListener('click', () => {
      el.video.currentTime = 0;
      el.video.play().catch(() => {});
      el.resumeLabel.hidden = true;
      el.restart.hidden = true;
      saveCurrentPosition(false);
    });

    el.playPause.addEventListener('click', () => {
      if (el.video.paused) el.video.play().catch(() => {});
      else el.video.pause();
    });
    el.seek.addEventListener('input', () => {
      const duration = Number(el.video.duration);
      if (!Number.isFinite(duration) || duration <= 0) return;
      el.video.currentTime = duration * (Number(el.seek.value) / 100);
      updatePlaybackControls();
    });
    el.mute.addEventListener('click', () => {
      if (el.video.muted || el.video.volume === 0) {
        el.video.muted = false;
        if (el.video.volume === 0) el.video.volume = 0.5;
      } else {
        el.video.muted = true;
      }
      updatePlaybackControls();
    });
    el.volume.addEventListener('input', () => {
      el.video.volume = Number(el.volume.value);
      el.video.muted = false;
      updatePlaybackControls();
    });
    el.fullscreen.addEventListener('click', requestVideoFullscreen);
    el.rotate.addEventListener('click', rotateVideo);
    window.addEventListener('resize', handleFullscreenViewportChange);
    if (window.screen && window.screen.orientation &&
        typeof window.screen.orientation.addEventListener === 'function') {
      window.screen.orientation.addEventListener('change', handleFullscreenViewportChange);
    }

    enableNameDrag(el.nowPlaying);
    el.video.addEventListener('loadedmetadata', () => {
      updatePlaybackControls();
      if (isStageFullscreen() && state.fullscreenActive && !state.orientationLocked) {
        applyFullscreenFallbackRotation();
      }
    });
    el.video.addEventListener('durationchange', updatePlaybackControls);
    el.video.addEventListener('play', () => {
      state.playbackEnded = false;
      updateCurrentRowHighlight();
      updatePlaybackControls();
    });
    el.video.addEventListener('volumechange', updatePlaybackControls);
    el.video.addEventListener('timeupdate', () => {
      updatePlaybackControls();
      updateVisibleProgress();
      if (!state.currentItem) return;
      if (Date.now() - state.lastSaveAt >= 15000) saveCurrentPosition(false);
    });
    el.video.addEventListener('pause', () => {
      updatePlaybackControls();
      if (state.ignoreNextPauseSave) {
        state.ignoreNextPauseSave = false;
        return;
      }
      saveCurrentPosition(false);
    });
    el.video.addEventListener('webkitbeginfullscreen', () => {
      state.nativeFullscreenActive = true;
      state.rotation = 0;
      updateVideoRotation();
      updatePlaybackControls();
    });
    el.video.addEventListener('webkitendfullscreen', () => {
      el.video.controls = false;
      state.nativeFullscreenActive = false;
      state.rotation = 0;
      updateVideoRotation();
      updatePlaybackControls();
    });
    document.addEventListener('fullscreenchange', syncFullscreenState);
    document.addEventListener('webkitfullscreenchange', syncFullscreenState);
    el.video.addEventListener('ended', async () => {
      await saveCurrentPosition(false);
      if (state.queueIndex >= 0 && state.queueIndex < state.queue.length - 1) {
        state.queueIndex += 1;
        playCurrent();
      } else {
        state.playbackEnded = true;
        updateCurrentRowHighlight();
      }
    });

    el.playlistDialogForm.addEventListener('submit', (event) => {
      event.preventDefault();
      addToSelectedPlaylist();
    });
    el.closePlaylistDialog.addEventListener('click', () => el.playlistDialog.close());
    el.createPlaylistFromDialog.addEventListener('click', async () => {
      await createPlaylist();
      const result = await api('playlists');
      state.playlists = result.items || [];
      el.playlistSelect.replaceChildren();
      state.playlists.forEach((playlist) => {
        const option = document.createElement('option');
        option.value = String(playlist.id);
        option.textContent = playlist.name;
        el.playlistSelect.append(option);
      });
    });

    document.addEventListener('click', () => renewOnLocalAction());
    document.addEventListener('keydown', (event) => {
      if (!state.user || event.repeat) return;
      const target = event.target;
      if (target && ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes(target.tagName)) return;
      if (!state.currentItem) return;
      if (event.code === 'Space') {
        event.preventDefault();
        if (el.video.paused) el.video.play().catch(() => {});
        else el.video.pause();
      } else if (event.code === 'ArrowRight') {
        event.preventDefault(); el.video.currentTime = Math.min(el.video.duration || Infinity, el.video.currentTime + 10);
      } else if (event.code === 'ArrowLeft') {
        event.preventDefault(); el.video.currentTime = Math.max(0, el.video.currentTime - 10);
      } else if (event.code === 'ArrowUp') {
        event.preventDefault(); el.video.volume = Math.min(1, el.video.volume + .05);
      } else if (event.code === 'ArrowDown') {
        event.preventDefault(); el.video.volume = Math.max(0, el.video.volume - .05);
      } else if (event.code === 'KeyF') {
        event.preventDefault(); requestVideoFullscreen();
      }
    });
    window.addEventListener('pagehide', savePositionBeacon);
  }

  function renewOnLocalAction() {
    if (!state.user || Date.now() - state.lastTouchAt < 5000) return;
    state.lastTouchAt = Date.now();
    api('touch').catch(() => {});
  }

  async function restoreSavedView() {
    const savedView = sessionStorage.getItem('tali_view');
    if (savedView === 'history') {
      await loadHistory();
    } else if (savedView === 'broadcast') {
      await loadBroadcast();
    } else if (savedView === 'playlists') {
      const savedPlaylist = sessionStorage.getItem('tali_playlist');
      const playlistId = savedPlaylist && /^\d+$/.test(savedPlaylist)
        ? Number(savedPlaylist) : null;
      await loadPlaylists(playlistId);
    } else {
      await loadDirectory(state.path);
    }
  }

  async function initialize() {
    initTheme();
    bindEvents();
    try {
      const result = await api('me', {}, 'GET');
      state.user = result.user;
      state.preferences = normalizePreferences(result.preferences);
      showApp();
      await restoreSavedView();
    } catch (_) {
      showLogin();
    }
  }

  initialize();
})();
