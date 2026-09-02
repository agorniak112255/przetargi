<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Presta\PrestaSettingsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrestaWebserviceKeyTest extends TestCase
{
    #[Test]
    public function strips_spaces_and_url_wrapper(): void
    {
        $this->assertSame(
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            PrestaSettingsService::normalizeWebserviceKey(' ABCD EFGH IJKL MNOP QRST UVWX YZ01 2345 ')
        );
        $this->assertSame(
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            PrestaSettingsService::normalizeWebserviceKey('https://shop.example/api?ws_key=ABCDEFGHIJKLMNOPQRSTUVWXYZ012345')
        );
        $this->assertTrue(PrestaSettingsService::isValidWebserviceKey('ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'));
        $this->assertFalse(PrestaSettingsService::isValidWebserviceKey('za-krotki'));
    }
}
