<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

class SystemInfoService
{
    /**
     * Получить информацию о диске
     */
    public function getDiskUsage(string $path = '/media/rx'): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Получить информацию о системе
     */
    public function getSystemInfo(): array
    {
        return [
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'uptime' => $this->getUptime(),
            'load_average' => sys_getloadavg(),
        ];
    }

    /**
     * Получить uptime системы
     */
    private function getUptime(): string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = (int)file_get_contents('/proc/uptime');
            $days = intdiv($uptime, 86400);
            $hours = intdiv($uptime % 86400, 3600);
            $minutes = intdiv($uptime % 3600, 60);
            
            if ($days > 0) {
                return "{$days}д {$hours}ч";
            } elseif ($hours > 0) {
                return "{$hours}ч {$minutes}м";
            } else {
                return "{$minutes}м";
            }
        }

        return 'N/A';
    }

    /**
     * Получить использование памяти
     */
    public function getMemoryUsage(): array
    {
        if (file_exists('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $content, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $content, $available);
            preg_match('/MemFree:\s+(\d+)/', $content, $free);

            if ($total && $available) {
                $totalKb = (int)$total[1];
                $availableKb = (int)$available[1];
                $usedKb = $totalKb - $availableKb;

                return [
                    'total' => $this->formatBytes($totalKb * 1024),
                    'used' => $this->formatBytes($usedKb * 1024),
                    'free' => $this->formatBytes($availableKb * 1024),
                    'percent' => round(($usedKb / $totalKb) * 100, 1),
                ];
            }
        }

        return ['total' => 'N/A', 'used' => 'N/A', 'free' => 'N/A', 'percent' => 0];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 1) . ' ' . $units[$pow];
    }
}
