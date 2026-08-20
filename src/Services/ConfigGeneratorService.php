<?php

declare(strict_types=1);

namespace RadioScanner\Services;

class ConfigGeneratorService
{
    private string $configPath = '/usr/local/etc/rtl_airband.conf';
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

    public function generateScan(array $preset): string
    {
        $out = [];
        $out[] = '# preset: ' . $preset['name'];
        $out[] = 'localtime = true;';
        $out[] = 'stats_filepath = "/media/rx/rtl_airband_stats.txt";';
        $out[] = 'log_scan_activity = true;';
        $out[] = 'min_transmission_time = 10.0;';
        $out[] = 'max_transmission_time = 3600.0;';
        $out[] = 'max_transmission_idle = 60.0;';
        $out[] = 'devices:';
        $out[] = '(';
        $out[] = '{';
        $out[] = '  type = "' . ($preset['type'] ?? 'rtlsdr') . '";';
        $out[] = '  serial = "' . ($preset['serial'] ?? '00000102') . '";';
        $out[] = '  gain = ' . $this->fmtFreq((float)($preset['gain'] ?? 15.7)) . ';';
        $out[] = '  mode = "wideband_scan";';
        $out[] = '  freq_from = ' . $this->fmtFreq((float)$preset['freq_from']) . ';';
        $out[] = '  freq_to = ' . $this->fmtFreq((float)$preset['freq_to']) . ';';
        $out[] = '  channel_step = ' . $this->fmtFreq((float)($preset['channel_step'] ?? 12.5)) . ';';
        $out[] = '  max_active_carriers = ' . self::MAX_ACTIVE_CARRIERS . ';';
        $out[] = '  modulation = "nfm";';
        $out[] = '  squelch_snr_threshold = ' . self::SQUELCH_SNR_THRESHOLD . ';';
        $out[] = '  outputs: ({';
        $out[] = '    type = "file";';
        $out[] = '    directory = "' . ($preset['mp3outdir'] ?? '/media/rx/sources') . '";';
        $out[] = '    filename_template = "' . ($preset['scan_fname'] ?? 'SCAN') . '";';
        $out[] = '    include_freq = true;';
        $out[] = '    split_on_transmission = false;';
        $out[] = '    append = true;';
        $out[] = '    discontinuity_tone = false;';
        $out[] = '  });';
        $out[] = '}';
        $out[] = ');';

        return implode("\n", $out) . "\n";
    }

    public function generateReceive(array $preset): string
    {
        $workList = $preset['work_list'] ?? [];
        if (!is_array($workList) || count($workList) === 0) {
            throw new \InvalidArgumentException('work_list is empty');
        }

        $freqs = array_map('floatval', array_keys($workList));
        $min = min($freqs);
        $max = max($freqs);
        $centerfreq = round(($min + $max) / 2, 4);

        $rangeHz = ($max - $min) * 1000000;
        $sampleRate = self::MIN_SAMPLE_RATE;
        if ($rangeHz > 0) {
            $needed = (int)ceil($rangeHz / 0.9 / self::WAVE_RATE) * self::WAVE_RATE;
            $sampleRate = max(self::MIN_SAMPLE_RATE, $needed);
        }

        $out = [];
        $out[] = '# preset: ' . $preset['name'];
        $out[] = 'localtime = true;';
        $out[] = 'multiple_demod_threads = true;';
        $out[] = 'multiple_output_threads = true;';
        $out[] = 'stats_filepath = "/media/rx/rtl_airband_stats.txt";';
        $out[] = 'log_scan_activity = true;';
        $out[] = 'min_transmission_time = 10.0;';
        $out[] = 'max_transmission_time = 3600.0;';
        $out[] = 'max_transmission_idle = 60.0;';
        $out[] = 'devices:';
        $out[] = '(';
        $out[] = '{';
        $out[] = '  type = "' . ($preset['type'] ?? 'rtlsdr') . '";';
        $out[] = '  serial = "' . ($preset['serial'] ?? '00000102') . '";';
        $out[] = '  gain = ' . $this->fmtFreq((float)($preset['gain'] ?? 15.7)) . ';';
        $out[] = '  sample_rate = ' . $sampleRate . ';';
        $out[] = '  mode = "multichannel";';
        $out[] = '  centerfreq = ' . sprintf('%.4f', $centerfreq) . ';';
        $out[] = '  channels: (';

        $first = true;
        ksort($workList);
        foreach ($workList as $freqStr => $label) {
            $freq = (float)$freqStr;
            if (!$first) {
                $out[] = '    ,';
            }
            $out[] = '    {';
            $out[] = '    freq = ' . sprintf('%.4f', $freq) . ';';
            $out[] = '    modulation = "nfm";';
            $out[] = '    outputs: ({';
            $out[] = '      type = "file";';
            $out[] = '      directory = "' . ($preset['mp3outdir'] ?? '/media/rx/sources') . '";';
            $out[] = '      filename_template = "' . $label . '";';
            $out[] = '      include_freq = true;';
            $out[] = '      split_on_transmission = true;';
            $out[] = '      });';
            $out[] = '    }';
            $first = false;
        }

        $out[] = '  );';
        $out[] = '}';
        $out[] = ');';

        return implode("\n", $out) . "\n";
    }

    public function apply(string $content): bool
    {
        $tmpFile = '/tmp/rtl_airband_tmp';
        file_put_contents($tmpFile, $content);
        exec('sudo cp ' . $tmpFile . ' ' . $this->configPath . ' 2>&1', $out, $code);
        unlink($tmpFile);
        if ($code !== 0) {
            return false;
        }
        return $this->scannerStatus->restart();
    }

    public function getActivePresetName(): ?string
    {
        if (!file_exists($this->configPath)) {
            return null;
        }
        $firstLine = file_get_contents($this->configPath, false, null, 0, 200);
        if ($firstLine === false) {
            return null;
        }
        if (preg_match('/^#\s*preset:\s*(.+)$/m', $firstLine, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function getActiveMode(): ?string
    {
        if (!file_exists($this->configPath)) {
            return null;
        }
        $content = file_get_contents($this->configPath);
        if ($content === false) {
            return null;
        }
        if (preg_match('/^\s*mode\s*=\s*"(wideband_scan|multichannel)"/m', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    private function fmtFreq(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        return str_contains($s, '.') ? $s : $s . '.0';
    }
}
