<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class PruneStorageCommand extends Command
{
    protected $signature = 'storage:prune
                            {--path= : Katalog cache plikowego (domyślnie storage/framework/cache/data)}
                            {--log-dir= : Katalog logów (domyślnie storage/logs)}
                            {--log-days=14 : Kasuj pliki laravel*.log starsze niż N dni}
                            {--max-log-mb=80 : Przytnij laravel.log powyżej tego rozmiaru}';

    protected $description = 'Kasuje wygasły cache plikowy i stare / zbyt duże logi';

    public function handle(): int
    {
        $cacheDeleted = $this->pruneExpiredCache();
        $logsDeleted = $this->pruneLogs();

        $this->info("Usunięto {$cacheDeleted} wygasłych plików cache i {$logsDeleted} plików logów.");

        return self::SUCCESS;
    }

    private function pruneExpiredCache(): int
    {
        $root = (string) ($this->option('path') ?: config('cache.stores.file.path'));
        if ($root === '' || ! is_dir($root)) {
            return 0;
        }

        $now = time();
        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                $this->removeEmptyDirectory($path, $root);

                continue;
            }
            if (! $item->isFile() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            if ($this->cacheFileExpired($path, $now)) {
                $this->safeUnlink($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function cacheFileExpired(string $path, int $now): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }
        $expire = fread($handle, 10);
        fclose($handle);
        if (! is_string($expire) || strlen($expire) < 10 || ! ctype_digit($expire)) {
            return true;
        }

        return $now >= (int) $expire;
    }

    private function pruneLogs(): int
    {
        $dir = (string) ($this->option('log-dir') ?: storage_path('logs'));
        if ($dir === '' || ! is_dir($dir)) {
            return 0;
        }

        $days = max(1, (int) $this->option('log-days'));
        $maxBytes = max(1, (int) $this->option('max-log-mb')) * 1024 * 1024;
        $cutoff = time() - ($days * 86400);
        $deleted = 0;

        foreach (glob($dir.'/laravel*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            $name = basename($file);
            if ($name !== 'laravel.log' && filemtime($file) !== false && filemtime($file) < $cutoff) {
                $this->safeUnlink($file);
                $deleted++;

                continue;
            }
            if ($name === 'laravel.log' && filesize($file) > $maxBytes) {
                $this->trimLog($file);
            }
        }

        return $deleted;
    }

    private function trimLog(string $path): void
    {
        $keep = 2 * 1024 * 1024;
        $size = filesize($path);
        if ($size === false || $size <= $keep) {
            return;
        }
        $tail = file_get_contents($path, false, null, $size - $keep);
        if (! is_string($tail) || $tail === '') {
            return;
        }
        file_put_contents($path, $tail);
    }

    private function removeEmptyDirectory(string $path, string $root): void
    {
        if (realpath($path) === realpath($root)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        if (count(array_diff($entries, ['.', '..'])) === 0) {
            @rmdir($path);
        }
    }

    private function safeUnlink(string $path): void
    {
        try {
            @unlink($path);
        } catch (Throwable) {
        }
    }
}
