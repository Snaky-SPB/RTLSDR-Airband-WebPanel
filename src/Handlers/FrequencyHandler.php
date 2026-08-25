<?php

declare(strict_types=1);

namespace AirbandWebPanel\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use AirbandWebPanel\Services\FileScannerService;

class FrequencyHandler
{
    private FileScannerService $scannerService;

    public function __construct(FileScannerService $scannerService)
    {
        $this->scannerService = $scannerService;
    }

    /**
     * GET /api/frequencies - список всех частот
     */
    public function list(Request $request, Response $response): Response
    {
        $frequencies = $this->scannerService->getFrequencies();
        
        $data = [
            'success' => true,
            'data' => $frequencies,
            'count' => count($frequencies),
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/frequencies/{freq}/{receiver} - файлы конкретной частоты
     */
    public function getFiles(Request $request, Response $response, array $args): Response
    {
        $frequency = $args['frequency'] ?? '';
        $receiver = $args['receiver'] ?? '';

        if (empty($frequency) || empty($receiver)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Missing frequency or receiver',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $files = $this->scannerService->getFrequencyFiles($frequency, $receiver);

        $data = [
            'success' => true,
            'data' => [
                'frequency' => $frequency,
                'receiver' => $receiver,
                'files' => $files,
                'count' => count($files),
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/frequencies/{freq}/{receiver}/delete - удалить все файлы частоты
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $frequency = $args['frequency'] ?? '';
        $receiver = $args['receiver'] ?? '';

        if (empty($frequency) || empty($receiver)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Missing frequency or receiver',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $deleted = $this->scannerService->deleteFrequencyFiles($frequency, $receiver);

        $data = [
            'success' => true,
            'data' => [
                'deleted' => $deleted,
                'frequency' => $frequency,
                'receiver' => $receiver,
            ],
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/files/{filename}/meta - метаданные файла
     */
    public function getFileMeta(Request $request, Response $response, array $args): Response
    {
        $filename = $args['filename'] ?? '';

        if (empty($filename)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Missing filename',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Защита от path traversal
        $filename = basename($filename);

        $metadata = $this->scannerService->getFileMetadata($filename);

        if ($metadata === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'File not found',
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $data = [
            'success' => true,
            'data' => $metadata,
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/files/{filename}/stream - потоковое воспроизведение
     */
    public function streamFile(Request $request, Response $response, array $args): Response
    {
        $filename = $args['filename'] ?? '';

        if (empty($filename)) {
            $response->getBody()->write('File not found');
            return $response->withStatus(400);
        }

        // Защита от path traversal
        $filename = basename($filename);
        $filepath = '/media/rx/sources/' . $filename;

        if (!file_exists($filepath)) {
            $response->getBody()->write('File not found');
            return $response->withStatus(404);
        }

        $size = filesize($filepath);
        $stream = fopen($filepath, 'rb');

        if ($stream === false) {
            $response->getBody()->write('Cannot open file');
            return $response->withStatus(500);
        }

        $body = $response->getBody();
        $body->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Content-Length', (string)$size)
            ->withHeader('Accept-Ranges', 'bytes');
    }
}
