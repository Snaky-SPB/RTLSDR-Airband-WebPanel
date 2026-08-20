#!/bin/bash

# Radio Scanner Web Interface - Installation Script
# Устанавливает все зависимости и настраивает приложение на сервере

set -e

echo "=== Radio Scanner Web Interface - Installation ==="
echo ""

# Проверка прав root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Запустите скрипт от root (sudo ./install.sh)"
    exit 1
fi

# Конфигурация
APP_DIR="/opt/radio-scanner-web"
SOURCE_DIR="${SOURCE_DIR:-$(cd "$(dirname "$0")" && pwd)}"
LOG_DIR="/var/log/radio-scanner"
SOURCES_DIR="/media/rx/sources"
RTL_AIRBAND_CONF="/etc/rtl_airband.conf"
WEB_PORT="80"

echo "Конфигурация:"
echo "  App directory: $APP_DIR"
echo "  Source directory: $SOURCE_DIR"
echo "  Log directory: $LOG_DIR"
echo "  Sources directory: $SOURCES_DIR"
echo "  rtl_airband config: $RTL_AIRBAND_CONF"
echo "  Web port: $WEB_PORT"
echo ""

# Шаг 1: Обновление пакетов
echo "[1/10] Обновление пакетов..."
apt-get update -qq

# Шаг 2: Установка PHP и веб-сервера
echo "[2/10] Установка PHP и Apache..."
apt-get install -y -qq php php-cli php-mbstring php-xml php-curl php-zip apache2 libapache2-mod-php

# Шаг 3: Установка Composer
echo "[3/10] Установка Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
else
    echo "  Composer уже установлен"
fi

# Шаг 4: Создание директорий
echo "[4/10] Создание директорий..."
mkdir -p "$APP_DIR"
mkdir -p "$LOG_DIR"
mkdir -p "$SOURCES_DIR"

# Шаг 5: Копирование файлов проекта
echo "[5/10] Копирование файлов проекта..."
if [ -d "$SOURCE_DIR" ]; then
    rsync -a --delete \
        --exclude='vendor/' \
        --exclude='var/' \
        --exclude='.git/' \
        --exclude='node_modules/' \
        --exclude='presets/' \
        "$SOURCE_DIR/" "$APP_DIR/"
    echo "  Файлы скопированы из $SOURCE_DIR"
    mkdir -p "$APP_DIR/presets"
    echo "  Каталог presets/ готов"
else
    echo "  WARNING: Исходный каталог $SOURCE_DIR не найден, пропускаем"
fi

# Шаг 6: Настройка прав доступа
echo "[6/10] Настройка прав доступа..."
# Проверка существования директора rtl_airband
if [ -d "$SOURCES_DIR" ]; then
    chown -R www-data:www-data "$SOURCES_DIR" 2>/dev/null || true
    chmod 755 "$SOURCES_DIR"
fi

chown -R www-data:www-data "$LOG_DIR"
chown -R www-data:www-data "$APP_DIR"

# Шаг 7: Настройка Apache
echo "[7/10] Настройка Apache..."
# Отключаем дефолтный сайт
a2dissite 000-default.conf 2>/dev/null || true

# Создаём конфигурацию для приложения
cat > /etc/apache2/sites-available/radio-scanner.conf << EOF
<VirtualHost *:$WEB_PORT>
    ServerAdmin webmaster@localhost
    DocumentRoot $APP_DIR/public

    <Directory $APP_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog $LOG_DIR/error.log
    CustomLog $LOG_DIR/access.log combined
</VirtualHost>
EOF

a2ensite radio-scanner.conf
a2enmod rewrite

# Шаг 8: Настройка sudo для управления сканером
echo "[8/10] Настройка sudo для www-data..."

create_sudoers() {
    local FILE=$1
    local RULE=$2
    if [ ! -f "$FILE" ]; then
        echo "$RULE" > "$FILE"
        chmod 440 "$FILE"
        visudo -c -f "$FILE" && echo "  ${FILE##*/}: OK" || echo "  ${FILE##*/}: FAILED"
    else
        echo "  ${FILE##*/}: уже существует"
    fi
}

create_sudoers /etc/sudoers.d/www-data-rtl-airband \
    'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart rtl_airband, /usr/bin/systemctl stop rtl_airband, /usr/bin/systemctl start rtl_airband'

create_sudoers /etc/sudoers.d/www-data-rtl-airband-cp \
    'www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/rtl_airband_tmp /usr/local/etc/rtl_airband.conf'

# Шаг 9: Установка зависимостей PHP
echo "[9/10] Установка PHP зависимостей..."
cd "$APP_DIR"

# Создаём composer.json если не существует
if [ ! -f "composer.json" ]; then
    cat > composer.json << 'EOF'
{
    "name": "radio-scanner/web-interface",
    "description": "Web interface for rtl_airband radio scanner",
    "type": "project",
    "require": {
        "php": "^8.1",
        "slim/slim": "^4.0",
        "slim/php-view": "^3.0",
        "slim/flash": "^0.4.0",
        "twig/twig": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "RadioScanner\\": "src/"
        }
    },
    "config": {
        "sort-packages": true
    }
}
EOF
fi

composer install --no-interaction

# Шаг 10: Перезапуск Apache
echo "[10/10] Перезапуск Apache..."
systemctl restart apache2

echo ""
echo "=== Установка завершена ==="
echo ""
echo "Следующие шаги:"
echo "  1. Настройте SOURCE_DIR=$SOURCE_DIR (или укажите переменную окружения)"
echo "  2. Разместите исходные файлы в SOURCE_DIR"
echo "  3. Откройте http://$(hostname -I | awk '{print $1}') в браузере"
echo ""
echo "Логи:"
echo "  Apache: /var/log/apache2/"
echo "  Приложение: $LOG_DIR/"
echo ""
