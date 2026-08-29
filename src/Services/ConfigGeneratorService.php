<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

class ConfigGeneratorService
{
    private string $configPath = '/usr/local/etc/rtl_airband.conf';
    private string $tmpFile = '/tmp/rtl_airband_tmp';
    private ScannerStatusService $scannerStatus;

    public const MAX_ACTIVE_CARRIERS = 8;
    public const SQUELCH_SNR_THRESHOLD = 9.54;
    public const MIN_SAMPLE_RATE = 2400000;
    public const WAVE_RATE = 16000;
    public const MAX_SCAN_RANGE_MHZ = 2.16;

    public function __construct(ScannerStatusService $scannerStatus)
    {
        $this->scannerStatus = $scannerStatus;
    }

    /**
     * Генерация полного конфига из пар (устройство + его полоса).
     * Железо (serial, correction, mp3outdir, mode) — с устройства;
     * gain, диапазон/work_list — с полосы.
     *
     * @param array $pairs список ['device' => array, 'band' => array]
     *
     * @throws \InvalidArgumentException при некорректном состоянии
     */
    public function generate(array $pairs): string
    {
        if ($pairs === []) {
            throw new \InvalidArgumentException('no devices');
        }

        $out = [
            '# generated: ' . date('Y-m-d H:i:s') . ' devices: ' . count($pairs),
            'localtime = true;',
            'multiple_demod_threads = true;',
            'multiple_output_threads = true;',
            'stats_filepath = "/media/rx/rtl_airband_stats.txt";',
            'log_scan_activity = true;',
            'split_min_file_time = 2.0;',
            'split_max_file_time = 3600.0;',
            'split_max_idle_time = 60.0;',
            'devices:',
            '(',
        ];

        $total = count($pairs);
        foreach ($pairs as $i => $pair) {
            $out[] = $this->deviceBlock($pair['device'], $pair['band']) . "\n}";
            if ($i < $total - 1) {
                $out[] = ',';
            }
        }
        $out[] = ');';

        return implode("\n", $out) . "\n";
    }

    /**
     * Запись конфига (без рестарта). Рестарт — из блока управления сервисом.
     */
    public function write(string $content): bool
    {
        if (@file_put_contents($this->tmpFile, $content) === false) {
            return false;
        }
        exec('sudo cp ' . escapeshellarg($this->tmpFile) . ' ' . escapeshellarg($this->configPath) . ' 2>&1', $out, $code);
        @unlink($this->tmpFile);
        return $code === 0;
    }

    /**
     * Текущие режимы устройств из живого конфига: { serial => mode }
     */
    public function getDeviceModes(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }
        $content = (string)file_get_contents($this->configPath);
        $modes = [];
        foreach ($this->parseDeviceBlocks($content) as $block) {
            if (!preg_match('/^\s*serial\s*=\s*"([^"]+)"/m', $block, $m)) {
                continue;
            }
            $serial = $m[1];
            if (preg_match('/^\s*mode\s*=\s*"(wideband_scan|multichannel|scan)"/m', $block, $m)) {
                $modes[$serial] = $m[1];
            } else {
                $modes[$serial] = 'multichannel';
            }
        }
        return $modes;
    }

    private function deviceBlock(array $device, array $band): string
    {
        $mode = (string)($device['mode'] ?? 'wideband_scan');
        $name = (string)($device['name'] ?? '?');
        $gain = (float)($band['gain'] ?? PresetService::DEFAULT_GAIN);
        $out = [
            "{",
            '  type = "' . ($device['type'] ?? 'rtlsdr') . '";',
            '  serial = "' . $device['serial'] . '";',
            '  gain = ' . $this->fmtFreq($gain) . ';',
        ];
        if ((float)($device['correction'] ?? 0) !== 0.0) {
            // rtlsdr: correction — int (ppm)
            $out[] = '  correction = ' . (int)round((float)$device['correction']) . ';';
        }

        if ($mode === 'multichannel') {
            return $this->multichannelBlock($device, $band, $out);
        }

        $freqFrom = (float)($band['freq_from'] ?? 0);
        $freqTo = (float)($band['freq_to'] ?? 0);
        $range = $freqTo - $freqFrom;
        if ($range <= 0) {
            throw new \InvalidArgumentException("device $name: invalid scan range in band " . ($band['name'] ?? '?'));
        }
        if ($range > self::MAX_SCAN_RANGE_MHZ) {
            throw new \InvalidArgumentException("device $name: band range must not exceed " . self::MAX_SCAN_RANGE_MHZ . ' MHz');
        }

        $out[] = '  mode = "wideband_scan";';
        $out[] = '  freq_from = ' . $this->fmtFreq($freqFrom) . ';';
        $out[] = '  freq_to = ' . $this->fmtFreq($freqTo) . ';';
        $out[] = '  channel_step = ' . $this->fmtFreq((float)($band['channel_step'] ?? PresetService::DEFAULT_CHANNEL_STEP)) . ';';
        $out[] = '  max_active_carriers = ' . self::MAX_ACTIVE_CARRIERS . ';';
        $out[] = '  modulation = "nfm";';
        $out[] = '  squelch_snr_threshold = ' . self::SQUELCH_SNR_THRESHOLD . ';';
        $blacklist = $band['freq_blacklist'] ?? [];
        if ($blacklist !== []) {
            $freqs = implode(', ', array_map(fn($f) => $this->fmtFreq((float)$f), $blacklist));
            $out[] = '  freq_blacklist = (' . $freqs . ');';
        }
        $out[] = '    outputs: ({';
        $out[] = '      type = "file";';
        $out[] = '      directory = "' . ($device['mp3outdir'] ?? DeviceService::DEFAULT_SOURCES_DIR) . '";';
        $out[] = '      filename_template = "' . ($band['scan_fname'] ?? 'SCAN') . '";';
        $out[] = '      include_freq = true;';
        $out[] = '      split_on_transmission = false;';
        $out[] = '      append = true;';
        $out[] = '      discontinuity_tone = false;';
        $out[] = '    });';

        return implode("\n", $out);
    }

    private function multichannelBlock(array $device, array $band, array $head): string
    {
        $name = (string)($device['name'] ?? '?');
        $workList = is_array($band['work_list'] ?? null) ? $band['work_list'] : [];
        $enabled = array_filter(
            $workList,
            static fn($entry) => is_array($entry) ? (bool)($entry['enabled'] ?? true) : true
        );
        if ($enabled === []) {
            throw new \InvalidArgumentException("device $name: no enabled channels in band " . ($band['name'] ?? '?'));
        }

        $freqs = array_map('floatval', array_keys($enabled));
        $min = min($freqs);
        $max = max($freqs);
        $centerfreq = round(($min + $max) / 2, 4);
        $rangeHz = ($max - $min) * 1000000;
        $sampleRate = max(self::MIN_SAMPLE_RATE, (int)ceil($rangeHz / 0.9 / self::WAVE_RATE) * self::WAVE_RATE);

        $out = $head;
        $out[] = '  mode = "multichannel";';
        $out[] = '  sample_rate = ' . $sampleRate . ';';
        $out[] = '  centerfreq = ' . sprintf('%.4f', $centerfreq) . ';';
        $out[] = '  channels: (';

        ksort($workList);
        $idx = 0;
        foreach ($workList as $freqStr => $entry) {
            $label = is_array($entry) ? (string)($entry['label'] ?? 'marked') : (string)$entry;
            $isEnabled = is_array($entry) ? (bool)($entry['enabled'] ?? true) : true;
            if ($idx > 0) {
                $out[] = '    ,';
            }
            $idx++;
            $out[] = '    {';
            if (!$isEnabled) {
                $out[] = '    disable = true;';
            }
            $out[] = '    freq = ' . sprintf('%.4f', (float)$freqStr) . ';';
            $out[] = '    modulation = "nfm";';
            $out[] = '    outputs: ({';
            $out[] = '      type = "file";';
            $out[] = '      directory = "' . ($device['mp3outdir'] ?? DeviceService::DEFAULT_SOURCES_DIR) . '";';
            $out[] = '      filename_template = "' . $label . '";';
            $out[] = '      include_freq = true;';
            $out[] = '      split_on_transmission = true;';
            $out[] = '    });';
            $out[] = '    }';
        }
        $out[] = '  );';

        return implode("\n", $out);
    }

    /**
     * Топ-уровневые {...} блоки в секции devices: ( ... );
     */
    private function parseDeviceBlocks(string $content): array
    {
        $pos = strpos($content, 'devices:');
        if ($pos === false) {
            return [];
        }
        $blocks = [];
        $len = strlen($content);
        $depth = 0;
        $blockStart = -1;
        $inList = false;
        for ($i = $pos; $i < $len; $i++) {
            $c = $content[$i];
            if ($c === '(') {
                if ($depth === 0) {
                    $inList = true;
                }
            } elseif ($c === ')') {
                if ($inList && $depth === 0) {
                    break;
                }
            } elseif ($c === '{') {
                if ($depth === 0 && $inList) {
                    $blockStart = $i;
                }
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0 && $blockStart !== -1) {
                    $blocks[] = substr($content, $blockStart, $i - $blockStart + 1);
                    $blockStart = -1;
                }
            }
        }
        return $blocks;
    }

    private function fmtFreq(float $v): string
    {
        $s = sprintf('%.4f', $v);
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        if (strpos($s, '.') === false) {
            $s .= '.0';
        }
        return $s;
    }
}
