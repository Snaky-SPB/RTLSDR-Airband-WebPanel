#!/bin/bash

# RTLSDR Airband WebPanel - Deploy Script
# Быстрая синхронизация файлов из исходного каталога в рабочий

set -e

APP_DIR="/opt/rtl-sdr-airband-webpanel"
SOURCE_DIR="${SOURCE_DIR:-$(cd "$(dirname "$0")" && pwd)}"

# Проверка прав root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Запустите скрипт от root (sudo ./deploy.sh)"
    exit 1
fi

if [ ! -d "$SOURCE_DIR" ]; then
    echo "ERROR: Исходный каталог $SOURCE_DIR не найден"
    echo "Укажите переменную SOURCE_DIR, если путь отличается:"
    echo "  SOURCE_DIR=/путь/к/исходникам sudo ./deploy.sh"
    exit 1
fi

if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: Рабочий каталог $APP_DIR не найден"
    echo "Сначала запустите install.sh"
    exit 1
fi

echo "=== Deploy RTLSDR Airband WebPanel ==="
echo "  Source: $SOURCE_DIR"
echo "  Target: $APP_DIR"
echo ""

# Копирование (rsync --delete убирает удалённые файлы)
echo "Copying files..."
rsync -a --delete \
    --exclude='vendor/' \
    --exclude='var/' \
    --exclude='.git/' \
    --exclude='node_modules/' \
    --exclude='presets/' \
    --exclude='devices/' \
    "$SOURCE_DIR/" "$APP_DIR/"

# Каталоги данных (presets/, devices/) если ещё нет
mkdir -p "$APP_DIR/presets"
mkdir -p "$APP_DIR/devices"

# Права
echo "Setting permissions..."
chown -R www-data:www-data "$APP_DIR"

# Перезагрузка Apache (graceful = без разрыва соединений)
echo "Reloading Apache..."
systemctl reload apache2

echo ""
echo "=== Done ==="
