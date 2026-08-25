<?php

declare(strict_types=1);

namespace AirbandWebPanel\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use AirbandWebPanel\Services\ScannerStatusService;
use AirbandWebPanel\Services\SystemInfoService;
use AirbandWebPanel\Services\ConfigGeneratorService;
use AirbandWebPanel\Services\DeviceService;

class SystemHandler
{
    private ScannerStatusService $statusService;
    private SystemInfoService $infoService;
    private ConfigGeneratorService $generator;
    private DeviceService $deviceService;

    public function __construct(
        ScannerStatusService $statusService,
        SystemInfoService $infoService,
        ConfigGeneratorService $generator,
        DeviceService $deviceService
    ) {
        $this->statusService = $statusService;
        $this->infoService = $infoService;
        $this->generator = $generator;
        $this->deviceService = $deviceService;
    }

    /**
     * GET /api/system/status - статус системы
     */
    public function getStatus(Request $request, Response $response): Response
    {
        $scannerRunning = $this->statusService->isRunning();
        $diskUsage = $this->infoService->getDiskUsage('/media/rx');
        $memoryUsage = $this->infoService->getMemoryUsage();
        $systemInfo = $this->infoService->getSystemInfo();

        $data = [
            'success' => true,
            'data' => [
                'scanner' => [
                    'running' => $scannerRunning,
                    'status' => $scannerRunning ? 'running' : 'stopped',
                    'enabled' => $this->statusService->isEnabled(),
                    'modes' => $this->generator->getDeviceModes(),
                ],
                'disk' => [
                    'total' => $this->formatBytes($diskUsage['total']),
                    'used' => $this->formatBytes($diskUsage['used']),
                    'free' => $this->formatBytes($diskUsage['free']),
                    'percent' => $diskUsage['percent'],
                ],
                'memory' => $memoryUsage,
                'uptime' => $systemInfo['uptime'],
                'hostname' => $systemInfo['hostname'],
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/system/scanner/restart - перезапуск rtl_airband
     */
    public function restartScanner(Request $request, Response $response): Response
    {
        $success = $this->statusService->restart();

        $data = [
            'success' => $success,
            'data' => [
                'action' => 'restart',
                'result' => $success ? 'ok' : 'failed',
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($success ? 200 : 500)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/system/scanner/stop - остановка rtl_airband
     */
    public function stopScanner(Request $request, Response $response): Response
    {
        $success = $this->statusService->stop();

        $data = [
            'success' => $success,
            'data' => [
                'action' => 'stop',
                'result' => $success ? 'ok' : 'failed',
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($success ? 200 : 500)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/system/scanner/start - запуск rtl_airband
     */
    public function startScanner(Request $request, Response $response): Response
    {
        $success = $this->statusService->start();

        $data = [
            'success' => $success,
            'data' => [
                'action' => 'start',
                'result' => $success ? 'ok' : 'failed',
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($success ? 200 : 500)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/system/service/enable - включить rtl_airband на автозагрузке
     */
    public function enableService(Request $request, Response $response): Response
    {
        return $this->serviceAction($response, 'enable', $this->statusService->enable());
    }

    /**
     * POST /api/system/service/disable - выключить rtl_airband с автозагрузки
     */
    public function disableService(Request $request, Response $response): Response
    {
        return $this->serviceAction($response, 'disable', $this->statusService->disable());
    }

    /**
     * POST /api/system/config/generate - генерация и запись конфига из всех устройств (без рестарта)
     */
    public function generateConfig(Request $request, Response $response): Response
    {
        $devices = $this->deviceService->list();
        try {
            $content = $this->generator->generate($devices);
        } catch (\InvalidArgumentException $e) {
            $data = ['success' => false, 'error' => $e->getMessage()];
            $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $success = $this->generator->write($content);
        $data = [
            'success' => $success,
            $success ? 'data' : 'error' => $success
                ? ['devices' => count($devices)]
                : 'failed to write config',
        ];
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($success ? 200 : 500)
            ->withHeader('Content-Type', 'application/json');
    }

    private function serviceAction(Response $response, string $action, bool $success): Response
    {
        $data = [
            'success' => $success,
            'data' => [
                'action' => $action,
                'result' => $success ? 'ok' : 'failed',
            ],
        ];
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($success ? 200 : 500)
            ->withHeader('Content-Type', 'application/json');
    }

    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 1) . ' ' . $units[$pow];
    }
}
