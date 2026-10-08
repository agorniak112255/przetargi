<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ManufacturerProfile;
use Tests\TestCase;

/**
 * Ten sam model w innym rozmiarze (code.size_letters) — półmaska SECURA 3000 S/M/L = S56T0SS0/SM0/SL0 (audyt SECURA
 * 08.10.2026).
 */
final class ManufacturerProfileSizeSiblingTest extends TestCase
{
    public function test_codes_differing_only_by_size_letter_are_siblings(): void
    {
        $secura = $this->profile(['XS', 'S', 'M', 'L', 'XL']);

        $this->assertTrue($secura->sizeSibling('S56T0SL0', 'S56T0SM0'));
        $this->assertTrue($secura->sizeSibling('S56T0SS0', 'S56T0SL0'));
        $this->assertTrue($secura->sizeSibling('s56t0-sm0', 'S56T0SL0'), 'porównanie po kluczu kodu');
        $this->assertTrue($secura->sizeSibling('KOMB-XS', 'KOMB-XL'));
        // inna cyfra (SECURA 3100), ten sam kod, inna długość, litera na początku kodu
        $this->assertFalse($secura->sizeSibling('S56T1SM0', 'S56T0SM0'));
        $this->assertFalse($secura->sizeSibling('S56T0SM0', 'S56T0SM0'));
        $this->assertFalse($secura->sizeSibling('KOMB-L', 'KOMB-XL'));
        $this->assertFalse($secura->sizeSibling('S5921000', 'M5921000'));
        // grupa gazu to nie rozmiar
        $this->assertFalse($secura->sizeSibling('S565A202', 'S565E202'));
        // profil bez rozmiarów
        $this->assertFalse($this->profile([])->sizeSibling('S56T0SL0', 'S56T0SM0'));
    }

    /**
     * @param  list<string>  $sizes
     */
    private function profile(array $sizes): ManufacturerProfile
    {
        return new ManufacturerProfile(
            brandKey: 'secura',
            hosts: ['securabc.com'],
            onlyManufacturer: true,
            catalogs: [],
            identityIn: ['url', 'title', 'markup'],
            codeNormalize: 'upper_alnum',
            minLength: 4,
            modelRegex: null,
            modelAliasIsKey: false,
            resolver: null,
            profileKey: 'secura',
            longestCodeWins: true,
            indexLabel: 'Indeks',
            sizeLetters: $sizes,
        );
    }
}
