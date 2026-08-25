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
     * Генерация полного конфига из списка устройств
     * (каждое — в своём режиме: wideband_scan или multichannel).
     *
     * @throws \InvalidArgumentException при некорректном состоянии устройств
     */
    public function generate(array $devices): string
    {
        if ($devices === []) {
            throw new \InvalidArgumentException('no devices');
        }

        $out = [
            '# generated: ' . date('Y-m-d H:i:s') . ' devices: ' . count($devices),
            'localtime = true;',
            'multiple_demod_threads = true;',
            'multiple_output_threads = true;',
            'stats_filepath = "/media/rx/rtl_airband_stats.txt";',
            'log_scan_activity = true;',
            'min_transmission_time = 10.0;',
            'max_transmission_time = 3600.0;',
            'max_transmission_idle = 60.0;',
            'devices:',
            '(',
        ];

        $total = count($devices);
        foreach ($devices as $i => $device) {
            $out[] = $this->deviceBlock($device) . "\n}";
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

    private function deviceBlock(array $device): string
    {
        $mode = (string)($device['mode'] ?? 'wideband_scan');
        $out = ["{", '  type = "' . ($device['type'] ?? 'rtlsdr') . '";', '  serial = "' . $device['serial'] . '";'];
        $out[] = '  gain = ' . $this->fmtFreq((float)($device['gain'] ?? 15.7)) . ';';
        if ((float)($device['correction'] ?? 0) !== 0.0) {
            // rtlsdr: correction — int (ppm)
            $out[] = '  correction = ' . (int)round((float)$device['correction']) . ';';
        }

        if ($mode === 'multichannel') {
            return $this->multichannelBlock($device, $out);
        }

        $freqFrom = (float)($device['freq_from'] ?? 0);
        $freqTo = (float)($device['freq_to'] ?? 0);
        $range = $freqTo - $freqFrom;
        if ($range <= 0) {
            throw new \InvalidArgumentException('device ' . $device['name'] . ': invalid scan range');
        }
        if ($range > self::MAX_SCAN_RANGE_MHZ) {
            throw new \InvalidArgumentException('device ' . $device['name'] . ': range must not exceed ' . self::MAX_SCAN_RANGE_MHZ . ' MHz');
        }

        $out[] = '  mode = "wideband_scan";';
        $out[] = '  freq_from = ' . $this->fmtFreq($freqFrom) . ';';
        $out[] = '  freq_to = ' . $this->fmtFreq($freqTo) . ';';
        $out[] = '  channel_step = ' . $this->fmtFreq((float)($device['channel_step'] ?? 12.5)) . ';';
        $out[] = '  max_active_carriers = ' . self::MAX_ACTIVE_CARRIERS . ';';
        $out[] = '  modulation = "nfm";';
        $out[] = '  squelch_snr_threshold = ' . self::SQUELCH_SNR_THRESHOLD . ';';
        $out[] = '    outputs: ({';
        $out[] = '      type = "file";';
        $out[] = '      directory = "' . ($device['mp3outdir'] ?? '/media/rx/sources') . '";';
        $out[] = '      filename_template = "' . ($device['scan_fname'] ?? 'SCAN') . '";';
        $out[] = '      include_freq = true;';
        $out[] = '      split_on_transmission = false;';
        $out[] = '      append = true;';
        $out[] = '      discontinuity_tone = false;';
        $out[] = '    });';

        return implode("\n", $out);
    }

    private function multichannelBlock(array $device, array $head): string
    {
        $workList = is_array($device['work_list'] ?? null) ? $device['work_list'] : [];
        $enabled = array_filter(
            $workList,
            static fn($entry) => is_array($entry) ? (bool)($entry['enabled'] ?? true) : true
        );
        if ($enabled === []) {
            throw new \InvalidArgumentException('device ' . $device['name'] . ': no enabled channels in work_list');
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
            $out[] = '      directory = "' . ($device['mp3outdir'] ?? '/media/rx/sources') . '";';
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
