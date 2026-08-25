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
WEBPANEL_APP_DIR="/opt/rtl-sdr-airband-webpanel"

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
    log "=== [1/2] RTLSDR-Airband (rtl_airband) ==="

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
        if [ -d "$dir/.git" ] && ! git_tree_ok "$dir"; then
            log "  существующий клон $dir повреждён - удаляю"
            rm -rf "$dir"
        fi
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
