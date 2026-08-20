<?php

declare(strict_types=1);

namespace RadioScanner\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RadioScanner\Services\ScannerStatusService;
use RadioScanner\Services\SystemInfoService;
use RadioScanner\Services\ConfigGeneratorService;

class SystemHandler
{
    private ScannerStatusService $statusService;
    private SystemInfoService $infoService;
    private ConfigGeneratorService $generator;

    public function __construct(
        ScannerStatusService $statusService,
        SystemInfoService $infoService,
        ConfigGeneratorService $generator
    ) {
        $this->statusService = $statusService;
        $this->infoService = $infoService;
        $this->generator = $generator;
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
                    'mode' => $this->generator->getActiveMode(),
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
