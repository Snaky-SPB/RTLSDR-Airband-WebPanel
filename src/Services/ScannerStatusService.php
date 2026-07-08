<?php

declare(strict_types=1);

namespace RadioScanner\Services;

class ScannerStatusService
{
    private string $rtlAirbandPidFile = '/var/run/rtl_airband.pid';
    private string $statsFile = '/media/rx/rtl_airband_stats.txt';

    /**
     * Проверить статус rtl_airband
     */
    public function isRunning(): bool
    {
        // Проверка через pidfile
        if (file_exists($this->rtlAirbandPidFile)) {
            $pid = (int)file_get_contents($this->rtlAirbandPidFile);
            return posix_kill($pid, 0);
        }

        // Fallback: проверка процесса
        exec('pgrep -x rtl_airband', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Получить статистику rtl_airband
     */
    public function getStats(): array
    {
        if (!file_exists($this->statsFile)) {
            return [];
        }

        $content = file_get_contents($this->statsFile);
        if ($content === false) {
            return [];
        }

        // Парсинг статистики (формат зависит от rtl_airband)
        $lines = explode("\n", trim($content));
        $stats = [];

        foreach ($lines as $line) {
            // Пример формата: timestamp frequency activity
            $parts = explode(' ', trim($line));
            if (count($parts) >= 3) {
                $stats[] = [
                    'timestamp' => $parts[0],
                    'frequency' => $parts[1],
                    'activity' => $parts[2],
                ];
            }
        }

        return $stats;
    }

    /**
     * Перезапустить rtl_airband
     */
    public function restart(): bool
    {
        exec('sudo systemctl restart rtl_airband 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Остановить rtl_airband
     */
    public function stop(): bool
    {
        exec('sudo systemctl stop rtl_airband 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Запустить rtl_airband
     */
    public function start(): bool
    {
        exec('sudo systemctl start rtl_airband 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
}
