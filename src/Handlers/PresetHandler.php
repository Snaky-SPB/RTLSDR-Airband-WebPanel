<?php

declare(strict_types=1);

namespace RadioScanner\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RadioScanner\Services\ConfigGeneratorService;

class PresetHandler
{
    private string $presetsFile;
    private ConfigGeneratorService $generator;

    public function __construct(ConfigGeneratorService $generator, ?string $presetsFile = null)
    {
        $this->generator = $generator;
        $this->presetsFile = $presetsFile ?? __DIR__ . '/../../presets.json';
    }

    private function loadPresets(): array
    {
        if (!file_exists($this->presetsFile)) {
            return [];
        }
        $json = file_get_contents($this->presetsFile);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function savePresets(array $presets): bool
    {
        return file_put_contents($this->presetsFile, json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }

    private function respond(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function list(Request $request, Response $response): Response
    {
        $presets = $this->loadPresets();
        $active = $this->generator->getActivePresetName();
        return $this->respond($response, [
            'success' => true,
            'data' => $presets,
            'active' => $active,
        ]);
    }

    public function getActive(Request $request, Response $response): Response
    {
        $name = $this->generator->getActivePresetName();
        return $this->respond($response, [
            'success' => true,
            'data' => ['name' => $name],
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getBody()->getContents();
        $preset = json_decode($body, true);
        if (!is_array($preset) || empty($preset['name'])) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid preset data: name is required',
            ], 400);
        }
        $presets = $this->loadPresets();
        foreach ($presets as $p) {
            if ($p['name'] === $preset['name']) {
                return $this->respond($response, [
                    'success' => false,
                    'error' => 'Preset already exists: ' . $preset['name'],
                ], 409);
            }
        }
        $presets[] = $preset;
        $this->savePresets($presets);
        return $this->respond($response, [
            'success' => true,
            'data' => $preset,
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        if ($name === '') {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Name is required',
            ], 400);
        }
        $presets = $this->loadPresets();
        $found = false;
        foreach ($presets as $i => $p) {
            if ($p['name'] === $name) {
                array_splice($presets, $i, 1);
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }
        $this->savePresets($presets);
        return $this->respond($response, ['success' => true]);
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        if ($name === '') {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Name is required',
            ], 400);
        }
        $presets = $this->loadPresets();
        $preset = null;
        foreach ($presets as $p) {
            if ($p['name'] === $name) {
                $preset = $p;
                break;
            }
        }
        if ($preset === null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }
        $config = $this->generator->generate($preset);
        $ok = $this->generator->apply($config);
        if (!$ok) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to apply preset (write config or restart scanner)',
            ], 500);
        }
        return $this->respond($response, [
            'success' => true,
            'data' => ['name' => $name, 'action' => 'applied'],
        ]);
    }

    private function findPresetByName(string $name): ?array
    {
        foreach ($this->loadPresets() as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }
        return null;
    }

    private function updatePreset(string $name, array $updated): bool
    {
        $presets = $this->loadPresets();
        foreach ($presets as $i => $p) {
            if ($p['name'] === $name) {
                $presets[$i] = $updated;
                return $this->savePresets($presets);
            }
        }
        return false;
    }

    public function addWhitelist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = $body['freq'] ?? '';
        $label = $body['label'] ?? 'marked';

        if ($name === '' || $freq === '') {
            return $this->respond($response, ['success' => false, 'error' => 'name and freq are required'], 400);
        }

        $preset = $this->findPresetByName($name);
        if (!$preset) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }

        if (!isset($preset['white_list']) || !is_array($preset['white_list'])) {
            $preset['white_list'] = [];
        }
        $preset['white_list'][$freq] = $label;
        $this->updatePreset($name, $preset);

        return $this->respond($response, ['success' => true, 'data' => ['freq' => $freq, 'label' => $label]]);
    }

    public function removeWhitelist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = $body['freq'] ?? '';

        if ($name === '' || $freq === '') {
            return $this->respond($response, ['success' => false, 'error' => 'name and freq are required'], 400);
        }

        $preset = $this->findPresetByName($name);
        if (!$preset) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }

        if (isset($preset['white_list'][$freq])) {
            unset($preset['white_list'][$freq]);
            $this->updatePreset($name, $preset);
        }

        return $this->respond($response, ['success' => true]);
    }

    public function addBlacklist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = $body['freq'] ?? '';

        if ($name === '' || $freq === '') {
            return $this->respond($response, ['success' => false, 'error' => 'name and freq are required'], 400);
        }

        $preset = $this->findPresetByName($name);
        if (!$preset) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }

        if (!isset($preset['black_list']) || !is_array($preset['black_list'])) {
            $preset['black_list'] = [];
        }
        if (!in_array($freq, $preset['black_list'])) {
            $preset['black_list'][] = $freq;
        }
        $this->updatePreset($name, $preset);

        return $this->respond($response, ['success' => true, 'data' => ['freq' => $freq]]);
    }

    public function removeBlacklist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = $body['freq'] ?? '';

        if ($name === '' || $freq === '') {
            return $this->respond($response, ['success' => false, 'error' => 'name and freq are required'], 400);
        }

        $preset = $this->findPresetByName($name);
        if (!$preset) {
            return $this->respond($response, ['success' => false, 'error' => 'Preset not found'], 404);
        }

        if (isset($preset['black_list']) && is_array($preset['black_list'])) {
            $preset['black_list'] = array_values(array_filter($preset['black_list'], fn($f) => $f !== $freq));
            $this->updatePreset($name, $preset);
        }

        return $this->respond($response, ['success' => true]);
    }
}
