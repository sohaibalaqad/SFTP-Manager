#!/bin/bash
# SFTP File Manager launcher — macOS (double-click "Start SFTP Manager.command") and Linux (./start.sh).
# First run: downloads the FrankenPHP engine once (PHP included — nothing else to install), verifies its
# SHA-256, creates the local settings, then starts the app on this computer only and opens the browser.

cd "$(dirname "$0")" || exit 1

VERSION="v1.12.7"
PORT="${SFTP_PORT:-8010}" # optional: SFTP_PORT=9000 ./start.sh

pause_and_exit() {
    echo
    read -r -p "اضغط Enter للإغلاق / Press Enter to close… " _
    exit "${1:-1}"
}

case "$(uname -s)-$(uname -m)" in
    Darwin-arm64)               ASSET="frankenphp-mac-arm64";     SUM="25e22aaf98634caabafa921817a5acd49f6954665d5cf47ea5d54e9e769e7a0d" ;;
    Darwin-x86_64)              ASSET="frankenphp-mac-x86_64";    SUM="7bddf2d53935d76dc4acd61b97f00b03667138a9464633b7961a9fd2249d2550" ;;
    Linux-x86_64)               ASSET="frankenphp-linux-x86_64";  SUM="cf2f111272e54fc860a598fe7d7c68445f47e516232a2bc9783439dfd7d14335" ;;
    Linux-aarch64|Linux-arm64)  ASSET="frankenphp-linux-aarch64"; SUM="ae9df56b8686a68b8459ffa235a481edbb8389064d254e82080669fb23d436eb" ;;
    *) echo "هذا النظام غير مدعوم / Unsupported system: $(uname -s) $(uname -m)"; pause_and_exit ;;
esac

open_browser() {
    [ -n "$SFTP_NO_BROWSER" ] && return 0 # for automated tests
    if [ "$(uname -s)" = "Darwin" ]; then open "$1"; else xdg-open "$1" >/dev/null 2>&1 || true; fi
}

app_running_on() {
    curl -fs --max-time 2 "http://127.0.0.1:$1/" 2>/dev/null | grep -q "مدير ملفات SFTP"
}

port_in_use() {
    (echo >"/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1
}

# Already running (e.g. double-clicked twice)? Just open it.
for p in $(seq $PORT $((PORT + 9))); do
    if app_running_on "$p"; then
        echo "البرنامج يعمل بالفعل / Already running: http://127.0.0.1:$p"
        open_browser "http://127.0.0.1:$p"
        exit 0
    fi
done

# 1) The engine (FrankenPHP = web server + PHP in one file), downloaded once.
if [ ! -x ./frankenphp ]; then
    echo "أول تشغيل: تنزيل محرك التشغيل (حوالي 170 MB) مرة واحدة فقط…"
    echo "First run: downloading the engine (~170 MB), only once…"
    if ! curl -fL --progress-bar -o frankenphp.part "https://github.com/php/frankenphp/releases/download/$VERSION/$ASSET"; then
        rm -f frankenphp.part
        echo "تعذر التنزيل. تحقق من الإنترنت ثم أعد المحاولة. / Download failed."
        pause_and_exit
    fi
    ACTUAL=$( (shasum -a 256 frankenphp.part 2>/dev/null || sha256sum frankenphp.part) | awk '{print $1}')
    if [ "$ACTUAL" != "$SUM" ]; then
        rm -f frankenphp.part
        echo "الملف المنزَّل لا يطابق النسخة الرسمية، تم حذفه. / Checksum mismatch — file deleted."
        pause_and_exit
    fi
    chmod +x frankenphp.part && mv frankenphp.part frankenphp
fi
[ "$(uname -s)" = "Darwin" ] && xattr -d com.apple.quarantine ./frankenphp 2>/dev/null

PHP=(./frankenphp php-cli)

# 2) Local settings with a unique secret key for this computer.
if [ ! -f .env ]; then
    cp .env.example .env
    "${PHP[@]}" artisan key:generate --force --no-interaction >/dev/null || pause_and_exit
fi

# 3) A free port (8010, or the next free one).
while port_in_use "$PORT"; do PORT=$((PORT + 1)); done
URL="http://127.0.0.1:$PORT"

echo
echo "✅ مدير ملفات SFTP يعمل على: $URL"
echo "   لإيقافه: أغلق هذه النافذة (أو Ctrl+C). / To stop: close this window."
echo

# Open the browser as soon as the app answers.
( for _ in $(seq 1 120); do
    if app_running_on "$PORT"; then open_browser "$URL"; break; fi
    sleep 0.5
  done ) &

# Only reachable from this computer (127.0.0.1). Keeps SFTP connections open between clicks.
"${PHP[@]}" artisan octane:start --server=frankenphp --caddyfile=Caddyfile --host=127.0.0.1 --port="$PORT" --workers=4 --log-level=error
pause_and_exit $?
