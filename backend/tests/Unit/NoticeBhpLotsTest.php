<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bzp\NoticeBhpLots;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Części ogłoszenia z towarami BHP (kody CPV z config bzp.cpv_categories, środki ochrony w opisie) i numer pakietu
 * z nazwy dokumentu — przypadki z ogłoszeń na produkcji 03.10.2026.
 */
final class NoticeBhpLotsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function documentNames(): array
    {
        return [
            'pakiet nr' => ['Załącznik nr 2 do SWZ - Pakiet nr 3 - Formularz cenowy.xlsx', 3],
            'pakiet bez spacji przed myślnikiem' => ['Załącznik nr 1 do SWZ - Pakiet nr 4- Formularz ofertowy.docx', 4],
            'część rzymska wielkimi' => ['CZĘŚĆ II - formularz asortymentowy', 2],
            'cz. z kropką' => ['Formularz cenowy cz.2.xlsx', 2],
            'zadanie' => ['Zadanie nr 5 opis przedmiotu zamówienia', 5],
            'odmiana części' => ['OPZ do Części 3.pdf', 3],
            'sam załącznik nr' => ['Załącznik nr 2 do SWZ', null],
            'bez numeru' => ['SWZ.pdf', null],
        ];
    }

    #[DataProvider('documentNames')]
    public function test_lot_number_from_document_name(string $name, ?int $expected): void
    {
        $this->assertSame($expected, NoticeBhpLots::lotNumberOf($name));
    }

    public function test_cpv_category_takes_the_longest_prefix(): void
    {
        $this->assertSame('Rękawice', NoticeBhpLots::cpvCategory('18424300-0'));
        $this->assertSame('Odzież robocza i ochronna', NoticeBhpLots::cpvCategory('18130000-9'));
        $this->assertNull(NoticeBhpLots::cpvCategory('33140000-3'));
    }

    public function test_lot_is_bhp_by_additional_cpv_or_protective_equipment_in_description(): void
    {
        $lots = new NoticeBhpLots;

        $gloves = $lots->forLot(['name' => 'Pakiet nr 3 – papiery do EKG, rękawice', 'cpv_main' => '33140000-3', 'cpv_additional' => ['33124130-5', '18424300-0']]);
        $this->assertTrue($gloves['bhp']);
        $this->assertSame('kod rodzaju zamówienia (CPV) 18424300-0 — rękawice', $gloves['reason']);

        $this->assertTrue($lots->forLot(['name' => 'Część 3 -Dostawa Hełmów strażackich i latarek', 'cpv_main' => '35110000-8'])['bhp']);
        $this->assertTrue($lots->forLot(['name' => 'Szelki bezpieczeństwa sztuk 4', 'cpv_main' => '44212320-6'])['bhp']);

        // sprzęt medyczny i „ewakuacyjny” bez sprzętu asekuracyjnego — nie BHP
        $this->assertFalse($lots->forLot(['name' => 'Pakiet nr 4 – opatrunki, maski krtaniowe, tlenowe', 'cpv_main' => '33140000-3', 'cpv_additional' => ['33157110-9']])['bhp']);
        $this->assertFalse($lots->forLot(['name' => 'Część 5: Przyczepka do ewakuacji poszkodowanych z trudno dostępnych miejsc, podpinana do pojazdu typu ATV.', 'cpv_main' => '34223300-9'])['bhp']);
        $this->assertFalse($lots->forLot(['name' => 'Prześcieradło ewakuacyjne szt. 25', 'cpv_main' => '33141000-0'])['bhp']);
    }
}
