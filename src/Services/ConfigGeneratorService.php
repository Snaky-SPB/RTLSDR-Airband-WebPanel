<?php

declare(strict_types=1);

namespace RadioScanner\Services;

class ConfigGeneratorService
{
    private string $configPath = '/usr/local/etc/rtl_airband.conf';
    private ScannerStatusService $scannerStatus;

    public function __construct(ScannerStatusService $scannerStatus)
    {
        $this->scannerStatus = $scannerStatus;
    }

    public function generate(array $preset): string
    {
        $freqs = [];
        $labels = [];
        $step = (float)($preset['step'] ?? 0.0125);
        $start = (float)($preset['startfreq']);
        $stop = (float)($preset['stopfreq']);
        $scanFname = $preset['scan_fname'] ?? 'SCAN';
        $whiteList = $preset['white_list'] ?? [];
        $blackList = $preset['black_list'] ?? [];

        for ($f = $start; $f <= $stop + 0.0001; $f += $step) {
            $freq = round($f, 4);
            $freqStr = sprintf('%.4f', $freq);
            $freqs[] = $freq;
            if (isset($whiteList[$freqStr])) {
                $labels[] = $whiteList[$freqStr];
            } else {
                $labels[] = $scanFname;
            }
        }

        $centerfreq = round(array_sum($freqs) / count($freqs), 4);

        $out = [];
        $out[] = '# preset: ' . $preset['name'];
        $out[] = 'localtime = true;';
        $out[] = 'multiple_demod_threads = true;';
        $out[] = 'multiple_output_threads = true;';
        $out[] = 'stats_filepath = "/media/rx/rtl_airband_stats.txt";';
        $out[] = 'log_scan_activity = true;';
        $out[] = 'devices:';
        $out[] = '(';
        $out[] = '{';
        $out[] = '  type = "' . ($preset['type'] ?? 'rtlsdr') . '";';
        $out[] = '  serial = "' . ($preset['serial'] ?? '00000102') . '";';
        $out[] = '  gain = ' . ($preset['gain'] ?? 15.7) . ';';
        $out[] = '  sample_rate = ' . ($preset['sample_rate'] ?? 2.54) . ';';
        $out[] = '  mode = "multichannel";';
        $out[] = '  centerfreq = ' . sprintf('%.4f', $centerfreq) . ';';
        $out[] = '  channels: (';

        $first = true;
        foreach ($freqs as $i => $freq) {
            $freqStr = sprintf('%.4f', $freq);
            $label = $labels[$i];

            if (in_array($freqStr, $blackList) && !isset($whiteList[$freqStr])) {
                continue;
            }

            if (!$first) {
                $out[] = '    ,';
            }
            $out[] = '    {';
            $out[] = '    freq = ' . $freqStr . ';';
            $out[] = '    modulation = "nfm";';
            $out[] = '    outputs: ({';
            $out[] = '      type = "file";';
            $out[] = '      directory = "' . ($preset['mp3outdir'] ?? '/media/rx/sources') . '";';
            $out[] = '      filename_template = "' . $label . '";';
            $out[] = '      include_freq = true;';
            if ($label === $scanFname) {
                $out[] = '      split_on_transmission = false;';
            } else {
                $out[] = '      split_on_transmission = true;';
            }
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
}
