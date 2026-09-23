<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\B2bProductNameMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nazwa karty a nazwa u dostawcy B2B: ten sam wyrób czy nazwa innego wyrobu (23.09.2026: 173 karty Bollé
 * z nazwą innego wyrobu — przypadki z produkcji).
 */
final class B2bProductNameMatchTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_same_product(string $sku, string $cardName, ?string $remoteName, bool $expected): void
    {
        $this->assertSame($expected, B2bProductNameMatch::sameProduct($cardName, $remoteName, $sku));
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null, 3: bool}> */
    public static function cases(): array
    {
        return [
            // nazwa innego wyrobu
            'FLASHV' => ['FLASHV', 'Napotnik do przyłbic ELECTRO i ELECTRO+ (Pakiet 5 szt.)', 'FLASH – Welding helmet', false],
            'TRACPSF: sama „XP”' => ['TRACPSF', 'XP', 'TRACKER – Smoke safety glasses', false],
            'B808BLPSI' => ['B808BLPSI', 'Tylny ochraniacz głowy', 'B808 – Clear safety glasses', false],
            'RUSXMN10E: „XP” ma 2 znaki' => ['RUSXMN10E', 'XP', 'RUSH+ 2.0 XP - size M/L – Hybrid clear safety glasses', false],
            'BAXCSP: PC i AS za krótkie' => ['BAXCSP', 'Soczewki spawalnicze PC z powłokami AS, przyciemnienie 5 - czarne oprawy PC', 'BAXTER – Copper safety glasses', false],
            'wspólne tylko słowo ogólne' => ['SILSMK', 'Soczewka zapasowa SMOKE', 'SILEX SMOKE – Spare lens', false],
            'RUSH to nie RUSH+' => ['RUSHCL', 'Okulary RUSH bezbarwne', 'RUSH+ – Clear safety glasses', false],
            'SKU jako część innego słowa' => ['B808', 'Okulary B808BL', 'COBRA – Clear safety glasses', false],
            // nazwa u dostawcy wielkimi literami (Procera): zwykłe słowa nie są oznaczeniem modelu
            'wersaliki: wspólne tylko „okulary”' => ['TRACPSI', 'Okulary spawalnicze XP', 'OKULARY OCHRONNE BOLLE TRACKER (PRZEZROCZYSTE)', false],
            'wersaliki: wspólny kod z cyfrą' => ['B808BLPSI', 'Okulary ochronne B808', 'OKULARY OCHRONNE BOLLE B808 (PRZEZROCZYSTE)', true],
            // brak nazwy u dostawcy — nie wiadomo
            'nazwa u dostawcy null' => ['TRYBSSI', 'Okulary ochronne TRYON BSSI', null, false],
            'nazwa u dostawcy pusta' => ['TRYBSSI', 'Okulary ochronne TRYON BSSI', '  ', false],
            'pusta nazwa karty' => ['TRYBSSI', ' ', 'TRYON BSSI – Copper safety glasses', false],
            // ten sam wyrób
            'nazwy identyczne' => ['TRYBSSI', 'TRYON BSSI – Copper safety glasses', 'TRYON BSSI – Copper safety glasses', true],
            'TRYON BSSI' => ['TRYBSSI', 'Okulary ochronne TRYON BSSI, soczewka miedziana', 'TRYON BSSI – Copper safety glasses', true],
            'P1P10: szyba laserowa' => ['LSWP1P10', 'Szyba chroniąca przed laserem P1P10', 'P1P10 laser safety window', true],
            'SKU w nazwie karty' => ['ETUIMF', 'Etui z mikrofibry ETUIMF', 'CASE – Microfibre pouch', true],
            'bez wielkości liter' => ['TRYBSSI', 'Okulary Tryon — nazwa nadana ręcznie', 'TRYON BSSI – Copper safety glasses', true],
            'RUSH+ w obu nazwach' => ['RUSXMN10F', 'Gogle ochronne Rush+ z pianką', 'RUSH+ 2.0 XP - size M/L – Hybrid clear safety glasses', true],
        ];
    }
}
