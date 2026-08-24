# Radio Scanner Web Interface

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

### Установка только веб-панели (на существующей системе)

```bash
sudo ./install.sh    # первый раз
sudo ./deploy.sh     # обновление: rsync в /opt/radio-scanner-web + reload apache
```

### Доступ

Откройте в браузере: `http://192.168.0.111/`

## Структура проекта

```
.
├── public/              # Точка входа (index.php)
├── src/                 # Исходный код PHP
│   ├── Handlers/        # API обработчики
│   ├── Services/        # Бизнес-логика
│   └── Models/          # Модели данных
├── templates/           # Twig шаблоны
├── config/              # Конфигурация
├── install.sh           # Скрипт установки
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
