#!/bin/sh
# Install libvlc-wasm 0.2.0 locally for the isolated TALI test page.
# Run on the FreeBSD host as root. Does not edit nginx.conf or reload services.

set -eu

JAIL_ROOT="/jails/tali"
TEST_DIR="${JAIL_ROOT}/usr/local/www/tali/vlc-wasm-test"
VENDOR_DIR="${TEST_DIR}/vendor"
PACKAGE_DIR="${VENDOR_DIR}/libvlc-wasm"
RAW_BASE="https://raw.githubusercontent.com/Fmulder007/test2/main/tali/vlc-wasm-test"
NPM_URL="https://registry.npmjs.org/libvlc-wasm/-/libvlc-wasm-0.2.0.tgz"

if [ ! -d "${TEST_DIR}" ]; then
    echo "ERROR: test page directory not found: ${TEST_DIR}" >&2
    echo "First copy tali/vlc-wasm-test into the TALI webroot." >&2
    exit 1
fi

TMP_DIR=$(mktemp -d /tmp/tali-vlc-wasm.XXXXXX)
STAGE_DIR="${VENDOR_DIR}/.libvlc-wasm-stage"
trap 'rm -rf "${TMP_DIR}" "${STAGE_DIR}"' EXIT HUP INT TERM

mkdir -p "${VENDOR_DIR}"

echo "Updating test page JavaScript to the local-library version…"
fetch -q -o "${TMP_DIR}/app.js" "${RAW_BASE}/app.js"
fetch -q -o "${TMP_DIR}/index.html" "${RAW_BASE}/index.html"

ARCHIVE="/root/libvlc-wasm-0.2.0.tgz"
if [ ! -s "${ARCHIVE}" ]; then
    echo "Downloading official libvlc-wasm 0.2.0 package from npm…"
    fetch -o "${TMP_DIR}/libvlc-wasm-0.2.0.tgz" "${NPM_URL}"
    ARCHIVE="${TMP_DIR}/libvlc-wasm-0.2.0.tgz"
else
    echo "Using existing archive: ${ARCHIVE}"
fi

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"
tar -xzf "${ARCHIVE}" -C "${STAGE_DIR}"

if [ ! -f "${STAGE_DIR}/package/src/index.js" ] || \
   [ ! -f "${STAGE_DIR}/package/src/worker.js" ] || \
   [ ! -f "${STAGE_DIR}/package/wasm/libvlc.js" ] || \
   [ ! -s "${STAGE_DIR}/package/wasm/libvlc.wasm" ]; then
    echo "ERROR: npm archive has an unexpected structure or is incomplete." >&2
    exit 1
fi

rm -rf "${PACKAGE_DIR}.new"
mv "${STAGE_DIR}/package" "${PACKAGE_DIR}.new"
rm -rf "${PACKAGE_DIR}"
mv "${PACKAGE_DIR}.new" "${PACKAGE_DIR}"
rm -rf "${STAGE_DIR}"

find "${PACKAGE_DIR}" -type f -exec chmod 644 {} \;

echo
echo "Installed local libvlc-wasm 0.2.0:"
du -sh "${PACKAGE_DIR}"
ls -lh "${PACKAGE_DIR}/wasm/libvlc.js" "${PACKAGE_DIR}/wasm/libvlc.wasm"
echo
cp "${TMP_DIR}/app.js" "${TEST_DIR}/app.js"
cp "${TMP_DIR}/index.html" "${TEST_DIR}/index.html"
chmod 644 "${TEST_DIR}/app.js" "${TEST_DIR}/index.html"

echo "The test page now loads JavaScript, Worker and WASM from TALI itself."
echo "No nginx configuration changes or service reloads were made."
