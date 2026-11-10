#!/bin/sh
# Install the isolated VLC WebAssembly test page into the TALI jail.
# Run on the FreeBSD host as root. This script does not edit nginx.conf or reload nginx.

set -eu

JAIL="tali"
JAIL_ROOT="/jails/tali"
WEBROOT="${JAIL_ROOT}/usr/local/www/tali"
TEST_DIR="${WEBROOT}/vlc-wasm-test"
NGINX_DIR="${JAIL_ROOT}/usr/local/etc/nginx"
BASE_URL="https://raw.githubusercontent.com/Fmulder007/test2/main/tali"

if [ ! -d "${JAIL_ROOT}" ]; then
    echo "ERROR: jail directory not found: ${JAIL_ROOT}" >&2
    exit 1
fi

if ! jls -j "${JAIL}" >/dev/null 2>&1; then
    echo "ERROR: jail '${JAIL}' is not running." >&2
    echo "Start it first, then run this script again." >&2
    exit 1
fi

if [ ! -d "${WEBROOT}" ]; then
    echo "ERROR: TALI webroot not found: ${WEBROOT}" >&2
    exit 1
fi

mkdir -p "${TEST_DIR}" "${NGINX_DIR}"

fetch -q -o "${TEST_DIR}/index.html" "${BASE_URL}/vlc-wasm-test/index.html"
fetch -q -o "${TEST_DIR}/style.css" "${BASE_URL}/vlc-wasm-test/style.css"
fetch -q -o "${TEST_DIR}/app.js" "${BASE_URL}/vlc-wasm-test/app.js"
fetch -q -o "${NGINX_DIR}/vlc-wasm-test-location.conf" "${BASE_URL}/vlc-wasm-test/nginx-vlc-wasm-test.conf"

chmod 644 "${TEST_DIR}/index.html" "${TEST_DIR}/style.css" "${TEST_DIR}/app.js" "${NGINX_DIR}/vlc-wasm-test-location.conf"

echo
echo "VLC WASM test page files installed:"
ls -lh "${TEST_DIR}"
echo
echo "Nginx location snippet saved to:"
echo "${NGINX_DIR}/vlc-wasm-test-location.conf"
echo
echo "NEXT: add this line INSIDE the existing server { } block in TALI nginx config:"
echo "    include /usr/local/etc/nginx/vlc-wasm-test-location.conf;"
echo
echo "Then test and reload nginx inside the jail:"
echo "    jexec tali nginx -t"
echo "    jexec tali service nginx reload"
echo
echo "Page URL: http://192.168.2.222/vlc-wasm-test/"
