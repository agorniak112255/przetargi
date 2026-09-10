<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PruneStorageCommandTest extends TestCase
{
    public function test_removes_expired_cache_and_keeps_fresh(): void
    {
        $dir = storage_path('framework/testing/prune-cache-'.bin2hex(random_bytes(4)));
        $nested = $dir.'/aa/bb';
        mkdir($nested, 0777, true);

        $expired = $nested.'/expired';
        $fresh = $nested.'/fresh';
        file_put_contents($expired, str_pad((string) (time() - 30), 10, '0', STR_PAD_LEFT).serialize('old'));
        file_put_contents($fresh, str_pad((string) (time() + 3600), 10, '0', STR_PAD_LEFT).serialize('new'));

        try {
            $this->artisan('storage:prune', [
                '--path' => $dir,
                '--log-dir' => $dir.'/no-logs',
            ])->assertSuccessful();

            $this->assertFileDoesNotExist($expired);
            $this->assertFileExists($fresh);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_deletes_old_rotated_logs_and_trims_huge_laravel_log(): void
    {
        $dir = storage_path('framework/testing/prune-logs-'.bin2hex(random_bytes(4)));
        mkdir($dir, 0777, true);
        $old = $dir.'/laravel-2026-01-01.log';
        $current = $dir.'/laravel.log';
        file_put_contents($old, 'old');
        touch($old, time() - (20 * 86400));
        file_put_contents($current, str_repeat('x', 3 * 1024 * 1024));

        try {
            $this->artisan('storage:prune', [
                '--path' => $dir.'/no-cache',
                '--log-dir' => $dir,
                '--log-days' => 14,
                '--max-log-mb' => 1,
            ])->assertSuccessful();

            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($current);
            clearstatcache(true, $current);
            $this->assertLessThanOrEqual(2 * 1024 * 1024, filesize($current));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
