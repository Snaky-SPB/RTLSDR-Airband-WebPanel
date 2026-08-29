#!/bin/bash
#
# Установка системы radio-scanner на конечное устройство (Debian/Ubuntu, например NanoPi R1):
#   [1/3] RTLSDR-Airband (rtl_airband): apt-зависимости, сборка, установка, systemd-служба
#   [2/3] RTLSDR-Airband-WebPanel: PHP + Apache + Composer, vhost, sudoers
#   [3/3] usb4g-watchdog: мониторинг 4G-канала (RNDIS), восстановление модема (VBUS/USB-reset/reboot)
#
# Использование на чистом устройстве (root), "one-liner":
#   bash <(curl -fsSL https://raw.githubusercontent.com/Snaky-SPB/RTLSDR-Airband-WebPanel/main/scripts/install-radio-scanner.sh)
#   (или wget: bash <(wget -qO- <тот же URL>))
# Скрипт самодостаточен: отсутствующие исходники клонирует с GitHub.
#
# Можно запустить и локально из развёрнутого дерева radio-scanner/:
#   sudo bash scripts/install-radio-scanner.sh
#
# Переменные окружения:
#   AIRBAND_REPO        git-репозиторий rtl_airband (default: форк Snaky-SPB)
#   AIRBAND_BRANCH      ветка сборки                 (default: wideband-scan)
#   AIRBAND_DIR         каталог исходников rtl_airband (default: соседняя копия или /opt/src/RTLSDR-Airband)
#   WEBPANEL_REPO       git-репозиторий WebPanel     (default: публичный GitHub)
#   WEBPANEL_BRANCH     ветка WebPanel               (default: main)
#   WEBPANEL_DIR        каталог исходников WebPanel  (default: соседняя копия или /opt/src/RTLSDR-Airband-WebPanel)
#   PLATFORM            CMake PLATFORM               (default: native)
#   SKIP_AIRBAND=1      пропустить rtl_airband
#   SKIP_WEBPANEL=1     пропустить WebPanel
#   SKIP_WATCHDOG=1     пропустить usb4g-watchdog

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

AIRBAND_REPO="${AIRBAND_REPO:-https://github.com/Snaky-SPB/RTLSDR-Airband.git}"
AIRBAND_BRANCH="${AIRBAND_BRANCH:-wideband-scan}"
WEBPANEL_REPO="${WEBPANEL_REPO:-https://github.com/Snaky-SPB/RTLSDR-Airband-WebPanel.git}"
WEBPANEL_BRANCH="${WEBPANEL_BRANCH:-main}"
PLATFORM="${PLATFORM:-native}"
CLONE_DIR_AIRBAND="/opt/src/RTLSDR-Airband"
CLONE_DIR_WEBPANEL="/opt/src/RTLSDR-Airband-WebPanel"

RTL_AIRBAND_CONF="/usr/local/etc/rtl_airband.conf"
SOURCES_DIR="/media/rx/sources"
WEBPANEL_APP_DIR="/opt/rtl-sdr-airband-webpanel"

log() { echo "[install] $*"; }
die() { echo "[install] ERROR: $*" >&2; exit 1; }

usage() { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }
case "${1:-}" in
    -h|--help) usage ;;
esac

[ "$(id -u)" -eq 0 ] || die "запустите от root: sudo bash $0"

if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID:-}" in
        debian|ubuntu) ;;
        *) die "поддерживаемые ОС: Debian/Ubuntu (обнаружена: ${ID:-неизвестно})" ;;
    esac
else
    die "/etc/os-release не найден — скрипт должен выполняться на самом устройстве"
fi

# Дерево считается рабочим, если git внутри него разрешает HEAD
git_tree_ok() {
    git -c safe.directory='*' -C "$1" rev-parse --verify HEAD >/dev/null 2>&1
}

# Каталог исходников rtl_airband:
#  1. явно заданный AIRBAND_DIR (требует рабочий git, иначе ошибка)
#  2. соседняя копия с рабочим .git (развёрнутое дерево radio-scanner/)
#  3. клон в CLONE_DIR_AIRBAND
AIRBAND_DIR="${AIRBAND_DIR:-}"
if [ -n "$AIRBAND_DIR" ]; then
    git_tree_ok "$AIRBAND_DIR" || die "$AIRBAND_DIR: не рабочее git-хранилище (частичная копия?). Запустите без AIRBAND_DIR или с чистой копией"
elif [ -d "$SCRIPT_ROOT/RTLSDR-Airband/.git" ]; then
    if git_tree_ok "$SCRIPT_ROOT/RTLSDR-Airband"; then
        AIRBAND_DIR="$SCRIPT_ROOT/RTLSDR-Airband"
    else
        log "NOTE: соседняя RTLSDR-Airband/ с битым .git (частичная копия?) - буду клонировать с GitHub"
        AIRBAND_DIR="$CLONE_DIR_AIRBAND"
    fi
else
    AIRBAND_DIR="$CLONE_DIR_AIRBAND"
fi

install_airband() {
    log "=== [1/3] RTLSDR-Airband (rtl_airband) ==="

    log "  apt-зависимости..."
    apt-get update -qq
    apt-get install -y -qq \
        build-essential cmake git pkg-config curl rsync ca-certificates \
        libmp3lame-dev libshout3-dev libconfig++-dev libfftw3-dev librtlsdr-dev

    # Самоисцеление: повреждённый клон в управляемом скриптом каталоге - удалить и пересоздать
    if [ "$AIRBAND_DIR" = "$CLONE_DIR_AIRBAND" ] && [ -d "$AIRBAND_DIR/.git" ] && ! git_tree_ok "$AIRBAND_DIR"; then
        log "  существующий клон $AIRBAND_DIR повреждён - удаляю"
        rm -rf "$AIRBAND_DIR"
    fi

    if [ ! -d "$AIRBAND_DIR/.git" ]; then
        log "  клонирование: $AIRBAND_REPO ($AIRBAND_BRANCH) -> $AIRBAND_DIR"
        mkdir -p "$(dirname "$AIRBAND_DIR")"
        git clone --quiet -b "$AIRBAND_BRANCH" "$AIRBAND_REPO" "$AIRBAND_DIR"
    elif [ "$AIRBAND_DIR" = "$CLONE_DIR_AIRBAND" ]; then
        log "  обновление: $AIRBAND_DIR -> $AIRBAND_BRANCH"
        git -C "$AIRBAND_DIR" fetch origin
        git -C "$AIRBAND_DIR" checkout -B "$AIRBAND_BRANCH" "origin/$AIRBAND_BRANCH"
    else
        log "  локальная копия: $AIRBAND_DIR (git-операции не выполняются)"
    fi

    # быстрая проверка: git должен выдавать версию, иначе cmake упадёт на CMakeLists.txt:13
    ver="$(git -c safe.directory='*' -C "$AIRBAND_DIR" describe --tags --abbrev --dirty --always 2>/dev/null || true)"
    [ -n "$ver" ] || die "$AIRBAND_DIR: git не выдаёт версию (дерево повреждено). Удалите каталог и повторите запуск"
    log "  версия: $ver"

    log "  сборка: Release, NFM, RTL-SDR, PLATFORM=$PLATFORM"
    # cmake запускается изнутри дерева: find_version ищет .git от CWD процесса
    # (execute_process наследует CWD cmake, а не source-каталог) — извне версия
    # не детектится и CMakeLists.txt:13 падает
    ( cd "$AIRBAND_DIR" && \
        cmake -S . -B build \
            -DCMAKE_BUILD_TYPE=Release \
            -DNFM=ON -DRTLSDR=ON -DMIRISDR=OFF -DSOAPYSDR=OFF -DPULSEAUDIO=OFF \
            -DPLATFORM="$PLATFORM" && \
        cmake --build build -j"$(nproc)" )

    log "  установка: cmake --install -> /usr/local/bin/rtl_airband"
    ( cd "$AIRBAND_DIR" && cmake --install build )

    log "  systemd: rtl_airband.service"
    install -m 644 "$AIRBAND_DIR/init.d/rtl_airband.service" /etc/systemd/system/rtl_airband.service
    systemctl daemon-reload
    systemctl enable rtl_airband
    if [ -f "$RTL_AIRBAND_CONF" ]; then
        systemctl restart rtl_airband
        log "  служба перезапущена (конфиг уже существует)"
    else
        log "  конфиг $RTL_AIRBAND_CONF ещё не создан:"
        log "  служба стартует после первого применения пресета из веб-панели"
    fi

    mkdir -p "$SOURCES_DIR"
    log "  OK: $(rtl_airband -v)"
}

install_webpanel() {
    log "=== [2/3] RTLSDR-Airband-WebPanel ==="

    local dir="${WEBPANEL_DIR:-}"
    if [ -z "$dir" ]; then
        local cand
        for cand in "$SCRIPT_ROOT/RTLSDR-Airband-WebPanel" /media/rx/www; do
            if [ -f "$cand/composer.json" ] && [ -d "$cand/public" ] && [ -f "$cand/install.sh" ]; then
                dir="$cand"
                break
            fi
        done
    fi
    if [ -z "$dir" ]; then
        dir="$CLONE_DIR_WEBPANEL"
    fi
    # Как и у Airband: управляемый клон обновляется всегда (даже если WEBPANEL_DIR
    # указывает на него), любой другой каталог — локальная копия, деплой как есть
    if [ "$dir" = "$CLONE_DIR_WEBPANEL" ]; then
        if [ -d "$dir/.git" ] && ! git_tree_ok "$dir"; then
            log "  существующий клон $dir повреждён - удаляю"
            rm -rf "$dir"
        fi
        if [ -d "$dir/.git" ]; then
            log "  обновление: $dir -> $WEBPANEL_BRANCH"
            git -C "$dir" fetch origin
            git -C "$dir" checkout -B "$WEBPANEL_BRANCH" "origin/$WEBPANEL_BRANCH"
        elif [ ! -e "$dir" ]; then
            log "  клонирование: $WEBPANEL_REPO ($WEBPANEL_BRANCH) -> $dir"
            mkdir -p "$(dirname "$dir")"
            git clone --quiet -b "$WEBPANEL_BRANCH" "$WEBPANEL_REPO" "$dir"
        else
            log "  NOTE: $dir существует без .git - оставляю как есть"
        fi
    else
        log "  локальная копия: $dir (git-операции не выполняются)"
    fi
    log "  исходники: $dir"

    if [ -d "$WEBPANEL_APP_DIR" ]; then
        [ -f "$dir/deploy.sh" ] || die "$dir: deploy.sh не найден"
        log "  WebPanel уже установлен ($WEBPANEL_APP_DIR) - deploy.sh"
        log "  (rsync + права + reload apache; PHP/Apache/Composer не трогаются)"
        ( cd "$dir" && bash deploy.sh )
    else
        log "  WebPanel не установлен - install.sh"
        log "  (PHP + Apache + Composer, vhost, sudoers)"
        ( cd "$dir" && bash install.sh )
    fi
}

install_watchdog() {
    log "=== [3/3] usb4g-watchdog ==="

    log "  /usr/local/sbin/usb4g-watchdog.sh"
    cat > /usr/local/sbin/usb4g-watchdog.sh <<'WATCHDOG_EOF'
#!/bin/bash
#
# usb4g-watchdog — мониторинг 4G-канала (RNDIS-интерфейс 4G-модема).
#
# Лечит состояние «интерфейс жив, интернета нет»: модем в RNDIS-режиме
# может потерять PDP-контекст — RNDIS + DHCP работают, шлюз модема
# отвечает, но за модемом трафика нет. Единственное доказанное
# восстановление — обесточивание модема; USB-reset PDP не возвращает
# (проверено на p-radio, 2026-08-29).
#
# Восстановление (по ступеням, после FAIL_THRESHOLD неудачных проверок):
#   1) power-cycle VBUS порта, если задан VBUS_REG (например NanoPi R1:
#      usb0-vbus, OTG-порт) — точечное обесточивание порта модема;
#   2) USB-reset: remove USB-устройства RNDIS + unbind/rebind родительского хаба;
#   3) reboot.
#
# Конфиг: /etc/default/usb4g-watchdog (EnvironmentFile).
# Логи: stdout -> journal (journalctl -u usb4g-watchdog).

IFACE="${IFACE:-usb0}"
GW="${GW:-192.168.88.1}"
CHECK_INTERVAL="${CHECK_INTERVAL:-60}"
FAIL_THRESHOLD="${FAIL_THRESHOLD:-5}"
PROBE_TIMEOUT="${PROBE_TIMEOUT:-4}"
RESET_WAIT="${RESET_WAIT:-120}"
COOLDOWN="${COOLDOWN:-1800}"
VBUS_REG="${VBUS_REG:-}"
VBUS_OFF_TIME="${VBUS_OFF_TIME:-5}"

STATE_FILE="/var/lib/usb4g-watchdog/last-intervention"

log() { echo "[usb4g-watchdog] $*"; }

# --- проверки ---

iface_ok() {
    [ -d "/sys/class/net/$IFACE" ] || return 1
    [ "$(cat "/sys/class/net/$IFACE/carrier" 2>/dev/null)" = "1" ] || return 1
    ip -4 -o addr show dev "$IFACE" >/dev/null 2>&1 || return 1
}

gw_ok() {
    ping -c 1 -W 2 "$GW" >/dev/null 2>&1
}

# «Интернет за модемом»: TCP-подключение к закрытому порту публичного DNS.
# Живая сеть -> RST приходит мгновенно (rc=1); мёртвая сеть -> timeout (rc=124).
external_ok() {
    local t rc
    for t in "8.8.8.8:9" "1.1.1.1:9" "9.9.9.9:9"; do
        timeout "$PROBE_TIMEOUT" bash -c "exec 3<>/dev/tcp/${t%:*}/${t#*:}" 2>/dev/null
        rc=$?
        [ "$rc" -eq 1 ] && return 0
    done
    return 1
}

check_ok() {
    iface_ok && gw_ok && external_ok
}

# --- восстановление ---

usb_device_of_iface() {
    [ -e "/sys/class/net/$IFACE/device" ] || return 1
    basename "$(readlink -f "/sys/class/net/$IFACE/device")"
}

vbus_regulator_dir() {
    local d
    [ -n "$VBUS_REG" ] || return 1
    for d in /sys/class/regulator/*/; do
        if [ "$(cat "$d/name" 2>/dev/null)" = "$VBUS_REG" ]; then
            printf '%s' "${d%/}"
            return 0
        fi
    done
    return 1
}

do_vbus_powercycle() {
    local reg
    reg="$(vbus_regulator_dir)" || { log "regulator '$VBUS_REG' не найден - пропускаю ступень 1"; return 1; }
    log "power-cycle VBUS: $VBUS_REG off на ${VBUS_OFF_TIME}s"
    echo disable > "$reg/state" || return 1
    sleep "$VBUS_OFF_TIME"
    echo enable > "$reg/state" || return 1
}

do_usb_reset() {
    local dev parent
    dev="$(usb_device_of_iface)" || { log "USB-устройство интерфейса $IFACE не найдено"; return 1; }
    parent="$(basename "$(dirname "$(readlink -f "/sys/bus/usb/devices/$dev")")")"
    log "USB-reset: remove $dev, rebind хаба $parent"
    echo 1 > "/sys/bus/usb/devices/$dev/remove" || return 1
    if [ -d "/sys/bus/usb/devices/$parent" ]; then
        echo "$parent" > /sys/bus/usb/drivers/usb/unbind 2>/dev/null
        sleep 3
        echo "$parent" > /sys/bus/usb/drivers/usb/bind 2>/dev/null
    fi
}

maybe_intervene() {
    local now last
    now="$(date +%s)"
    last="$(cat "$STATE_FILE" 2>/dev/null || echo 0)"
    if [ $((now - last)) -lt "$COOLDOWN" ]; then
        log "кулдаун (осталось $((last + COOLDOWN - now))s) - не вмешиваюсь"
        return
    fi
    mkdir -p "$(dirname "$STATE_FILE")"
    echo "$now" > "$STATE_FILE"

    log "4G-канал мёртв ($FAIL_THRESHOLD неудачных проверок) - начинаю восстановление"
    do_vbus_powercycle || true
    sleep "$RESET_WAIT"
    if check_ok; then
        log "восстановлено после power-cycle VBUS"
        return
    fi
    log "power-cycle VBUS не помог - USB-reset"
    do_usb_reset || true
    sleep "$RESET_WAIT"
    if check_ok; then
        log "восстановлено после USB-reset"
        return
    fi
    log "USB-reset не помог - reboot (единственное доказанное восстановление)"
    reboot
}

fails=0
log "старт: iface=$IFACE gw=$GW interval=${CHECK_INTERVAL}s threshold=$FAIL_THRESHOLD vbus_reg=${VBUS_REG:-<не задан>}"

while true; do
    if check_ok; then
        [ "$fails" -gt 0 ] && log "канал восстановлен (было неудачных проверок: $fails)"
        fails=0
    else
        fails=$((fails + 1))
        log "неудачная проверка ($fails/$FAIL_THRESHOLD)"
        [ "$fails" -ge "$FAIL_THRESHOLD" ] && { maybe_intervene; fails=0; }
    fi
    sleep "$CHECK_INTERVAL"
done
WATCHDOG_EOF
    chmod 755 /usr/local/sbin/usb4g-watchdog.sh

    log "  /etc/systemd/system/usb4g-watchdog.service"
    cat > /etc/systemd/system/usb4g-watchdog.service <<'UNIT_EOF'
[Unit]
Description=4G RNDIS data-link watchdog
After=network.target
Wants=network.target

[Service]
Type=simple
EnvironmentFile=-/etc/default/usb4g-watchdog
ExecStart=/usr/local/sbin/usb4g-watchdog.sh
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
UNIT_EOF

    if [ -f /etc/default/usb4g-watchdog ]; then
        log "  /etc/default/usb4g-watchdog - уже существует, не трогаю"
    else
        log "  /etc/default/usb4g-watchdog (новый)"
        cat > /etc/default/usb4g-watchdog <<'DEFAULT_EOF'
# usb4g-watchdog: конфигурация (комментарии к параметрам - в /usr/local/sbin/usb4g-watchdog.sh)
# RNDIS-интерфейс 4G-модема
IFACE=usb0
# Шлюз RNDIS-сети модема
GW=192.168.88.1
# Интервал проверок, сек
CHECK_INTERVAL=60
# Сколько последовательных неудачных проверок до вмешательства
FAIL_THRESHOLD=5
# Таймаут одного TCP-проба, сек
PROBE_TIMEOUT=4
# Пауза после ступени восстановления перед повторной проверкой, сек
RESET_WAIT=120
# Кулдаун между вмешательствами, сек (переживает reboot - файл состояния в /var/lib)
COOLDOWN=1800
# Имя регулятора VBUS порта модема (опционально).
# NanoPi R1: если модем на OTG-порте - usb0-vbus (enable GPIO PL2); тогда
# ступень 1 = точечное обесточивание порта, обычно достаточное.
# Pi Zero 2W + Waveshare USB HUB HAT (B): регулятора нет - оставить пустым.
VBUS_REG=
# Сколько секунд держать VBUS обесточенным, сек
VBUS_OFF_TIME=5
DEFAULT_EOF
    fi

    systemctl daemon-reload
    systemctl enable usb4g-watchdog
    systemctl restart usb4g-watchdog
    log "  OK: журнал - journalctl -u usb4g-watchdog -f"
}

[ "${SKIP_AIRBAND:-0}" = "1" ] || install_airband
[ "${SKIP_WEBPANEL:-0}" = "1" ] || install_webpanel
[ "${SKIP_WATCHDOG:-0}" = "1" ] || install_watchdog

log "=== Установка завершена ==="
log "Следующие шаги:"
log "  1. открыть http://<IP устройства>/"
log "  2. создать пресет (scan/receive) и применить - будет создан конфиг"
log "     $RTL_AIRBAND_CONF и запущена служба rtl_airband"
log "  3. проверка: systemctl status rtl_airband; journalctl -u rtl_airband -f"
