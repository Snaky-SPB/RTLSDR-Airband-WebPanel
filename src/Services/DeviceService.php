<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

use AirbandWebPanel\Exceptions\WriteException;

class DeviceService
{
    private string $devicesDir;

    public const TYPES = ['rtlsdr'];
    public const MAX_SCAN_RANGE_MHZ = 2.16;
    public const DEFAULT_SOURCES_DIR = '/media/rx/sources';

    public function __construct(?string $devicesDir = null)
    {
        $this->devicesDir = $devicesDir ?? __DIR__ . '/../../devices';
    }

    public static function isValidName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (strpbrk($name, '/\\') !== false) {
            return false;
        }
        if (str_starts_with($name, '.')) {
            return false;
        }
        return true;
    }

    /**
     * Префикс SCAN-файлов из имени (пресета или устройства)
     */
    public static function scanFnameFromName(string $name): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-');
        return 'SCAN-' . ($slug !== '' ? $slug : 'scan');
    }

    /**
     * @throws \InvalidArgumentException при некорректных данных
     */
    public function create(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException('Invalid device name');
        }
        if ($this->find($name) !== null) {
            throw new \RuntimeException('Device already exists');
        }
        $device = $this->normalize($input, $name);
        if (!$this->writeDevice($name, $device)) {
            throw new WriteException('Failed to write device file (disk error?)');
        }
        return $device;
    }

    /**
     * Частичное обновление: неизвестные/отсутствующие поля не меняются
     *
     * @throws \InvalidArgumentException при некорректных данных
     */
    public function update(string $name, array $input): array
    {
        $existing = $this->find($name);
        if ($existing === null) {
            throw new \RuntimeException('Device not found');
        }
        $merged = array_merge($existing, array_filter($input, static fn($v) => $v !== null));
        $device = $this->normalize($merged, $name);
        if (!$this->writeDevice($name, $device)) {
            throw new WriteException('Failed to write device file (disk error?)');
        }
        return $device;
    }

    public function find(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $path = $this->devicesDir . '/' . $name . '.json';
        if (!file_exists($path)) {
            return null;
        }
        $device = json_decode((string)file_get_contents($path), true);
        return is_array($device) ? $device : null;
    }

    public function list(): array
    {
        if (!is_dir($this->devicesDir)) {
            return [];
        }
        $devices = [];
        foreach (glob($this->devicesDir . '/*.json') ?: [] as $file) {
            $device = json_decode((string)file_get_contents($file), true);
            if (is_array($device) && self::isValidName((string)($device['name'] ?? ''))) {
                $devices[] = $device;
            }
        }
        usort($devices, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $devices;
    }

    public function delete(string $name): bool
    {
        $path = $this->devicesDir . '/' . $name . '.json';
        if (!self::isValidName($name) || !file_exists($path)) {
            return false;
        }
        return unlink($path);
    }

    private function writeDevice(string $name, array $device): bool
    {
        if (!is_dir($this->devicesDir) && !@mkdir($this->devicesDir, 0755, true) && !is_dir($this->devicesDir)) {
            return false;
        }
        return @file_put_contents(
            $this->devicesDir . '/' . $name . '.json',
            json_encode($device, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        ) !== false;
    }

    /**
     * Дефолты + валидация. Бросает \InvalidArgumentException при ошибке.
     */
    private function normalize(array $input, string $name): array
    {
        $type = (string)($input['type'] ?? 'rtlsdr');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported device type: $type");
        }

        $serial = trim((string)($input['serial'] ?? ''));
        if ($serial === '') {
            throw new \InvalidArgumentException('serial is required');
        }

        $gain = (float)($input['gain'] ?? 15.7);
        if (!is_numeric($input['gain'] ?? 15.7)) {
            throw new \InvalidArgumentException('gain must be a number');
        }

        $correction = (float)($input['correction'] ?? 0);
        if (!is_numeric($input['correction'] ?? 0)) {
            throw new \InvalidArgumentException('correction must be a number');
        }

        $mode = (string)($input['mode'] ?? 'wideband_scan');
        if (!in_array($mode, ['wideband_scan', 'multichannel'], true)) {
            throw new \InvalidArgumentException('mode must be wideband_scan or multichannel');
        }

        $mp3outdir = (string)($input['mp3outdir'] ?? self::DEFAULT_SOURCES_DIR);
        if ($mp3outdir === '') {
            $mp3outdir = self::DEFAULT_SOURCES_DIR;
        }

        $device = [
            'name' => $name,
            'type' => $type,
            'serial' => $serial,
            'gain' => $gain,
            'correction' => $correction,
            'mp3outdir' => $mp3outdir,
            'mode' => $mode,
            'work_list' => $this->normalizeWorkList($input['work_list'] ?? []),
        ];

        if ($mode === 'wideband_scan') {
            $freqFrom = (float)($input['freq_from'] ?? 0);
            $freqTo = (float)($input['freq_to'] ?? 0);
            $channelStep = (float)($input['channel_step'] ?? 12.5);

            if ($freqFrom <= 0 || $freqTo <= 0) {
                throw new \InvalidArgumentException('freq_from and freq_to are required');
            }
            if ($freqTo <= $freqFrom) {
                throw new \InvalidArgumentException('freq_to must be greater than freq_from');
            }
            if (($freqTo - $freqFrom) > self::MAX_SCAN_RANGE_MHZ) {
                throw new \InvalidArgumentException('range must not exceed ' . self::MAX_SCAN_RANGE_MHZ . ' MHz');
            }
            if ($channelStep <= 0) {
                throw new \InvalidArgumentException('channel_step must be positive');
            }

            $scanFname = trim((string)($input['scan_fname'] ?? ''));
            if ($scanFname === '') {
                $scanFname = self::scanFnameFromName($name);
            }
            if (!preg_match('/^SCAN-[A-Za-z0-9_-]+$/', $scanFname)) {
                throw new \InvalidArgumentException('Invalid scan_fname');
            }

            $device['freq_from'] = $freqFrom;
            $device['freq_to'] = $freqTo;
            $device['channel_step'] = $channelStep;
            $device['scan_fname'] = $scanFname;
        }

        return $device;
    }

    /**
     * work_list: { "172.8125": "T" } или { "172.8125": {"label": "T", "enabled": true} }
     * → { "172.8125": {"label": "T", "enabled": true} }
     */
    private function normalizeWorkList($workList): array
    {
        if (!is_array($workList)) {
            throw new \InvalidArgumentException('work_list must be an object');
        }
        $result = [];
        foreach ($workList as $freq => $value) {
            $freqKey = is_string($freq) ? sprintf('%.4f', (float)$freq) : null;
            if ($freqKey === null || !preg_match('/^\d+\.\d{1,4}$/', (string)$freq)) {
                throw new \InvalidArgumentException('Invalid work_list frequency: ' . var_export($freq, true));
            }
            if (is_string($value)) {
                $label = $value;
                $enabled = true;
            } elseif (is_array($value)) {
                $label = (string)($value['label'] ?? 'marked');
                $enabled = (bool)($value['enabled'] ?? true);
            } else {
                throw new \InvalidArgumentException('Invalid work_list entry for ' . $freqKey);
            }
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
                throw new \InvalidArgumentException('Invalid work_list label: ' . $label);
            }
            $result[$freqKey] = ['label' => $label, 'enabled' => $enabled];
        }
        return $result;
    }
}
