<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use AirbandWebPanel\Services\FileScannerService;
use AirbandWebPanel\Services\ScannerStatusService;
use AirbandWebPanel\Services\SystemInfoService;
use AirbandWebPanel\Services\ConfigGeneratorService;
use AirbandWebPanel\Services\DeviceService;
use AirbandWebPanel\Services\ScanPresetService;
use AirbandWebPanel\Handlers\FrequencyHandler;
use AirbandWebPanel\Handlers\SystemHandler;
use AirbandWebPanel\Handlers\DeviceHandler;

require __DIR__ . '/../vendor/autoload.php';

// Создание приложения
$app = AppFactory::create();

// Middleware для обработки ошибок
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Middleware для парсинга тела запроса (JSON)
$app->addBodyParsingMiddleware();

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
$deviceService = new DeviceService();
$scanPresets = new ScanPresetService();

$frequencyHandler = new FrequencyHandler($fileScannerService);
$systemHandler = new SystemHandler($scannerStatusService, $systemInfoService, $configGenerator, $deviceService);
$deviceHandler = new DeviceHandler($deviceService, $scanPresets);

// Страница 1: сервис + устройства
$app->get('/', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/dashboard.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// Страница 2: частоты устройства
$app->get('/device/{name}', function (Request $request, Response $response, array $args) use ($deviceService): Response {
    $name = (string)($args['name'] ?? '');
    if ($deviceService->find($name) === null) {
        $response->getBody()->write("Device not found: $name");
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
    $html = file_get_contents(__DIR__ . '/device.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// API: Устройства
$app->get('/api/devices', [$deviceHandler, 'list']);
$app->get('/api/devices/{name}', [$deviceHandler, 'get']);
$app->post('/api/devices', [$deviceHandler, 'create']);
$app->post('/api/devices/{name}', [$deviceHandler, 'update']);
$app->delete('/api/devices/{name}', [$deviceHandler, 'delete']);
$app->post('/api/devices/{name}/apply-preset', [$deviceHandler, 'applyPreset']);
$app->post('/api/devices/{name}/worklist', [$deviceHandler, 'addWorklist']);
$app->post('/api/devices/{name}/worklist/remove', [$deviceHandler, 'removeWorklist']);
$app->post('/api/devices/{name}/worklist/toggle', [$deviceHandler, 'toggleWorklist']);

// API: Scan-пресеты (только чтение)
$app->get('/api/presets', function (Request $request, Response $response) use ($scanPresets): Response {
    $response->getBody()->write(json_encode([
        'success' => true,
        'data' => $scanPresets->list(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
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

// API: Автозагрузка
$app->post('/api/system/service/enable', [$systemHandler, 'enableService']);
$app->post('/api/system/service/disable', [$systemHandler, 'disableService']);

// API: Генерация конфига
$app->post('/api/system/config/generate', [$systemHandler, 'generateConfig']);

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
