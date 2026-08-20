<?php

declare(strict_types=1);

namespace RadioScanner\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RadioScanner\Services\ConfigGeneratorService;

class PresetHandler
{
    private string $presetsDir;
    private ConfigGeneratorService $generator;

    public function __construct(ConfigGeneratorService $generator, ?string $presetsDir = null)
    {
        $this->generator = $generator;
        $this->presetsDir = $presetsDir ?? __DIR__ . '/../../presets';
    }

    private function isValidName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_starts_with($name, '.');
    }

    private function presetFile(string $name): string
    {
        return $this->presetsDir . '/' . $name . '.json';
    }

    private function loadPresets(): array
    {
        $presets = [];
        if (!is_dir($this->presetsDir)) {
            return $presets;
        }
        $files = glob($this->presetsDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $json = file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $preset = json_decode($json, true);
            if (is_array($preset) && !empty($preset['name'])) {
                $presets[] = $preset;
            }
        }
        usort($presets, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $presets;
    }

    private function findPreset(string $name): ?array
    {
        if (!$this->isValidName($name)) {
            return null;
        }
        $file = $this->presetFile($name);
        if (!file_exists($file)) {
            return null;
        }
        $json = file_get_contents($file);
        if ($json === false) {
            return null;
        }
        $preset = json_decode($json, true);
        return is_array($preset) ? $preset : null;
    }

    private function savePreset(array $preset): bool
    {
        if (!is_dir($this->presetsDir)) {
            mkdir($this->presetsDir, 0755, true);
        }
        return file_put_contents(
            $this->presetFile($preset['name']),
            json_encode($preset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        ) !== false;
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
        return $this->respond($response, [
            'success' => true,
            'data' => $this->loadPresets(),
            'active' => $this->generator->getActivePresetName(),
            'mode' => $this->generator->getActiveMode(),
        ]);
    }

    public function getActive(Request $request, Response $response): Response
    {
        return $this->respond($response, [
            'success' => true,
            'data' => [
                'name' => $this->generator->getActivePresetName(),
                'mode' => $this->generator->getActiveMode(),
            ],
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getBody()->getContents();
        $input = json_decode($body, true);
        if (!is_array($input)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid JSON',
            ], 400);
        }

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || !$this->isValidName($name)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid preset name',
            ], 400);
        }

        $freqFrom = (float)($input['freq_from'] ?? 0);
        $freqTo = (float)($input['freq_to'] ?? 0);
        $step = (float)($input['channel_step'] ?? 12.5);
        if ($freqFrom <= 0 || $freqTo <= 0 || $freqTo <= $freqFrom) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid frequency range: freq_from and freq_to (MHz) are required, freq_to > freq_from',
            ], 400);
        }
        $range = $freqTo - $freqFrom;
        if ($range > ConfigGeneratorService::MAX_SCAN_RANGE_MHZ) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Range too large: ' . round($range, 4) . ' MHz > ' . ConfigGeneratorService::MAX_SCAN_RANGE_MHZ . ' MHz (SDR sample rate limit)',
            ], 400);
        }
        if ($step <= 0) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'channel_step (kHz) must be > 0',
            ], 400);
        }

        $scanFname = trim((string)($input['scan_fname'] ?? ''));
        if ($scanFname === '') {
            $scanFname = $this->defaultScanFname($name);
        }
        if (!preg_match('/^SCAN-[A-Za-z0-9_-]+$/', $scanFname)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'scan_fname must match SCAN-[A-Za-z0-9_-]+',
            ], 400);
        }

        $workList = $input['work_list'] ?? [];
        if (!is_array($workList)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'work_list must be an object {freq: label}',
            ], 400);
        }
        foreach ($workList as $freq => $label) {
            if (!is_string($freq) || !preg_match('/^\d+\.\d{1,4}$/', $freq)) {
                return $this->respond($response, [
                    'success' => false,
                    'error' => 'Invalid work_list frequency: ' . var_export($freq, true),
                ], 400);
            }
            $label = (string)$label;
            if ($label === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
                return $this->respond($response, [
                    'success' => false,
                    'error' => 'Invalid work_list label for ' . $freq . ' (allowed: A-Za-z0-9_-)',
                ], 400);
            }
        }

        if ($this->findPreset($name) !== null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset already exists: ' . $name,
            ], 409);
        }

        $preset = [
            'name' => $name,
            'type' => (string)($input['type'] ?? 'rtlsdr'),
            'serial' => (string)($input['serial'] ?? '00000102'),
            'gain' => (float)($input['gain'] ?? 15.7),
            'freq_from' => $freqFrom,
            'freq_to' => $freqTo,
            'channel_step' => $step,
            'scan_fname' => $scanFname,
            'mp3outdir' => (string)($input['mp3outdir'] ?? '/media/rx/sources'),
            'work_list' => $workList,
        ];

        if (!$this->savePreset($preset)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to save preset',
            ], 500);
        }

        return $this->respond($response, [
            'success' => true,
            'data' => $preset,
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        if ($name === '' || !$this->isValidName($name)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid preset name',
            ], 400);
        }
        $file = $this->presetFile($name);
        if (!file_exists($file)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }
        if (!unlink($file)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to delete preset',
            ], 500);
        }
        return $this->respond($response, ['success' => true]);
    }

    public function applyScan(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $preset = $this->findPreset($name);
        if ($preset === null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }

        $range = (float)$preset['freq_to'] - (float)$preset['freq_from'];
        if ($range > ConfigGeneratorService::MAX_SCAN_RANGE_MHZ) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Range too large: ' . round($range, 4) . ' MHz > ' . ConfigGeneratorService::MAX_SCAN_RANGE_MHZ . ' MHz',
            ], 400);
        }

        $config = $this->generator->generateScan($preset);
        if (!$this->generator->apply($config)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to apply preset (write config or restart scanner)',
            ], 500);
        }
        return $this->respond($response, [
            'success' => true,
            'data' => ['name' => $name, 'mode' => 'wideband_scan'],
        ]);
    }

    public function applyReceive(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $preset = $this->findPreset($name);
        if ($preset === null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }

        $workList = $preset['work_list'] ?? [];
        if (!is_array($workList) || count($workList) === 0) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'work_list is empty: add frequencies to receive mode first',
            ], 400);
        }

        $config = $this->generator->generateReceive($preset);
        if (!$this->generator->apply($config)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to apply preset (write config or restart scanner)',
            ], 500);
        }
        return $this->respond($response, [
            'success' => true,
            'data' => ['name' => $name, 'mode' => 'multichannel'],
        ]);
    }

    public function addWorklist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = (string)($body['freq'] ?? '');
        $label = (string)($body['label'] ?? 'marked');

        if ($name === '' || $freq === '') {
            return $this->respond($response, [
                'success' => false,
                'error' => 'name and freq are required',
            ], 400);
        }

        $freqKey = sprintf('%.4f', (float)$freq);
        if (!preg_match('/^\d+\.\d{1,4}$/', $freqKey)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid frequency: ' . $freq,
            ], 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Invalid label (allowed: A-Za-z0-9_-)',
            ], 400);
        }

        $preset = $this->findPreset($name);
        if ($preset === null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }

        if (!isset($preset['work_list']) || !is_array($preset['work_list'])) {
            $preset['work_list'] = [];
        }
        $preset['work_list'][$freqKey] = $label;
        if (!$this->savePreset($preset)) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Failed to save preset',
            ], 500);
        }

        return $this->respond($response, [
            'success' => true,
            'data' => ['freq' => $freqKey, 'label' => $label],
        ]);
    }

    public function removeWorklist(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $body = json_decode($request->getBody()->getContents(), true);
        $freq = (string)($body['freq'] ?? '');

        if ($name === '' || $freq === '') {
            return $this->respond($response, [
                'success' => false,
                'error' => 'name and freq are required',
            ], 400);
        }

        $preset = $this->findPreset($name);
        if ($preset === null) {
            return $this->respond($response, [
                'success' => false,
                'error' => 'Preset not found: ' . $name,
            ], 404);
        }

        $freqKey = sprintf('%.4f', (float)$freq);
        if (isset($preset['work_list'][$freqKey])) {
            unset($preset['work_list'][$freqKey]);
            if (!$this->savePreset($preset)) {
                return $this->respond($response, [
                    'success' => false,
                    'error' => 'Failed to save preset',
                ], 500);
            }
        }

        return $this->respond($response, ['success' => true]);
    }

    private function defaultScanFname(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $name);
        $slug = trim($slug, '-');
        return 'SCAN-' . ($slug !== '' ? $slug : 'scan');
    }
}
