#!/bin/sh
#
# TALI — сбор диагностического отчёта перед экспериментом с VLC WebAssembly.
#
# Запускать НА ХОСТЕ FreeBSD от root:
#   sh /root/research-tali-vlc.sh
#
# Скрипт ничего не меняет в jail TALI, Nginx, PHP-FPM, ZFS или видеотеке.
# Единственное создаваемое действие — текстовый отчёт в /root.
# В отчёт не копируются базы данных, cookie, пароли, ключи, .env и конфиги
# с секретами. Содержимое основных файлов сайта проходит простую редакцию
# строк, похожих на присваивания секретов.
#
# После завершения скачайте файл отчёта через WinSCP из /root и верните его
# в чат. Перед отправкой можно открыть отчёт и проверить его содержимое.

set -u
umask 077

JAIL="tali"
WEBROOT="/usr/local/www/tali"
MEDIA="/data/video"
DBDIR="/var/db/tali"
REPORT="/root/tali-vlc-research-$(date +%Y%m%d-%H%M%S).txt"

if [ "$(id -u)" -ne 0 ]; then
    echo "ОШИБКА: запустите скрипт от root на хосте FreeBSD."
    exit 1
fi

if ! jls -j "$JAIL" >/dev/null 2>&1; then
    echo "ОШИБКА: jail TALI не запущен. Скрипт ничего не менял."
    exit 1
fi

: > "$REPORT" || {
    echo "ОШИБКА: не удалось создать отчёт $REPORT"
    exit 1
}

exec 3>&1
exec >>"$REPORT" 2>&1

section()
{
    printf "\n\n================================================================\n"
    printf "%s\n" "$1"
    printf "================================================================\n"
}

run()
{
    printf "\n$"
    printf " %s" "$@"
    printf "\n"
    "$@"
    rc=$?
    printf "[код завершения: %s]\n" "$rc"
    return 0
}

host_file()
{
    file="$1"
    printf "\n--- Файл хоста: %s ---\n" "$file"
    if [ -r "$file" ]; then
        cat "$file"
    else
        echo "[не найден или нет доступа]"
    fi
}

jail_file()
{
    file="$1"
    printf "\n--- Файл jail %s: %s ---\n" "$JAIL" "$file"
    if jexec "$JAIL" test -r "$file"; then
        jexec "$JAIL" cat "$file"
    else
        echo "[не найден или нет доступа]"
    fi
}

jail_source_file()
{
    file="$1"
    printf "\n--- Исходный файл сайта: %s ---\n" "$file"
    if jexec "$JAIL" test -r "$file"; then
        jexec "$JAIL" awk '
        {
            line = $0
            low = tolower(line)
            if (low ~ /(password|passwd|secret|api[_-]?key|private[_-]?key|bearer[[:space:]])/ &&
                line ~ /[=:]/) {
                sub(/[=:].*/, " = [ЗНАЧЕНИЕ СКРЫТО]", line)
            }
            print line
        }' "$file"
    else
        echo "[файл отсутствует]"
    fi
}

section "TALI — ДИАГНОСТИЧЕСКИЙ ОТЧЁТ"
echo "Дата сбора: $(date)"
echo "Хост: $(hostname)"
echo "Отчёт создан: $REPORT"
echo "Режим: только чтение; кроме создания этого отчёта изменений нет."
echo "Содержимое видеотеки, SQLite и секретных файлов в отчёт не копируется."

section "1. ХОСТ FREEBSD И СОСТОЯНИЕ JAIL"
run freebsd-version -kru
run uptime
run jls
run jls -j "$JAIL"
run jls -n -j "$JAIL"

section "2. ZFS: DATASET TALI И ЕГО СНИМКИ"
run zfs list -o name,used,avail,refer,mountpoint zroot/jails/tali
run zfs get mountpoint,quota,refquota,origin,compression,atime zroot/jails/tali
run zfs list -t snapshot -o name,creation,used,refer -s creation -r zroot/jails/tali

section "3. КОНФИГУРАЦИЯ JAIL TALI НА ХОСТЕ"
if [ -r /etc/jail.conf ]; then
    awk '
    /^tali[[:space:]]*\{/ { printing=1 }
    printing { print }
    printing && /^}/ { printing=0 }
    ' /etc/jail.conf
else
    echo "[/etc/jail.conf недоступен]"
fi
host_file /etc/fstab.tali
run sysrc -n jail_enable
run sysrc -n jail_list

section "4. СЕТЬ TALI И МОСТ ХОСТА"
run ifconfig bridge0
run ifconfig epair1a
run jexec "$JAIL" ifconfig
run jexec "$JAIL" netstat -rn
run jexec "$JAIL" sockstat -4 -6 -l

section "5. ОС, ПАКЕТЫ И ПРОЦЕССЫ В TALI"
run jexec "$JAIL" freebsd-version -kru
run jexec "$JAIL" pkg info nginx
run jexec "$JAIL" pkg info php
run jexec "$JAIL" pkg info vlc
run jexec "$JAIL" pkg info ffmpeg
run jexec "$JAIL" nginx -v
run jexec "$JAIL" php -v
run jexec "$JAIL" php --ini
run jexec "$JAIL" php -m
run jexec "$JAIL" ps -axo user,pid,comm

section "6. СЛУЖБЫ И СЛУШАЮЩИЕ ПОРТЫ"
run jexec "$JAIL" service nginx status
run jexec "$JAIL" service php_fpm status
run jexec "$JAIL" service vlc status
run jexec "$JAIL" sockstat -4 -6 -l

section "7. NGINX: ПРОВЕРКА И ПОЛНАЯ АКТИВНАЯ КОНФИГУРАЦИЯ"
run jexec "$JAIL" nginx -t
run jexec "$JAIL" nginx -T

section "8. PHP-FPM: ФАЙЛЫ КОНФИГУРАЦИИ И ПРОВЕРКА"
run jexec "$JAIL" ls -la /usr/local/etc/php-fpm.d
run jexec "$JAIL" php-fpm -t
echo
echo "Безопасные основные параметры PHP-FPM:"
for key in php_fpm_enable; do
    printf "%s: " "$key"
    jexec "$JAIL" sysrc -n "$key"
done

section "9. НАСТРОЙКИ RC.CONF TALI (ТОЛЬКО НЕСЕКРЕТНЫЕ ПОЛЯ)"
for key in hostname ifconfig_epair1b defaultrouter nginx_enable php_fpm_enable vlc_enable vlc_user vlc_group; do
    value=$(jexec "$JAIL" sysrc -n "$key" 2>/dev/null)
    if [ -n "$value" ]; then
        printf "%s=%s\n" "$key" "$value"
    fi
done

section "10. СТРУКТУРА WEBROOT TALI"
run jexec "$JAIL" ls -la "$WEBROOT"
run jexec "$JAIL" find "$WEBROOT" -maxdepth 4 -type f -print
run jexec "$JAIL" du -sh "$WEBROOT"

section "11. ОСНОВНЫЕ ФАЙЛЫ САЙТА: HTML, JAVASCRIPT, CSS, PHP API"
jail_source_file "$WEBROOT/index.html"
jail_source_file "$WEBROOT/app.js"
jail_source_file "$WEBROOT/style.css"
jail_source_file "$WEBROOT/api.php"

section "12. SQLITE: ТОЛЬКО МЕТАДАННЫЕ, БЕЗ СОДЕРЖИМОГО БАЗЫ"
run jexec "$JAIL" ls -lah "$DBDIR"
run jexec "$JAIL" du -sh "$DBDIR"

section "13. ВИДЕОТЕКА: ТОЛЬКО СВОДНАЯ ИНФОРМАЦИЯ, БЕЗ ИМЁН ФАЙЛОВ"
run jexec "$JAIL" mount -p
run jexec "$JAIL" df -h "$MEDIA"
run jexec "$JAIL" ls -ld "$MEDIA"
run jexec "$JAIL" du -sh "$MEDIA"
echo
echo "Количество видеофайлов:"
jexec "$JAIL" sh -c 'find /data/video -type f | wc -l'
echo
echo "Распределение файлов по расширениям (имена файлов не выводятся):"
jexec "$JAIL" sh -c 'find /data/video -type f | awk -F. "{ ext=tolower(\$NF); count[ext]++ } END { for (ext in count) print ext, count[ext] }" | sort'

section "14. MIME-ТИПЫ ДЛЯ ВИДЕО"
jexec "$JAIL" awk '
    tolower($0) ~ /(mp4|m4v|mov|webm|ogv|ogg|avi|mkv|mpeg|mpg|m2v|ts|mts|m2ts|wmv|flv|3gp|vob)/ {
        print
    }
' /usr/local/etc/nginx/mime.types

section "15. VLC: ВЕРСИЯ, КОНФИГУРАЦИОННЫЕ ПУТИ И ДОКУМЕНТАЦИЯ"
run jexec "$JAIL" vlc --version
run jexec "$JAIL" pkg info -l vlc
run jexec "$JAIL" ls -la /usr/local/etc/tali-vlc
run jexec "$JAIL" ls -la /usr/local/etc/rc.d
echo
echo "Файл tali-api.php намеренно НЕ читается: в нём находится пароль Telnet VLC."
echo "Содержимое rc.conf и конфигурационных файлов VLC с возможными паролями не копируется."

section "16. ПРОВЕРКА HTTP-ЗАГОЛОВКОВ ЛОКАЛЬНОГО TALI"
if jexec "$JAIL" command -v curl >/dev/null 2>&1; then
    run jexec "$JAIL" curl -sS -I --max-time 8 http://127.0.0.1/
    run jexec "$JAIL" curl -sS -I --max-time 8 http://127.0.0.1/video/
else
    echo "curl в jail не найден; HTTP HEAD-тест пропущен."
fi

section "17. ПОЛЕЗНЫЕ ФАКТЫ ДЛЯ VLC WASM"
echo "Проверяются наличие заголовков COOP/COEP/CSP, MIME, Range и текущий URL видео."
echo "Текущая разметка и код плеера приведены в разделе 11."
echo "Проверка COOP/COEP по активной конфигурации Nginx:"
jexec "$JAIL" nginx -T 2>&1 | awk '
    tolower($0) ~ /(cross-origin-opener-policy|cross-origin-embedder-policy|cross-origin-resource-policy|content-security-policy|access-control-allow-origin|accept_ranges|sendfile|aio|directio|mp4;|mp4_max_buffer_size|mp4_buffer_size|mp4_limit_rate)/ {
        print
    }
'
echo
echo "Ссылки и источники видео из app.js/API находятся в разделе 11."

section "18. КОНЕЦ ОТЧЁТА"
echo "Дата завершения: $(date)"
echo "Отчёт создан скриптом research-vlc.sh."
echo "Секретные файлы, cookie и содержимое SQLite не включались."

exec 1>&3 2>&3
echo "Готово. Отчёт создан:"
echo "$REPORT"
echo
echo "Скачайте этот TXT-файл через WinSCP из /root и верните его в чат."
echo "Перед отправкой при желании просмотрите отчёт: он содержит пути, IP и исходный код TALI."
