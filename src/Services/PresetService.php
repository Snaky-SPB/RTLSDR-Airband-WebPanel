<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

use AirbandWebPanel\Exceptions\WriteException;

/**
 * Полосы (band-пресеты) — операционный профиль:
 * scan-часть (диапазон, step, gain, SCAN-префикс, freq_blacklist)
 * + receive-часть (work_list).
 * Одна полоса используется устройствами в любом режиме; режим выбирает часть.
 * Файл: presets/<name>.json. Суперсет старого scan-формата
 * {name, freq_from, freq_to, channel_step} — старые файлы читаются
 * с gain=15.7, work_list=[], freq_blacklist=[].
 */
class PresetService
{
    private string $presetsDir;

    public const DEFAULT_GAIN = 15.7;
    public const DEFAULT_CHANNEL_STEP = 12.5;

    public function __construct(?string $presetsDir = null)
    {
        $this->presetsDir = $presetsDir ?? __DIR__ . '/../../presets';
    }

    /**
     * Префикс SCAN-файлов из имени полосы
     */
    public static function scanFnameFromName(string $name): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-');
        return 'SCAN-' . ($slug !== '' ? $slug : 'scan');
    }

    /**
     * @throws \InvalidArgumentException при некорректных данных
     * @throws \RuntimeException если полоса уже существует
     * @throws WriteException при неудачной записи
     */
    public function create(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if (!DeviceService::isValidName($name)) {
            throw new \InvalidArgumentException('Invalid preset name');
        }
        if ($this->findByName($name) !== null) {
            throw new \RuntimeException('Preset already exists');
        }
        $band = $this->normalize($input, $name);
        if (!$this->writePreset($name, $band)) {
            throw new WriteException('Failed to write preset file (disk error?)');
        }
        return $band;
    }

    /**
     * Частичное обновление: неизвестные/отсутствующие поля не меняются
     *
     * @throws \InvalidArgumentException при некорректных данных
     * @throws \RuntimeException если полоса не найдена
     * @throws WriteException при неудачной записи
     */
    public function update(string $name, array $input): array
    {
        $existing = $this->find($name);
        if ($existing === null) {
            throw new \RuntimeException('Preset not found');
        }
        $merged = array_merge($existing, array_filter($input, static fn($v) => $v !== null));
        $band = $this->normalize($merged, $existing['name']);
        if (!$this->writePreset($existing['name'], $band)) {
            throw new WriteException('Failed to write preset file (disk error?)');
        }
        return $band;
    }

    public function list(): array
    {
        if (!is_dir($this->presetsDir)) {
            return [];
        }
        $bands = [];
        foreach (glob($this->presetsDir . '/*.json') ?: [] as $file) {
            $band = $this->canonical(json_decode((string)file_get_contents($file), true));
            if ($band !== null) {
                $bands[] = $band;
            }
        }
        usort($bands, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $bands;
    }

    /**
     * Поиск по имени файла <name>.json, затем по внутреннему name
     */
    public function find(string $name): ?array
    {
        $byFile = $this->findByName($name);
        if ($byFile !== null) {
            return $byFile;
        }
        foreach ($this->list() as $band) {
            if (strcasecmp($band['name'], $name) === 0) {
                return $band;
            }
        }
        return null;
    }

    public function delete(string $name): bool
    {
        $path = $this->presetsDir . '/' . $name . '.json';
        if (!DeviceService::isValidName($name) || !file_exists($path)) {
            return false;
        }
        return unlink($path);
    }

    private function findByName(string $name): ?array
    {
        if (!DeviceService::isValidName($name)) {
            return null;
        }
        $path = $this->presetsDir . '/' . $name . '.json';
        if (file_exists($path)) {
            return $this->canonical(json_decode((string)file_get_contents($path), true));
        }
        return null;
    }

    private function writePreset(string $name, array $band): bool
    {
        if (!is_dir($this->presetsDir) && !@mkdir($this->presetsDir, 0755, true) && !is_dir($this->presetsDir)) {
            return false;
        }
        return @file_put_contents(
            $this->presetsDir . '/' . $name . '.json',
            json_encode($band, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        ) !== false;
    }

    /**
     * Чтение файла (list/find): допустим старый формат, недостающие поля — дефолты.
     * Некорректные файлы (нет имени/диапазона) пропускаются.
     */
    private function canonical($preset): ?array
    {
        if (!is_array($preset)) {
            return null;
        }
        if (!DeviceService::isValidName((string)($preset['name'] ?? ''))) {
            return null;
        }
        $from = $preset['freq_from'] ?? null;
        $to = $preset['freq_to'] ?? null;
        if (!is_numeric($from) || !is_numeric($to)) {
            return null;
        }
        $from = (float)$from;
        $to = (float)$to;
        if ($from <= 0 || $to <= $from) {
            return null;
        }
        $step = $preset['channel_step'] ?? self::DEFAULT_CHANNEL_STEP;
        $scanFname = (string)($preset['scan_fname'] ?? '');
        if ($scanFname === '') {
            $scanFname = self::scanFnameFromName((string)$preset['name']);
        }
        return [
            'name' => (string)$preset['name'],
            'gain' => (float)($preset['gain'] ?? self::DEFAULT_GAIN),
            'freq_from' => $from,
            'freq_to' => $to,
            'channel_step' => is_numeric($step) ? (float)$step : self::DEFAULT_CHANNEL_STEP,
            'scan_fname' => $scanFname,
            'work_list' => is_array($preset['work_list'] ?? null) ? $preset['work_list'] : [],
            'freq_blacklist' => $this->canonicalFreqBlacklist($preset['freq_blacklist'] ?? []),
        ];
    }

    /**
     * Валидация + дефолты. Бросает \InvalidArgumentException при ошибке.
     */
    private function normalize(array $input, string $name): array
    {
        if (array_key_exists('gain', $input) && !is_numeric($input['gain'])) {
            throw new \InvalidArgumentException('gain must be a number');
        }
        $gain = (float)($input['gain'] ?? self::DEFAULT_GAIN);

        $freqFrom = $input['freq_from'] ?? null;
        $freqTo = $input['freq_to'] ?? null;
        if (!is_numeric($freqFrom) || !is_numeric($freqTo)) {
            throw new \InvalidArgumentException('freq_from and freq_to are required');
        }
        $freqFrom = (float)$freqFrom;
        $freqTo = (float)$freqTo;
        if ($freqFrom <= 0) {
            throw new \InvalidArgumentException('freq_from must be positive');
        }
        if ($freqTo <= $freqFrom) {
            throw new \InvalidArgumentException('freq_to must be greater than freq_from');
        }

        if (array_key_exists('channel_step', $input) && !is_numeric($input['channel_step'])) {
            throw new \InvalidArgumentException('channel_step must be a number');
        }
        $channelStep = (float)($input['channel_step'] ?? self::DEFAULT_CHANNEL_STEP);
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

        return [
            'name' => $name,
            'gain' => $gain,
            'freq_from' => $freqFrom,
            'freq_to' => $freqTo,
            'channel_step' => $channelStep,
            'scan_fname' => $scanFname,
            'work_list' => $this->normalizeWorkList($input['work_list'] ?? []),
            'freq_blacklist' => $this->normalizeFreqBlacklist($input['freq_blacklist'] ?? []),
        ];
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

    /**
     * freq_blacklist: список частот (МГц), исключаемых из детекции wideband_scan.
     * → дедупликация + сортировка, ключ "172.5000" (%.4f)
     */
    private function normalizeFreqBlacklist($blacklist): array
    {
        if (!is_array($blacklist)) {
            throw new \InvalidArgumentException('freq_blacklist must be a list');
        }
        $map = [];
        foreach ($blacklist as $freq) {
            if (!is_scalar($freq) || !is_numeric($freq)) {
                throw new \InvalidArgumentException('Invalid freq_blacklist frequency: ' . var_export($freq, true));
            }
            $map[sprintf('%.4f', (float)$freq)] = true;
        }
        $result = array_keys($map);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    private function canonicalFreqBlacklist($blacklist): array
    {
        if (!is_array($blacklist)) {
            return [];
        }
        $map = [];
        foreach ($blacklist as $freq) {
            if (is_scalar($freq) && is_numeric($freq)) {
                $map[sprintf('%.4f', (float)$freq)] = true;
            }
        }
        $result = array_keys($map);
        sort($result, SORT_NUMERIC);
        return $result;
    }
}
