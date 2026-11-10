# Тест VLC WebAssembly для TALI

Отдельная диагностическая страница. Основной плеер, API и базу данных TALI не меняет.

## Состав

- `vlc-wasm-test/index.html`, `app.js`, `style.css` — тестовая страница и управление.
- `vlc-wasm-test/nginx-vlc-wasm-test.conf` — отдельный location с COOP/COEP.
- Используется libvlc-wasm 0.2.0. При установке пакет один раз скачивается на сервер из npm и распаковывается в локальный каталог TALI. Во время просмотра браузер получает JS, Worker и WASM только с `192.168.2.222`; CDN не используется.
- Для Atom ограничены потоки VLC: 2 потока движка, 1 поток декодера, HTTP-кеш 8 MiB, шрифты отключены.

## Установка библиотеки на TALI

На хосте FreeBSD от root выполнить:

```sh
fetch -o /root/install-vlc-wasm-test.sh https://raw.githubusercontent.com/Fmulder007/test2/main/tali/install-vlc-wasm-test.sh
sh /root/install-vlc-wasm-test.sh
```

Установщик скачивает официальный архив npm `libvlc-wasm@0.2.0` и размещает его в:

`/jails/tali/usr/local/www/tali/vlc-wasm-test/vendor/libvlc-wasm/`

Он не редактирует конфигурацию Nginx и не перезапускает службы. Если загрузка архива с npm на хосте недоступна, архив можно скачать на другой машине и перенести на сервер; скрипт рассчитан на файл архива в `/root/libvlc-wasm-0.2.0.tgz`, если он уже существует.

## Nginx

В существующий `server { ... }` блок Nginx TALI добавить содержимое `nginx-vlc-wasm-test.conf`. Это отдельный URL location; глобальные заголовки и другие location не менять.

Проверить конфигурацию:

```sh
jexec tali nginx -t
```

Только если проверка успешна:

```sh
jexec tali service nginx reload
```

## Проверка

Для SharedArrayBuffer одновременно нужны secure context и COOP/COEP. Обычный HTTP по адресу `192.168.2.222` браузер не считает безопасным контекстом. Если HTTPS пока не настроен, для разового теста Firefox можно добавить IP в `dom.securecontext.allowlist` через `about:config`. Это настройка тестового браузера, не сервера.

Открыть:

`http://192.168.2.222/vlc-wasm-test/`

В поле адреса указать путь к существующему файлу, например:

`/video/Каталог/Фильм.mkv`

Страница проверит изоляцию, затем загрузит библиотеку с локального TALI и откроет файл. Видеофайл запрашивается напрямую у Nginx TALI с HTTP Range; VLC-сервер на порту 8090 не используется.

## Важно

- COOP/COEP включены только для `/vlc-wasm-test/`, не для всего сайта.
- Страница не запускает и не меняет системный VLC, Telnet 4212 или HTTP-поток 8090.
- Плейлисты, профили, история и API TALI не подключены.
- В составе пакета есть лицензируемый отдельно WASM-бинарник VLC; лицензии пакета находятся в каталоге `vendor/libvlc-wasm/` после установки.
