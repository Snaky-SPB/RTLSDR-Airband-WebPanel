<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

use AirbandWebPanel\Exceptions\WriteException;

/**
 * Приёмники (устройства) — только железо + режим + указатель на полосу.
 * Операционные параметры (gain, диапазон, work_list) живут в полосе (PresetService).
 * Файл: devices/<name>.json.
 */
class DeviceService
{
    private string $devicesDir;

    public const TYPES = ['rtlsdr'];
    public const MODES = ['wideband_scan', 'multichannel'];
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
     * @throws \InvalidArgumentException при некорректных данных
     * @throws \RuntimeException если устройство уже существует
     * @throws WriteException при неудачной записи
     */
    public function create(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException('Invalid device name');
        }
        if ($this->findByName($name) !== null) {
            throw new \RuntimeException('Device already exists');
        }
        $device = $this->normalize($input, $name);
        if (!$this->writeDevice($name, $device)) {
            throw new WriteException('Failed to write device file (disk error?)');
        }
        return $device;
    }

    /**
     * Частичное обновление: неизвестные/отсутствующие поля не меняются.
     * preset: "" или null — снять полосу (устройство неактивно).
     *
     * @throws \InvalidArgumentException при некорректных данных
     * @throws \RuntimeException если устройство не найдено
     * @throws WriteException при неудачной записи
     */
    public function update(string $name, array $input): array
    {
        $existing = $this->find($name);
        if ($existing === null) {
            throw new \RuntimeException('Device not found');
        }
        $merged = array_merge($existing, array_filter($input, static fn($v) => $v !== null));
        $device = $this->normalize($merged, $existing['name']);
        if (!$this->writeDevice($existing['name'], $device)) {
            throw new WriteException('Failed to write device file (disk error?)');
        }
        return $device;
    }

    public function find(string $name): ?array
    {
        $byFile = $this->findByName($name);
        if ($byFile !== null) {
            return $byFile;
        }
        foreach ($this->list() as $device) {
            if (strcasecmp($device['name'], $name) === 0) {
                return $device;
            }
        }
        return null;
    }

    public function list(): array
    {
        if (!is_dir($this->devicesDir)) {
            return [];
        }
        $devices = [];
        foreach (glob($this->devicesDir . '/*.json') ?: [] as $file) {
            $device = $this->canonical(json_decode((string)file_get_contents($file), true));
            if ($device !== null) {
                $devices[] = $device;
            }
        }
        usort($devices, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $devices;
    }

    /**
     * Имена устройств, ссылающихся на полосу (для запрета удаления занятой)
     */
    public function findUsers(string $preset): array
    {
        $users = [];
        foreach ($this->list() as $device) {
            if (strcasecmp((string)($device['preset'] ?? ''), $preset) === 0) {
                $users[] = $device['name'];
            }
        }
        return $users;
    }

    public function delete(string $name): bool
    {
        $path = $this->devicesDir . '/' . $name . '.json';
        if (!self::isValidName($name) || !file_exists($path)) {
            return false;
        }
        return unlink($path);
    }

    private function findByName(string $name): ?array
    {
        if (!self::isValidName($name)) {
            return null;
        }
        $path = $this->devicesDir . '/' . $name . '.json';
        if (file_exists($path)) {
            return $this->canonical(json_decode((string)file_get_contents($path), true));
        }
        return null;
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
     * Чтение файла (list/find): допустимы файлы старого формата —
     * лишние поля (gain, freq_from, work_list...) отбрасываются.
     */
    private function canonical($device): ?array
    {
        if (!is_array($device)) {
            return null;
        }
        if (!self::isValidName((string)($device['name'] ?? ''))) {
            return null;
        }
        $serial = trim((string)($device['serial'] ?? ''));
        if ($serial === '') {
            return null;
        }
        $mode = (string)($device['mode'] ?? 'wideband_scan');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'wideband_scan';
        }
        return [
            'name' => (string)$device['name'],
            'type' => (string)($device['type'] ?? 'rtlsdr'),
            'serial' => $serial,
            'correction' => (float)($device['correction'] ?? 0),
            'mp3outdir' => (string)($device['mp3outdir'] ?? self::DEFAULT_SOURCES_DIR),
            'mode' => $mode,
            'preset' => (string)($device['preset'] ?? ''),
        ];
    }

    /**
     * Валидация + дефолты. Бросает \InvalidArgumentException при ошибке.
     * Существование указанной полосы проверяет хендлер (PresetService).
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

        if (array_key_exists('correction', $input) && !is_numeric($input['correction'])) {
            throw new \InvalidArgumentException('correction must be a number');
        }
        $correction = (float)($input['correction'] ?? 0);

        $mode = (string)($input['mode'] ?? 'wideband_scan');
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be wideband_scan or multichannel');
        }

        $mp3outdir = (string)($input['mp3outdir'] ?? self::DEFAULT_SOURCES_DIR);
        if ($mp3outdir === '') {
            $mp3outdir = self::DEFAULT_SOURCES_DIR;
        }

        $preset = trim((string)($input['preset'] ?? ''));

        return [
            'name' => $name,
            'type' => $type,
            'serial' => $serial,
            'correction' => $correction,
            'mp3outdir' => $mp3outdir,
            'mode' => $mode,
            'preset' => $preset,
        ];
    }
}
