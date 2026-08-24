#!/bin/bash
#
# Установка системы radio-scanner на конечное устройство (Debian/Ubuntu, например NanoPi R1):
#   [1/2] RTLSDR-Airband (rtl_airband): apt-зависимости, сборка, установка, systemd-служба
#   [2/2] RTLSDR-Airband-WebPanel: PHP + Apache + Composer, vhost, sudoers
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
WEBPANEL_APP_DIR="/opt/radio-scanner-web"

log() { echo "[install] $*"; }
die() { echo "[install] ERROR: $*" >&2; exit 1; }

usage() { sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }
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

# Каталог исходников rtl_airband: соседняя копия (развёрнутое дерево
# radio-scanner/) в приоритете, иначе клонируем в CLONE_DIR_AIRBAND.
AIRBAND_DIR="${AIRBAND_DIR:-}"
if [ -z "$AIRBAND_DIR" ]; then
    if [ -d "$SCRIPT_ROOT/RTLSDR-Airband/.git" ]; then
        AIRBAND_DIR="$SCRIPT_ROOT/RTLSDR-Airband"
    else
        AIRBAND_DIR="$CLONE_DIR_AIRBAND"
    fi
fi

install_airband() {
    log "=== [1/2] RTLSDR-Airband (rtl_airband) ==="

    log "  apt-зависимости..."
    apt-get update -qq
    apt-get install -y -qq \
        build-essential cmake git pkg-config curl rsync ca-certificates \
        libmp3lame-dev libshout3-dev libconfig++-dev libfftw3-dev librtlsdr-dev

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

    log "  сборка: Release, NFM, RTL-SDR, PLATFORM=$PLATFORM"
    cmake -S "$AIRBAND_DIR" -B "$AIRBAND_DIR/build" \
        -DCMAKE_BUILD_TYPE=Release \
        -DNFM=ON -DRTLSDR=ON -DMIRISDR=OFF -DSOAPYSDR=OFF -DPULSEAUDIO=OFF \
        -DPLATFORM="$PLATFORM"
    cmake --build "$AIRBAND_DIR/build" -j"$(nproc)"

    log "  установка: cmake --install -> /usr/local/bin/rtl_airband"
    cmake --install "$AIRBAND_DIR/build"

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
    log "=== [2/2] RTLSDR-Airband-WebPanel ==="

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
        if [ -d "$dir/.git" ]; then
            log "  обновление: $dir -> $WEBPANEL_BRANCH"
            git -C "$dir" fetch origin
            git -C "$dir" checkout -B "$WEBPANEL_BRANCH" "origin/$WEBPANEL_BRANCH"
        else
            log "  клонирование: $WEBPANEL_REPO ($WEBPANEL_BRANCH) -> $dir"
            mkdir -p "$(dirname "$dir")"
            git clone --quiet -b "$WEBPANEL_BRANCH" "$WEBPANEL_REPO" "$dir"
        fi
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

[ "${SKIP_AIRBAND:-0}" = "1" ] || install_airband
[ "${SKIP_WEBPANEL:-0}" = "1" ] || install_webpanel

log "=== Установка завершена ==="
log "Следующие шаги:"
log "  1. открыть http://<IP устройства>/"
log "  2. создать пресет (scan/receive) и применить - будет создан конфиг"
log "     $RTL_AIRBAND_CONF и запущена служба rtl_airband"
log "  3. проверка: systemctl status rtl_airband; journalctl -u rtl_airband -f"
