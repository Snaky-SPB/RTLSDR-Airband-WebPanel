<?php

declare(strict_types=1);

namespace AirbandWebPanel\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use AirbandWebPanel\Exceptions\WriteException;
use AirbandWebPanel\Services\DeviceService;
use AirbandWebPanel\Services\PresetService;

class PresetHandler
{
    private PresetService $presetService;
    private DeviceService $deviceService;

    public function __construct(PresetService $presetService, DeviceService $deviceService)
    {
        $this->presetService = $presetService;
        $this->deviceService = $deviceService;
    }

    /**
     * GET /api/presets - список полос
     */
    public function list(Request $request, Response $response): Response
    {
        return $this->respond($response, ['success' => true, 'data' => $this->presetService->list()]);
    }

    /**
     * GET /api/presets/{name}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $band = $this->presetService->find($name);
        if ($band === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }
        return $this->respond($response, ['success' => true, 'data' => $band]);
    }

    /**
     * POST /api/presets - создать полосу
     * Body: { name, gain, freq_from, freq_to, channel_step, scan_fname (опц.), work_list (опц.) }
     */
    public function create(Request $request, Response $response): Response
    {
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $band = $this->presetService->create($input);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 409);
        }
        return $this->respond($response, ['success' => true, 'data' => $band], 201);
    }

    /**
     * POST /api/presets/{name} - частичное обновление полосы
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $input = (array)($request->getParsedBody() ?? []);
        try {
            $band = $this->presetService->update($name, $input);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 404);
        }
        return $this->respond($response, ['success' => true, 'data' => $band]);
    }

    /**
     * DELETE /api/presets/{name}
     * 409, если полосу использует хотя бы одно устройство
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        if ($this->presetService->find($name) === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }
        $users = $this->deviceService->findUsers($name);
        if ($users !== []) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset is used by: ' . implode(', ', $users),
            ], 409);
        }
        if (!$this->presetService->delete($name)) {
            return $this->respond($response, ['success' => false, 'error' => 'Failed to delete preset'], 500);
        }
        return $this->respond($response, ['success' => true]);
    }

    /**
     * POST /api/presets/{name}/worklist - добавить частоту в полосу
     * Body: { "freq": "172.8125", "label": "T" } (label опционален)
     */
    public function addWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $band = $this->presetService->find($name);
        if ($band === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
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
        $band['work_list'][$freqKey] = ['label' => $label, 'enabled' => true];

        try {
            $this->presetService->update($name, ['work_list' => $band['work_list']]);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true, 'data' => ['freq' => $freqKey, 'label' => $label]]);
    }

    /**
     * POST /api/presets/{name}/worklist/remove
     * Body: { "freq": "172.8125" }
     */
    public function removeWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $band = $this->presetService->find($name);
        if ($band === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $freq = trim((string)($body['freq'] ?? ''));
        $freqKey = $freq !== '' ? sprintf('%.4f', (float)$freq) : '';

        $workList = $band['work_list'] ?? [];
        unset($workList[$freqKey]);

        try {
            $this->presetService->update($name, ['work_list' => $workList]);
        } catch (WriteException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, ['success' => false, 'error' => $e->getMessage()], 400);
        }
        return $this->respond($response, ['success' => true]);
    }

    /**
     * POST /api/presets/{name}/worklist/toggle - включить/выключить частоту
     * Body: { "freq": "172.8125" }
     */
    public function toggleWorklist(Request $request, Response $response, array $args): Response
    {
        $name = (string)($args['name'] ?? '');
        $band = $this->presetService->find($name);
        if ($band === null) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $freq = trim((string)($body['freq'] ?? ''));
        $freqKey = $freq !== '' ? sprintf('%.4f', (float)$freq) : '';

        $workList = $band['work_list'] ?? [];
        if (!isset($workList[$freqKey])) {
            return $this->respond($response, ['success' => false, 'error' => 'Frequency not in work_list'], 404);
        }
        $entry = $workList[$freqKey];
        $enabled = is_array($entry) ? (bool)($entry['enabled'] ?? true) : true;
        $workList[$freqKey] = ['label' => is_array($entry) ? $entry['label'] : (string)$entry, 'enabled' => !$enabled];

        try {
            $this->presetService->update($name, ['work_list' => $workList]);
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
