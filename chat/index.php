<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
$user=chat_current_user();
$csrf=chat_csrf_token();
$base=chat_base_url();
$communicationProtocol=chat_communication_protocol();
$homeUrl=trim((string)chat_cfg('app','home_url',''));
$homeParts=$homeUrl!==''?parse_url($homeUrl):false;
if(!is_array($homeParts)||!filter_var($homeUrl,FILTER_VALIDATE_URL)||!in_array(strtolower((string)($homeParts['scheme']??'')),['http','https'],true)||empty($homeParts['host']))$homeUrl='';
$homeTooltip=$homeUrl!==''?'Перейти на '.(string)parse_url($homeUrl,PHP_URL_HOST):'';
$lightPalette = [
    'background'=>['--bg','#f4f4f4'],
    'panel_background'=>['--panel','#ffffff'],
    'text_color'=>['--text','#222222'],
    'link_color'=>['--link','#000080'],
    'border_color'=>['--border','#999999'],
    'muted_color'=>['--muted','#666666'],
    'notify_message_background'=>['--notify-message-bg','#fff2a8'],
    'accordion_active_background'=>['--accordion-active-bg','#d7e6f5'],
];
$darkPalette = [
    'dark_background'=>['--dark-bg','#20242a'],
    'dark_panel_background'=>['--dark-panel','#292f36'],
    'dark_text_color'=>['--dark-text','#d8dee7'],
    'dark_link_color'=>['--dark-link','#a8c7ff'],
    'dark_border_color'=>['--dark-border','#4b545f'],
    'dark_muted_color'=>['--dark-muted','#a3acb8'],
    'dark_notify_message_background'=>['--dark-notify-message-bg','#474126'],
    'dark_topbar_background'=>['--dark-topbar-bg','#303740'],
    'dark_button_background'=>['--dark-button-bg','#383f48'],
    'dark_button_text_color'=>['--dark-button-text','#e0e5ec'],
    'dark_button_hover_background'=>['--dark-button-hover-bg','#454e58'],
    'dark_input_background'=>['--dark-input-bg','#20252b'],
    'dark_input_text_color'=>['--dark-input-text','#e1e6ed'],
    'dark_composer_background'=>['--dark-composer-bg','#22282f'],
    'dark_composer_text_color'=>['--dark-composer-text','#e1e6ed'],
    'dark_toolbar_background'=>['--dark-toolbar-bg','#2c3239'],
    'dark_system_background'=>['--dark-system-bg','#3b3523'],
    'dark_system_border_color'=>['--dark-system-border','#63572b'],
    'dark_pinned_background'=>['--dark-pinned-bg','#2d302d'],
    'dark_pinned_hover_background'=>['--dark-pinned-hover-bg','#3c4137'],
    'dark_admin_action_background'=>['--dark-admin-action-bg','#453235'],
    'dark_admin_action_text_color'=>['--dark-admin-action-text','#ffadad'],
    'dark_quote_background'=>['--dark-quote-bg','#343b43'],
    'dark_date_edge_background'=>['--dark-date-edge-bg','#242a30'],
    'dark_composer_token_background'=>['--dark-composer-token-bg','#39414a'],
    'dark_mention_color'=>['--dark-mention-color','#a0dda3'],
    'dark_jump_background'=>['--dark-jump-bg','#4c4428'],
    'dark_jump_border_color'=>['--dark-jump-border','#b29a43'],
    'dark_statusbar_background'=>['--dark-statusbar-bg','#2e343b'],
    'dark_status_text_color'=>['--dark-status-text','#d0d6de'],
    'dark_success_color'=>['--dark-success-color','#91d6a0'],
    'dark_error_color'=>['--dark-error-color','#ff9999'],
    'dark_blocked_background'=>['--dark-blocked-bg','#442d31'],
    'dark_subtle_border_color'=>['--dark-subtle-border','#3e454d'],
    'dark_search_match_background'=>['--dark-search-match-bg','#63572d'],
    'dark_search_match_text_color'=>['--dark-search-match-text','#fff2bf'],
    'dark_notify_message_border_color'=>['--dark-notify-message-border','#bda648'],
    'dark_online_color'=>['--dark-online-color','#72d58f'],
    'dark_offline_color'=>['--dark-offline-color','#a3acb8'],
    'dark_danger_color'=>['--dark-danger-color','#ff9999'],
    'dark_status_success_color'=>['--dark-status-success-color','#91d6a0'],
    'dark_status_info_color'=>['--dark-status-info-color','#d0d6de'],
    'dark_tag_background'=>['--dark-tag-bg','#383f48'],
    'dark_tag_border_color'=>['--dark-tag-border','#515b66'],
    'dark_message_time_color'=>['--dark-message-time-color','#aab3bf'],
    'dark_journal_head_color'=>['--dark-journal-head-color','#bac3ce'],
    'dark_private_detail_color'=>['--dark-private-detail-color','#aab4c0'],
    'dark_attachment_background'=>['--dark-attachment-bg','#303740'],
    'dark_hover_background'=>['--dark-hover-bg','#3c4137'],
    'dark_accordion_active_background'=>['--dark-accordion-active-bg','#3b4d60'],
];
$cssVars=[];
foreach($lightPalette as $key=>[$var,$default]) {
    $value=(string)chat_cfg('ui',$key,$default);
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$value))$value=$default;
    $cssVars[$var]=$value;
}
foreach($darkPalette as $key=>[$var,$default]) {
    $value=(string)chat_cfg('ui',$key,$default);
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$value))$value=$default;
    $cssVars[$var]=$value;
}
$themeStyle=':root{';
foreach($cssVars as $var=>$value)$themeStyle.=$var.':'.$value.';';
$themeStyle.='}body.theme-dark{--bg:var(--dark-bg);--panel:var(--dark-panel);--text:var(--dark-text);--link:var(--dark-link);--border:var(--dark-border);--muted:var(--dark-muted);--notify-message-bg:var(--dark-notify-message-bg);}';
$darkTheme = $user && !empty($user['dark_theme']);
?><!doctype html>
<html lang="ru" class="<?= $darkTheme ? 'theme-dark' : '' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content"><meta name="csrf-token" content="<?=chat_e($csrf)?>"><meta name="chat-base" content="<?=chat_e($base)?>"><meta name="chat-protocol" content="<?= $communicationProtocol ?>"><title>Чат</title><link rel="stylesheet" href="assets/chat.css?v=1.2.0"><style><?= $themeStyle ?></style></head>
<body class="<?= $user ? 'chat-page'.($darkTheme ? ' theme-dark' : '') : 'auth-page' ?>">
<?php if(!$user): ?>
<main class="auth-shell"><header class="site-title"><h1>Чат</h1><span>Простой веб-чат</span></header>
<div class="auth-grid">
<section class="auth-box"><h2>Вход</h2><form id="login-form"><label>Email<input type="email" name="email" autocomplete="username" required maxlength="254"></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Войти</button><p id="login-result" class="form-result" aria-live="polite"></p></form><details><summary>Забыл пароль</summary><form id="reset-form"><label>Email<input type="email" name="email" required maxlength="254"></label><button type="submit">Выслать новый пароль</button><p id="reset-result" class="form-result" aria-live="polite"></p></form></details></section>
<section class="auth-box"><h2>Регистрация</h2><form id="register-form"><label>Email<input type="email" name="email" autocomplete="email" required maxlength="254"></label><label>Ник или Позывной при наличии<input type="text" name="nick" autocomplete="nickname" required minlength="2" maxlength="32" pattern="[^\s]+" title="Без пробелов, от 2 до 32 символов"></label><p class="hint">Пароль будет создан автоматически и отправлен по email. Учётная запись активируется после перехода по ссылке в письме.</p><button type="submit">Зарегистрироваться</button><p id="register-result" class="form-result" aria-live="polite"></p></form><details class="resend-details"><summary>Не пришло письмо подтверждения?</summary><form id="resend-form"><label>Email<input type="email" name="email" required maxlength="254"></label><button type="submit">Повторить отправку</button><p id="resend-result" class="form-result"></p></form></details></section>
</div><footer class="site-footer">Все даты и время чата формируются сервером.</footer></main>
<?php else: ?>
<div class="app-shell" id="chat-app" data-communication-protocol="<?= $communicationProtocol ?>" data-user-id="<?= (int)$user['id'] ?>" data-admin="<?= $user['is_admin']?'1':'0' ?>" data-ban-mode="<?=chat_e((string)$user['ban_mode'])?>" data-poll-seconds="<?=max(1,chat_cfg_int('app','poll_seconds',2))?>" data-presence-seconds="<?=max(15,chat_cfg_int('app','presence_seconds',30))?>" data-quote-max-chars="<?=max(10,chat_cfg_int('limits','quote_max_chars',80))?>" data-server-epoch="<?=time()?>" data-timezone="<?=chat_e((string)chat_cfg('app','timezone','UTC'))?>" data-version="1.3.0" data-message-interval-seconds="<?=max(1,chat_cfg_int('limits','message_interval_seconds',5))?>">
<header class="topbar"><span class="brand" id="mode-title">Чат</span><form id="search-form" class="search-controls" role="search"><input type="text" id="search-query" name="q" maxlength="128" autocomplete="off" placeholder="Поиск по истории…" aria-label="Поиск"><button type="button" id="search-clear" class="search-clear hidden" aria-label="Очистить поиск" title="Очистить поиск">×</button><button type="submit" id="search-button" aria-label="Найти" title="Найти"><svg class="mobile-control-icon search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5 21 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span class="control-label">Найти</span></button></form><button type="button" id="mobile-menu-toggle" class="mobile-menu-toggle" aria-label="Открыть меню" title="Меню"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="19" r="1.8" fill="currentColor"/></svg></button><div class="account-info" id="account-info"><label id="date-picker-label" class="date-picker-control" title="Выбрать дату истории"><span id="date-picker-caption">История</span><input type="date" id="history-date" aria-label="Выбрать дату истории"></label><button type="button" id="all-journal" class="hidden">Все записи</button><span class="account-nick"><?=chat_e((string)$user['nick'])?></span><span class="account-email"><?=chat_e((string)$user['email'])?></span><button type="button" id="profile-toggle">Сменить ник</button><form id="logout-form"><button type="submit">Выход</button></form></div></header>
<div id="profile-panel" class="inline-panel hidden"><form id="nick-form"><label>Новый ник<input type="text" name="nick" minlength="2" maxlength="32" pattern="[^\s]+" required></label><button type="submit">Подтвердить через email</button><button type="button" id="profile-close">Закрыть</button><span id="nick-result" class="form-result"></span></form></div>
<div class="main-layout">
<main class="center-panel">
<div id="pinned-block" class="pinned-block"><button type="button" class="block-caption pinned-heading" id="pinned-search-toggle" title="Найти все закреплённые сообщения">Закреплённые сообщения</button><div id="pinned-list" class="pinned-list"></div></div>
<div id="mobile-smiles-popover" class="mobile-smiles-popover hidden" role="dialog" aria-label="Выбор смайлика"><div class="mobile-smiles-popover-head"><strong>Смайлы</strong><button type="button" id="mobile-smiles-close" aria-label="Закрыть смайлы" title="Закрыть">×</button></div><div id="mobile-smiles-list" class="smiles-panel"></div></div>
<div id="timeline" class="timeline" aria-live="polite"><div class="loading">Загрузка…</div></div>
<div id="mobile-status-toast" class="mobile-status-toast status-toast-pill hidden" role="status" aria-live="polite"></div>
<div id="composer-wrap" class="composer-wrap"><div class="composer-tools"><button type="button" id="mobile-smiles-button" class="mobile-smiles-button" aria-label="Открыть смайлы" title="Смайлы"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="9.5" r="1" fill="currentColor"/><circle cx="15" cy="9.5" r="1" fill="currentColor"/><path d="M7.5 13.5c1 2 2.5 3 4.5 3s3.5-1 4.5-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button><button type="button" id="attach-button" title="Прикрепить вложение" aria-label="Прикрепить вложение"><svg class="mobile-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 12.5 15 6a4 4 0 0 1 5.7 5.7l-8.2 8.2a6 6 0 0 1-8.5-8.5l8-8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="control-label">Прикрепить файл</span></button><input type="file" id="attachments" multiple hidden><button type="button" id="quote-selection" class="hidden">Цитировать</button></div><div id="attach-names" class="attachment-pending-list hidden" aria-live="polite"></div><div id="composer" class="composer" contenteditable="true" role="textbox" aria-label="Текст сообщения" aria-multiline="true" data-placeholder="Напишите сообщение…"></div><div class="composer-bottom"><span class="responsive-control-anchor" id="dark-theme-anchor" aria-hidden="true"></span><label class="dark-theme-setting" title="Сохранить тёмную тему для этой учётной записи">Тёмная тема <input type="checkbox" id="dark-theme-toggle"<?= $darkTheme ? ' checked' : '' ?>></label><span class="responsive-control-anchor" id="notify-setting-anchor" aria-hidden="true"></span><label class="notify-setting">Уведомления <select id="notification-mode"><option value="ALL">ВСЕ</option><option value="MINE">МОЁ</option><option value="NONE">НЕТ</option></select></label><span class="hint composer-hint">Отправка по Ctrl+Enter</span><button type="button" id="send-button" title="Отправить" aria-label="Отправить"><svg class="mobile-control-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 21 3l-6.5 18-3.2-7.3L3 11.5Z" fill="currentColor"/><path d="m11.3 13.7 4.8-4.8" fill="none" stroke="var(--send-icon-line,currentColor)" stroke-width="1.4" stroke-linecap="round"/></svg><span class="control-label">Отправить</span></button></div></div>
<div id="blocked-banner" class="blocked-banner hidden"></div>
</main>
<aside class="users-panel" id="sidebar-panel">
  <div class="mobile-menu-header" id="mobile-menu-header"></div>
  <button type="button" id="users-accordion-toggle" class="accordion-heading" aria-expanded="true" aria-controls="users-list"><span>Пользователи</span><span class="accordion-chevron" aria-hidden="true">▾</span></button>
  <div id="users-list" class="accordion-content"><div class="loading">Загрузка…</div></div>
  <button type="button" id="smiles-accordion-toggle" class="accordion-heading" aria-expanded="false" aria-controls="smiles-list"><span>Смайлы</span><span class="accordion-chevron" aria-hidden="true">▸</span></button>
  <div id="smiles-list" class="accordion-content smiles-panel hidden"><div class="loading">Загрузка смайлов…</div></div>
  <button type="button" id="settings-accordion-toggle" class="accordion-heading mobile-settings-heading" aria-expanded="false" aria-controls="settings-list"><span>Настройки</span><span class="accordion-chevron" aria-hidden="true">▸</span></button>
  <div id="settings-list" class="accordion-content mobile-settings-list hidden"><div id="mobile-settings-content" class="mobile-settings-content"></div></div>
  <?php if($user['is_admin']): ?>
  <button type="button" id="admin-accordion-toggle" class="accordion-heading admin-accordion-toggle" aria-expanded="false" aria-controls="admin-menu-list"><span>Администрирование</span><span class="accordion-chevron" aria-hidden="true">▸</span></button>
  <div id="admin-menu-list" class="accordion-content admin-menu-list hidden">
    <button type="button" id="admin-menu-storage" class="admin-menu-item">Хранилище</button>
    <button type="button" id="admin-menu-blocked-nicks" class="admin-menu-item">Заблокированные ники</button>
    <button type="button" id="admin-menu-blocked-emails" class="admin-menu-item">Заблокированные Email</button>
    <button type="button" id="admin-menu-journal" class="admin-menu-item">Журнал Админа</button>
  </div>
  <?php endif; ?>
</aside>
<div class="app-footer-row">
  <footer class="statusbar" role="status" aria-live="polite"><span id="server-clock"><?=chat_e(date('d.m.Y H:i:s'))?></span><span class="statusbar-version">Light Chat v1.2.0</span><span id="status-message" class="status-message">Готово</span></footer>
</div>
</div>
<div id="user-delete-overlay" class="user-delete-overlay hidden" role="presentation">
  <section class="user-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="user-delete-title">
    <h2 id="user-delete-title">Удалить пользователя?</h2>
    <p id="user-delete-description">История сообщений пользователя останется в чате.</p>
    <form id="user-delete-form">
      <input type="hidden" id="delete-user-id" name="user_id" value="">
      <label class="delete-check"><input type="checkbox" id="delete-block-email" name="block_email" value="1"> Заблокировать его адрес email для дальнейшей регистрации?</label>
      <label class="delete-check"><input type="checkbox" id="delete-block-nick" name="block_nick" value="1"> Заблокировать такой ник для дальнейшей регистрации?</label>
      <div class="dialog-actions"><button type="submit">Удалить пользователя</button><button type="button" id="cancel-user-delete">Отмена</button></div>
    </form>
  </section>
</div>
</div>
<?php endif; ?>
<script src="assets/transport.js?v=1.3.0" defer></script><script src="assets/auth.js?v=1.3.0" defer></script><script src="assets/chat.js?v=1.3.1" defer></script>
</body></html>
