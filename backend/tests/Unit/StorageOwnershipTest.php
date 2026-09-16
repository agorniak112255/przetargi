<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StorageOwnership;
use PHPUnit\Framework\TestCase;

/**
 * Prostowanie właściciela plików w storage działa tylko wtedy, gdy polecenie idzie z konta root — w każdym
 * innym przypadku (także lokalnie na Windows) nie rusza niczego.
 */
final class StorageOwnershipTest extends TestCase
{
    public function test_does_nothing_when_the_command_is_not_run_as_root(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('test uruchomiony jako root — sprawdzamy ścieżkę bez roota');
        }

        $this->assertSame(0, StorageOwnership::restoreAfterRoot(dirname(__DIR__, 2)));
        $this->assertSame(0, StorageOwnership::restoreAfterRoot(sys_get_temp_dir()));
    }
}
