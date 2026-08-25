<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

/**
 * Пресеты сканирования — только чтение.
 * Файлы размещаются вручную в каталоге presets/: {name, freq_from, freq_to, channel_step}.
 * Некорректные файлы пропускаются; лишние поля (в т.ч. пресеты старого формата
 * с type/serial/work_list) игнорируются — такие файлы работают как scan-пресеты.
 */
class ScanPresetService
{
    private string $presetsDir;

    public function __construct(?string $presetsDir = null)
    {
        $this->presetsDir = $presetsDir ?? __DIR__ . '/../../presets';
    }

    public function list(): array
    {
        if (!is_dir($this->presetsDir)) {
            return [];
        }
        $presets = [];
        foreach (glob($this->presetsDir . '/*.json') ?: [] as $file) {
            $preset = json_decode((string)file_get_contents($file), true);
            if (is_array($preset) && $this->isValid($preset)) {
                $presets[] = $this->canonical($preset);
            }
        }
        usort($presets, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $presets;
    }

    public function find(string $name): ?array
    {
        if (!DeviceService::isValidName($name)) {
            return null;
        }
        // Сначала по имени файла <name>.json, затем по внутреннему name
        $path = $this->presetsDir . '/' . $name . '.json';
        if (file_exists($path)) {
            $preset = json_decode((string)file_get_contents($path), true);
            if (is_array($preset) && $this->isValid($preset)) {
                return $this->canonical($preset);
            }
        }
        foreach ($this->list() as $preset) {
            if (strcasecmp($preset['name'], $name) === 0) {
                return $preset;
            }
        }
        return null;
    }

    private function isValid(array $preset): bool
    {
        if (!DeviceService::isValidName((string)($preset['name'] ?? ''))) {
            return false;
        }
        $from = $preset['freq_from'] ?? null;
        $to = $preset['freq_to'] ?? null;
        $step = $preset['channel_step'] ?? null;
        if (!is_numeric($from) || !is_numeric($to) || !is_numeric($step)) {
            return false;
        }
        $from = (float)$from;
        $to = (float)$to;
        $step = (float)$step;
        if ($from <= 0 || $to <= $from) {
            return false;
        }
        if (($to - $from) > DeviceService::MAX_SCAN_RANGE_MHZ) {
            return false;
        }
        return $step > 0;
    }

    private function canonical(array $preset): array
    {
        return [
            'name' => (string)$preset['name'],
            'freq_from' => (float)$preset['freq_from'],
            'freq_to' => (float)$preset['freq_to'],
            'channel_step' => (float)$preset['channel_step'],
        ];
    }
}
