<?php

declare(strict_types=1);

namespace AirbandWebPanel\Services;

class FileScannerService
{
    private string $sourcesDir;

    public function __construct(string $sourcesDir = '/media/rx/sources')
    {
        $this->sourcesDir = $sourcesDir;
    }

    /**
     * Получить все файлы сканера, сгруппированные по частотам
     */
    public function getFrequencies(): array
    {
        $files = $this->getScanFiles();
        $grouped = [];

        foreach ($files as $file) {
            $info = $this->parseFilename($file);
            if ($info === null) {
                continue;
            }

            $freq = $info['frequency'];
            $receiver = $info['receiver'];
            $key = $freq . '_' . $receiver;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'frequency' => $freq,
                    'frequencyMhz' => $this->hzToMhz($freq),
                    'receiver' => $receiver,
                    'files' => []
                ];
            }

            $grouped[$key]['files'][] = [
                'filename' => $file,
                'filepath' => $this->sourcesDir . '/' . $file,
                'datetime' => $info['datetime'],
                'timestamp' => $this->fileToTimestamp($info),
            ];
        }

        // Сортировка файлов внутри частоты по времени
        foreach ($grouped as &$group) {
            usort($group['files'], function($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });
        }

        // Сортировка частот по названию
        uasort($grouped, function($a, $b) {
            return $a['frequency'] <=> $b['frequency'];
        });

        return array_values($grouped);
    }

    /**
     * Получить файлы конкретной частоты
     */
    public function getFrequencyFiles(string $frequency, string $receiver): array
    {
        $files = $this->getScanFiles();
        $result = [];

        foreach ($files as $file) {
            $info = $this->parseFilename($file);
            if ($info === null) {
                continue;
            }

            if ($info['frequency'] === $frequency && $info['receiver'] === $receiver) {
                $result[] = [
                    'filename' => $file,
                    'filepath' => $this->sourcesDir . '/' . $file,
                    'datetime' => $info['datetime'],
                    'timestamp' => $this->fileToTimestamp($info),
                ];
            }
        }

        // Сортировка по времени
        usort($result, function($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $result;
    }

    /**
     * Удалить все файлы частоты
     */
    public function deleteFrequencyFiles(string $frequency, string $receiver): int
    {
        $files = $this->getFrequencyFiles($frequency, $receiver);
        $deleted = 0;

        foreach ($files as $file) {
            if (file_exists($file['filepath']) && unlink($file['filepath'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Получить метаданные файла
     */
    public function getFileMetadata(string $filename): ?array
    {
        $filepath = $this->sourcesDir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            return null;
        }

        $info = $this->parseFilename($filename);
        if ($info === null) {
            return null;
        }

        // Получаем длительность через getID3 или exec
        $duration = $this->getAudioDuration($filepath);

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'duration' => $duration,
            'frequency' => $info['frequency'],
            'frequencyMhz' => $this->hzToMhz($info['frequency']),
            'receiver' => $info['receiver'],
            'datetime' => $info['datetime'],
        ];
    }

    /**
     * Получить список SCAN файлов
     */
    private function getScanFiles(): array
    {
        if (!is_dir($this->sourcesDir)) {
            return [];
        }

        $files = scandir($this->sourcesDir);
        $scanFiles = [];

        foreach ($files as $file) {
            // Фильтр только SCAN файлов
            if (preg_match('/^SCAN-.*\.mp3$/', $file)) {
                $scanFiles[] = $file;
            }
        }

        return $scanFiles;
    }

    /**
     * Распарсить имя файла
     * Формат: SCAN-Receiver_YYYYMMDD_HH_Frequency.mp3
     */
    private function parseFilename(string $filename): ?array
    {
        $pattern = '/^(SCAN-[a-zA-Z0-9_-]+)_(\d{8})_(\d{1,2})_(\d+)\.mp3$/';
        
        if (!preg_match($pattern, $filename, $matches)) {
            return null;
        }

        return [
            'date' => $matches[2],
            'hour' => $matches[3],
            'datetime' => $matches[2] . '_' . $matches[3],
            'frequency' => $matches[4],
            'receiver' => $matches[1],
        ];
    }

    /**
     * Конвертировать Hz в MHz
     */
    private function hzToMhz(string $hz): string
    {
        $mhz = (int)$hz / 1000000;
        return rtrim(rtrim(sprintf('%.4f', $mhz), '0'), '.') . ' MHz';
    }

    /**
     * Конвертировать информацию о файле в timestamp
     */
    private function fileToTimestamp(array $info): int
    {
        $date = $info['date'];
        $hour = str_pad($info['hour'], 2, '0', STR_PAD_LEFT);
        
        return \DateTime::createFromFormat('YmdHi', $date . $hour . '00')->getTimestamp();
    }

    /**
     * Получить длительность аудиофайла
     */
    private function getAudioDuration(string $filepath): ?float
    {
        // Попытка через getID3 (если установлен)
        if (class_exists('getID3')) {
            try {
                $getID3 = new \getID3();
                $info = $getID3->analyze($filepath);
                return $info['playtime_seconds'] ?? null;
            } catch (\Exception $e) {
                // Fallback к exec
            }
        }

        // Fallback через ffprobe или sox
        $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filepath) . " 2>/dev/null";
        $output = shell_exec($cmd);
        if ($output !== null) {
            return (float)trim($output);
        }

        return null;
    }
}
