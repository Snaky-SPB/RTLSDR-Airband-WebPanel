<?php

declare(strict_types=1);

namespace AirbandWebPanel\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use AirbandWebPanel\Exceptions\WriteException;
use AirbandWebPanel\Services\DeviceService;
use AirbandWebPanel\Services\ScanPresetService;

class DeviceHandler
{
    private DeviceService $deviceService;
    private ScanPresetService $scanPresets;

    public function __construct(DeviceService $deviceService, ScanPresetService $scanPresets)
    {
        $this->deviceService = $deviceService;
        $this->scanPresets = $scanPresets;
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
     */
    public function create(Request $request, Response $response): Response
    {
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $device = $this->deviceService->create($input);
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
     * POST /api/devices/{name} - частичное обновление (режим, диапазон, scan_fname и т.д.)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $device = $this->deviceService->update($name, $input);
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
     * POST /api/devices/{name}/apply-preset - применить scan-пресет к устройству
     * Body: { "preset": "172-173" }
     */
    public function applyPreset(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $device = $this->deviceService->find($name);
        if ($device === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        $presetName = trim((string)(($request->getParsedBody() ?? [])['preset'] ?? ''));
        $preset = $this->scanPresets->find($presetName);
        if ($preset === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }

        $device['mode'] = 'wideband_scan';
        $device['freq_from'] = $preset['freq_from'];
        $device['freq_to'] = $preset['freq_to'];
        $device['channel_step'] = $preset['channel_step'];
        $device['scan_fname'] = DeviceService::scanFnameFromName($preset['name']);

        try {
            $device = $this->deviceService->update($name, $device);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true, 'data' => $device]);
    }

    /**
     * POST /api/devices/{name}/worklist - добавить частоту
     * Body: { "freq": "172.8125", "label": "T" } (label опционален)
     */
    public function addWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $device = $this->deviceService->find($name);
        if ($device === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $freq = trim((string)($body['freq'] ?? ''));
        if ($freq === '' || !is_numeric($freq)) {
            return $this->respond($response, ['success' => false, 'error' => 'Invalid frequency'], 400);
        }
        $label = trim((string)($body['label'] ?? ''));
        if ($label === '') {
            $label = 'marked';
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
            return $this->respond($response, ['success' => false, 'error' => 'Invalid label'], 400);
        }

        $freqKey = sprintf('%.4f', (float)$freq);
        $device['work_list'][$freqKey] = ['label' => $label, 'enabled' => true];

        try {
            $device = $this->deviceService->update($name, ['work_list' => $device['work_list']]);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true, 'data' => ['freq' => $freqKey, 'label' => $label]]);
    }

    /**
     * POST /api/devices/{name}/worklist/remove
     * Body: { "freq": "172.8125" }
     */
    public function removeWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $device = $this->deviceService->find($name);
        if ($device === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $freq = trim((string)($body['freq'] ?? ''));
        $freqKey = $freq !== '' ? sprintf('%.4f', (float)$freq) : '';

        $device['work_list'] = $device['work_list'] ?? [];
        unset($device['work_list'][$freqKey]);

        try {
            $device = $this->deviceService->update($name, ['work_list' => $device['work_list']]);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true]);
    }

    /**
     * POST /api/devices/{name}/worklist/toggle - включить/выключить частоту
     * Body: { "freq": "172.8125" }
     */
    public function toggleWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $device = $this->deviceService->find($name);
        if ($device === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Device not found'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $freq = trim((string)($body['freq'] ?? ''));
        $freqKey = $freq !== '' ? sprintf('%.4f', (float)$freq) : '';

        $workList = $device['work_list'] ?? [];
        if (!isset($workList[$freqKey])) {
            return $this->respond($response, ['success' => false, 'error' => 'Frequency not in work_list'], 404);
        }
        $entry = $workList[$freqKey];
        $enabled = is_array($entry) ? (bool)($entry['enabled'] ?? true) : true;
        $workList[$freqKey] = ['label' => is_array($entry) ? $entry['label'] : (string)$entry, 'enabled' => !$enabled];

        try {
            $device = $this->deviceService->update($name, ['work_list' => $workList]);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true, 'data' => ['freq' => $freqKey, 'enabled' => !$enabled]]);
    }

    private function respond(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
