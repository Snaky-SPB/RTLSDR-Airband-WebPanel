# RTLSDR Airband WebPanel

Веб-интерфейс для управления радиосканером на базе rtl_airband.

## Назначение

- Прослушивание записей радиопереговоров
- Управление white/black списками частот
- Генерация и деплой конфигурации rtl_airband
- Мониторинг активности сканера

## Быстрый старт

### Установка всей системы на устройство (первый раз)

На устройстве (Debian/Ubuntu), от root — одной командой:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Snaky-SPB/RTLSDR-Airband-WebPanel/main/scripts/install-radio-scanner.sh)
```

Скрипт соберёт и установит сканер `rtl_airband` (systemd-служба `rtl_airband`) и веб-панель.
Параметры (репозитории, ветки, пропуск шагов): `scripts/install-radio-scanner.sh -h`.

### Пропуск шагов установки (точечное обновление)

Установка идёт тремя шагами: `rtl_airband` → веб-панель → `usb4g-watchdog`.
Каждый шаг можно пропустить переменной окружения:

| Переменная | Пропускает |
|------------|------------|
| `SKIP_AIRBAND=1` | сборку и установку `rtl_airband` |
| `SKIP_WEBPANEL=1` | установку/обновление веб-панели |
| `SKIP_WATCHDOG=1` | установку `usb4g-watchdog` |

Пример — обновить только watchdog (скрипт и systemd-юнит перезаписываются,
существующий `/etc/default/usb4g-watchdog` не трогается):

```bash
SKIP_AIRBAND=1 SKIP_WEBPANEL=1 bash <(curl -fsSL https://raw.githubusercontent.com/Snaky-SPB/RTLSDR-Airband-WebPanel/main/scripts/install-radio-scanner.sh)
```

### Установка только веб-панели (на существующей системе)

```bash
sudo ./install.sh    # первый раз
sudo ./deploy.sh     # обновление: rsync в /opt/rtl-sdr-airband-webpanel + reload apache
```

### Доступ

Откройте в браузере: `http://192.168.0.111/`

## Структура проекта

```
.
├── public/
│   ├── index.php        # Точка входа (Slim 4, роуты)
│   ├── dashboard.html   # Страница 1: сервис + устройства
│   └── device.html      # Страница 2: частоты устройства
├── src/                 # Исходный код PHP
│   ├── Handlers/        # API обработчики
│   └── Services/        # Бизнес-логика
├── devices/             # Устройства (один JSON на приёмник) — данные сервера
├── presets/             # Scan-пресеты (read-only, файлы вручную) — данные сервера
├── scripts/             # install-radio-scanner.sh (установка системы)
├── install.sh           # Установка веб-панели
├── deploy.sh            # Обновление (rsync)
└── wiki/                # Документация
```

## Требования

- PHP 8.1+
- Apache или Nginx
- Composer
- Доступ к `/media/rx/sources/`

## Разработка

```bash
# Локальный запуск для разработки
php -S localhost:8080 -t public
```

## Лицензия

MIT
