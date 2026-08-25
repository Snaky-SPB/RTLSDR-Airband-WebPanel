<?php

declare(strict_types=1);

namespace AirbandWebPanel\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use AirbandWebPanel\Exceptions\WriteException;
use AirbandWebPanel\Services\DeviceService;
use AirbandWebPanel\Services\PresetService;

class DeviceHandler
{
    private DeviceService $deviceService;
    private PresetService $presetService;

    public function __construct(DeviceService $deviceService, PresetService $presetService)
    {
        $this->deviceService = $deviceService;
        $this->presetService = $presetService;
    }

    /**
     * GET /api/devices - список устройств
     */
    public function list(Request $request, Response $response): Response
    {
        return $this->respond($response, ['success' => true, 'data' => $this->deviceService->list()]);
    }

    /**
     * GET /api/devices/{name}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $device = $this->deviceService->find($name);
        if ($device === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        return $this->respond($response, ['success' => true, 'data' => $device]);
    }

    /**
     * POST /api/devices - создать устройство
     * Body: { name, type, serial, correction, mode, preset (опционально) }
     */
    public function create(Request $request, Response $response): Response
    {
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $device = $this->deviceService->create($this->withPreset($input));
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 409);
        }
        return $this->respond($response, ['success' => true, 'data' => $device], 201);
    }

    /**
     * POST /api/devices/{name} - частичное обновление (mode, preset, correction, mp3outdir)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $device = $this->deviceService->update($name, $this->withPreset($input));
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 404);
        }
        return $this->respond($response, ['success' => true, 'data' => $device]);
    }

    /**
     * DELETE /api/devices/{name}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        if ($this->deviceService->find($name) === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        if (!$this->deviceService->delete($name)) {
            return $this->respond($response, ['success' => false, 'error' => 'Failed to delete device'], 500);
        }
        return $this->respond($response, ['success' => true]);
    }

    /**
     * Если указан preset — проверяем существование полосы
     */
    private function withPreset(array $input): array
    {
        $preset = trim((string)($input['preset'] ?? ''));
        if ($preset !== '' && $this->presetService->find($preset) === null) {
            throw new \InvalidArgumentException("Preset not found: $preset");
        }
        return $input;
    }

    private function respond(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
