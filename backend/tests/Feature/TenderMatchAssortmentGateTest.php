<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderMatchAssortmentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_glasses_do_not_get_gloves_or_invented_percent(): void
    {
        $gloves = $this->catalogProduct([
            'sku' => '11-541',
            'name' => 'HYFLEX 11-541 Rękawice montażowe',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze HyFlex 11-541.',
        ]);

        $tender = $this->makeTender('PRZ/GATE/GLASSES');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Okulary ochronne przyciemniane HYFLEX 11-541',
            'quantity' => 1,
            'status' => 'brak',
            'main_product_id' => $gloves->id,
            'ai_match_percent' => 90,
            'ai_match_reasons' => [
                ['code' => 'ai', 'label' => 'Zmyślone 90%', 'points' => 90],
                ['code' => 'asortyment_reject', 'label' => 'Konflikt asortymentu (eyes vs gloves)', 'points' => 0],
            ],
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('cleared', 1);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertNull($item->ai_match_percent);
        $this->assertSame('brak', $item->status);
        $this->assertNotSame('asortyment_reject', ($item->ai_match_reasons[0]['code'] ?? null));
    }

    public function test_rain_jacket_does_not_match_respirator(): void
    {
        $this->catalogProduct([
            'sku' => 'SECURA-3000',
            'name' => 'Półmaska SECURA 3000 część twarzowa',
            'manufacturer' => 'SECURA',
            'category' => 'drogi_oddechowe',
            'description' => 'Półmaska wielokrotnego użytku SECURA 3000.',
        ]);

        $tender = $this->makeTender('PRZ/GATE/JACKET');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Kurtka przeciwdeszczowa EN 343 EN 1149-5',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 0);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertNull($item->ai_match_percent);
    }

    public function test_gloves_do_not_match_rain_set(): void
    {
        $this->catalogProduct([
            'sku' => 'B50',
            'name' => 'Komplet przeciwdeszczowy B50 bluza + spodnie',
            'manufacturer' => 'X',
            'category' => 'odziez',
            'description' => 'Ubranie przeciwdeszczowe komplet bluza i spodnie.',
        ]);

        $tender = $this->makeTender('PRZ/GATE/GLOVES');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice lateksowe sterylne',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 0);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertNull($item->ai_match_percent);
    }

    public function test_polar_jacket_does_not_get_pola_gloves(): void
    {
        $this->catalogProduct([
            'sku' => 'POLA',
            'name' => 'POLA - EN 420 KAT. II, EN 388 - 3131',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Rękawice ochronne POLA.',
            'norms' => 'EN 420 EN 388',
        ]);

        $tender = $this->makeTender('PRZ/GATE/POLAR');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 9,
            'requirement' => 'KURTKA DAMSKA - POLAR granatowy rozm. S - XXXXL',
            'quantity' => 15,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 0);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertNull($item->ai_match_percent);
    }

    public function test_same_family_gloves_still_match(): void
    {
        $this->catalogProduct([
            'sku' => 'RNITZ-9',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ kat. 2 ze ściągaczem. Materiał: nitryl.',
            'enrichment_payload' => ['materials' => ['nitryl']],
        ]);

        $tender = $this->makeTender('PRZ/GATE/OK');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice robocze nitrylowe REJS RNITZ kat. 2 ze ściągaczem',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame('RNITZ-9', $item->mainProduct?->sku);
        $this->assertNotNull($item->ai_match_percent);
        $this->assertGreaterThanOrEqual(65, (int) $item->ai_match_percent);
        $this->assertNotSame('asortyment_reject', ($item->ai_match_reasons[0]['code'] ?? null));
    }

    /** Poz. 5 audytu: „wymienne szelki” spodniobutów nie robią z pozycji asekuracji; spodnie z szelkami to inny krój. */
    public function test_waders_do_not_get_harness(): void
    {
        $this->catalogProduct([
            'sku' => 'AB178',
            'name' => 'Szelki bezpieczeństwa typu kamizelka 3M™ Protecta® FIRST, kolor niebieski, rozmiar uniwersalny, AB17510CE',
            'manufacturer' => '3M',
            'category' => 'Środki ochrony indywidualnej',
            'description' => 'Szelki typu kamizelka zabezpieczające przed upadkiem z wysokości 3M™ Protecta® E50.',
            'purchase_price' => 1.00,
        ]);
        $this->catalogProduct([
            'sku' => '3112',
            'name' => 'Spodnie do pasa z szelkami Extreme',
            'manufacturer' => 'AJ GROUP',
            'category' => 'Asekuracja',
            'description' => 'Spodnie do pasa z szelkami do pracy na morzu lub w porcie. Wzmocnienia na kolanach, guma w pasie oraz elastyczne szelki.',
            'purchase_price' => 1.50,
        ]);
        $waders = $this->catalogProduct([
            'sku' => 'SB04 AIR',
            'name' => 'Spodniobuty oddychające AIR',
            'manufacturer' => 'AJ GROUP',
            'category' => 'Odzież',
            'description' => 'Wzmocnienia na kolanach. Regulowane w pasie za pomocą sznurka. Wymienne szelki z elastycznej, szerokiej gumy. '
                .'Obustronnie zgrzewane szwy. Wgrzane na stałe kalosze typu S5 z wkładką antyprzebiciową. EN ISO 20345, EN 343.',
            'purchase_price' => 9.00,
        ]);
        $this->assertSame('apparel', $waders->fresh()?->ppe_family);

        $tender = $this->makeTender('PRZ/GATE/WADERS');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 5,
            'requirement' => 'Spodniobuty wodoochronne z wgrzanymi na stałe kaloszami – obuwie bezpieczne typu S5 SRC wg EN ISO 20345, '
                .'z wkładką antyprzebiciową. Wymagane: tkanina powlekana PVC, EN 343; szwy zgrzewane obustronnie; wzmocnienia na kolanach; '
                .'regulacja w pasie sznurkiem; wymienne szelki z szerokiej elastycznej gumy; odporność do -50°C. Rozmiary: 39–48.',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame('SB04 AIR', $item->mainProduct?->sku);
        $this->assertNotSame('asortyment_reject', ($item->ai_match_reasons[0]['code'] ?? null));
    }

    /** Poz. 13 audytu: półmaska wielokrotnego użytku (EN 140) ≠ jednorazowa FFP1 — nawet tańsza. */
    public function test_reusable_half_mask_line_does_not_get_ffp(): void
    {
        $this->catalogProduct([
            'sku' => '9310+',
            'name' => '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+',
            'manufacturer' => '3M',
            'category' => 'Środki ochrony indywidualnej',
            'description' => 'Półmaska filtrująca 3M Aura 9310+ to jednorazowa półmaska klasy FFP1 do ochrony przed pyłami do 4 x NDS. EN 149.',
            'purchase_price' => 1.00,
        ]);
        $this->catalogProduct([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'category' => 'PÓŁMASKA SECURA 3000',
            'description' => 'Półmaska SECURA 3000 składa się z korpusu, dwóch zaworów wdechowych z łącznikami bagnetowymi, '
                .'zaworu wydechowego oraz nagłowia. Zgodność z PN-EN 140:2004.',
            'purchase_price' => 20.00,
        ]);

        $tender = $this->makeTender('PRZ/GATE/HALFMASK');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 13,
            'requirement' => 'Półmaska wielokrotnego użytku do ochrony układu oddechowego – po skompletowaniu z odpowiednimi elementami '
                .'oczyszczającymi chroni przed aerozolami, parami i gazami. Wymagane: korpus z dwoma zaworami wdechowymi z łącznikami '
                .'bagnetowymi; zawór wydechowy z pokrywą; jednoczęściowe nagłowie tekstylne; zgodność z PN-EN 140:2004.',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame('S56T0SM0', $item->mainProduct?->sku);
    }

    /** Poz. 12 audytu: wymagana elektroizolacja (EN 50321‑1, kV) — tańsze zwykłe OB z ESD nie jest dowodem. */
    public function test_insulating_shoes_line_does_not_get_cheaper_ob_shoe(): void
    {
        $this->catalogProduct([
            'sku' => 'ART 702 Air 6660 OB A E FO',
            'name' => 'ART 702 Air 6660 OB A E FO',
            'manufacturer' => 'ARTRA',
            'description' => 'Obuwie robocze ART 702 Air 6660 OB A E FO to lekkie buty do kontroli ładunków elektrostatycznych. '
                .'Spełnia normę EN ISO 20347:2012 w klasie OB A E FO SRC oraz wymagania ESD zgodnie z EN IEC 61340-4-3:2018.',
            'purchase_price' => 31.70,
        ]);
        $this->catalogProduct([
            'sku' => 'T5912100',
            'name' => 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER',
            'manufacturer' => 'SECURA',
            'category' => '11.1 OBUWIE ELEKTROIZOLACYJNE',
            'description' => 'Półbuty elektroizolacyjne ANTYAMPER 20 kV marki SECURA do pracy przy instalacjach o napięciu do 17 kV. '
                .'Produkt klasy 2 AC zgodnie z normą EN 50321-1. Wykonane z gumy naturalnej. EN 20347:2012 kategorii OB, SRA.',
            'purchase_price' => 384.73,
        ]);

        $tender = $this->makeTender('PRZ/GATE/KV');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 12,
            'requirement' => 'Półbuty elektroizolacyjne do prac przy urządzeniach i instalacjach elektroenergetycznych o napięciu przemiennym '
                .'do 17 kV, przeznaczone do nakładania na inne obuwie robocze. Wymagane: klasa 2 AC zgodnie z normą EN 50321-1; '
                .'wykonanie z gumy naturalnej; zgodność z normą EN 20347:2012 dla obuwia zawodowego kategorii OB; odporność na poślizg SRA.',
            'quantity' => 4,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $item->refresh();
        $this->assertSame('T5912100', $item->mainProduct?->sku);
    }

    /** Poz. 1 audytu: ochraniacz przedramienia (rękaw) to nie rękawice — także gdy nazwa karty to goły kod, a opis nazywa model. */
    public function test_sleeve_line_does_not_get_gloves(): void
    {
        $this->catalogProduct([
            'sku' => '48130110',
            'name' => 'HyFlex 48130',
            'manufacturer' => 'Ansell',
            'description' => 'Rękawice ochronne Ansell HyFlex 48-130 to lekkie rękawice montażowe z powłoką poliuretanową, ESD. '
                .'Spełniają EN 420 i EN 388 (4.1.3.1.A).',
            'purchase_price' => 1.00,
        ]);
        $this->catalogProduct([
            'sku' => '11202000',
            'name' => 'HyFlex 11202 SIZE 19\'\'/47,5 cm',
            'manufacturer' => 'Ansell',
            'description' => 'The new HyFlex® 11-202 HI-VIZ™ arm protector offers optimum wearing comfort. Ansell HyFlex 11-202 Hi-Vis '
                .'Cut-Resistant Sleeve with Velcro Fixing System. Standards: EN 420:2003 + A1:2009, Cat.III EN 407 (X.1.X.X.X), EN388 (2.X.4.2.C).',
            'purchase_price' => 5.00,
        ]);

        $tender = $this->makeTender('PRZ/GATE/SLEEVE');
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze '
                .'fluorescencyjnym żółtym. Konstrukcja bezszwowa, dzianina nylon/poliester/włókno szklane; regulowane zapięcie na rzep. '
                .'Wymagane: ŚOI kategorii III; EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C; EN 407 poziom 1.',
            'quantity' => 20,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk();

        $item->refresh();
        $this->assertNotSame('48130110', $item->mainProduct?->sku, 'rękawice zapisane dla rękawa');
        $this->assertContains($item->mainProduct?->sku, ['11202000', null]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function catalogProduct(array $attrs): Product
    {
        return Product::query()->create(array_merge([
            'catalog_price_net' => 3.50,
            'purchase_price' => 2.00,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ], $attrs));
    }

    private function makeTender(string $number): Tender
    {
        return Tender::query()->create([
            'number' => $number,
            'title' => 'Bramka asortymentu',
            'client_id' => Client::query()->create(['name' => 'Klient GATE'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }
}
