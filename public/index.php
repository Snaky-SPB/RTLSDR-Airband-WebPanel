<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use RadioScanner\Services\FileScannerService;
use RadioScanner\Services\ScannerStatusService;
use RadioScanner\Services\SystemInfoService;
use RadioScanner\Services\ConfigGeneratorService;
use RadioScanner\Handlers\FrequencyHandler;
use RadioScanner\Handlers\SystemHandler;
use RadioScanner\Handlers\PresetHandler;

require __DIR__ . '/../vendor/autoload.php';

// Создание приложения
$app = AppFactory::create();

// Middleware для обработки ошибок
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Middleware для CORS (для разработки)
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
});

// Инициализация сервисов и view
$fileScannerService = new FileScannerService('/media/rx/sources');
$scannerStatusService = new ScannerStatusService();
$systemInfoService = new SystemInfoService();

$configGenerator = new ConfigGeneratorService($scannerStatusService);
$frequencyHandler = new FrequencyHandler($fileScannerService);
$systemHandler = new SystemHandler($scannerStatusService, $systemInfoService);
$presetHandler = new PresetHandler($configGenerator);

$view = new PhpRenderer(__DIR__ . '/../templates');

// Главная страница
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write('
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Radio Scanner</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; background: #1a1a2e; color: #eee; }
                h1 { color: #00d9ff; }
                .status { padding: 20px; background: #16213e; border-radius: 8px; margin-top: 20px; }
                .info { color: #888; }
                a { color: #00d9ff; }
            </style>
        </head>
        <body>
            <h1>📻 Radio Scanner Web Interface</h1>
            <div class="status">
                <h2>Статус</h2>
                <p>Приложение запущено успешно!</p>
                <p><a href="/app">Перейти к плееру</a></p>
                <p class="info">API: <code>/api/frequencies</code>, <code>/api/system/status</code></p>
            </div>
        </body>
        </html>
    ');
    return $response;
});

// Страница приложения (статический HTML)
$app->get('/app', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/app.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// API: Список частот
$app->get('/api/frequencies', [$frequencyHandler, 'list']);

// API: Файлы частоты
$app->get('/api/frequencies/{frequency}/{receiver}', [$frequencyHandler, 'getFiles']);

// API: Удалить файлы частоты
$app->post('/api/frequencies/{frequency}/{receiver}/delete', [$frequencyHandler, 'delete']);

// API: Метаданные файла
$app->get('/api/files/{filename}/meta', [$frequencyHandler, 'getFileMeta']);

// API: Поток файла
$app->get('/api/files/{filename}/stream', [$frequencyHandler, 'streamFile']);

// API: Статус системы
$app->get('/api/system/status', [$systemHandler, 'getStatus']);

// API: Управление сканером
$app->post('/api/system/scanner/restart', [$systemHandler, 'restartScanner']);
$app->post('/api/system/scanner/stop', [$systemHandler, 'stopScanner']);
$app->post('/api/system/scanner/start', [$systemHandler, 'startScanner']);

// API: Пресеты конфигурации
$app->get('/api/presets', [$presetHandler, 'list']);
$app->get('/api/presets/active', [$presetHandler, 'getActive']);
$app->post('/api/presets', [$presetHandler, 'create']);
$app->delete('/api/presets/{name}', [$presetHandler, 'delete']);
$app->post('/api/presets/{name}/apply', [$presetHandler, 'apply']);
$app->post('/api/presets/{name}/whitelist', [$presetHandler, 'addWhitelist']);
$app->post('/api/presets/{name}/whitelist/remove', [$presetHandler, 'removeWhitelist']);
$app->post('/api/presets/{name}/blacklist', [$presetHandler, 'addBlacklist']);
$app->post('/api/presets/{name}/blacklist/remove', [$presetHandler, 'removeBlacklist']);

// Health check
$app->get('/api/health', function (Request $request, Response $response) {
    $data = [
        'status' => 'ok',
        'timestamp' => date('c'),
        'php_version' => PHP_VERSION
    ];
    
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
