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
