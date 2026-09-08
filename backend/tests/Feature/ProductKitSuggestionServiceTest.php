<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAccessory;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductKitSuggestionService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class ProductKitSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_secura_mask_with_wrong_manufacturer_ranks_secura_filter_first(): void
    {
        $mask = $this->product('S56T0SM0', 'Półmaska SECURA 3000 silikonowa', '3M');
        $secura = $this->product('3025', 'Pochłaniacz Secura 3025', 'SECURA');
        $threeM = $this->product('5925', '3M filtr cząstek stałych 5925 P2', '3M');

        $pool = $this->pool($mask);
        $this->assertTrue($pool->contains('id', $secura->id));
        $securaPos = $pool->search(static fn (Product $row): bool => (int) $row->id === (int) $secura->id);
        $threeMPos = $pool->search(static fn (Product $row): bool => (int) $row->id === (int) $threeM->id);
        $this->assertNotFalse($securaPos);
        if ($threeMPos !== false) {
            $this->assertLessThan($threeMPos, $securaPos);
        }
    }

    public function test_mask_pool_keeps_filter_and_drops_second_mask_and_jacket(): void
    {
        $mask = $this->product('SEC-3000', 'Półmaska Secura 3000', 'SECURA');
        $filter = $this->product('3041', 'Filtropochłaniacz Secura 3041', 'SECURA');
        $otherMask = $this->product('3M-6200', 'Półmaska 3M 6200', '3M');
        $jacket = $this->product('KOL-B', 'Bluza KOLPEO', 'PROS');

        $pool = $this->pool($mask);

        $this->assertTrue($pool->contains('id', $filter->id));
        $this->assertFalse($pool->contains('id', $otherMask->id));
        $this->assertFalse($pool->contains('id', $jacket->id));
        $this->assertStringContainsString('pochłaniacz', $this->userPrompt($mask, $pool));
        $this->assertStringContainsString('Nie dokładaj drugiej maski', $this->userPrompt($mask, $pool));
    }

    public function test_helmet_pool_keeps_earmuffs_and_visor(): void
    {
        $helmet = $this->product('VGARD', 'Hełm MSA V-Gard', 'MSA');
        $earmuffs = $this->product('MSA-LOW', 'Nauszniki na hełm MSA', 'MSA');
        $visor = $this->product('V-100', 'Osłona twarzy do hełmu MSA', 'MSA');
        $boots = $this->product('S3-1', 'Trzewiki ochronne S3', 'Urg');

        $pool = $this->pool($helmet);

        $this->assertTrue($pool->contains('id', $earmuffs->id));
        $this->assertTrue($pool->contains('id', $visor->id));
        $this->assertFalse($pool->contains('id', $boots->id));
        $this->assertStringContainsString('nauszniki na hełm', mb_strtolower($this->userPrompt($helmet, $pool)));
    }

    public function test_jacket_pool_keeps_same_line_pants_not_another_jacket(): void
    {
        $jacket = $this->product('KOL-B', 'Bluza KOLPEO', 'PROS');
        $pants = $this->product('KOL-S', 'Spodnie KOLPEO', 'PROS');
        $otherJacket = $this->product('REIS-B', 'Bluza polarowa REIS', 'Reis');

        $pool = $this->pool($jacket);

        $this->assertTrue($pool->contains('id', $pants->id));
        $this->assertFalse($pool->contains('id', $otherJacket->id));
        $this->assertStringContainsString('spodnie albo ogrodniczki', mb_strtolower($this->userPrompt($jacket, $pool)));
    }

    public function test_ffp_prompt_says_disposable_has_no_kit(): void
    {
        $ffp = $this->product('9310+', '3M Aura półmaska filtrująca FFP1 bez zaworu', '3M');
        $prompt = $this->userPrompt($ffp, $this->pool($ffp));

        $this->assertStringContainsString('jednorazowa', mb_strtolower($prompt));
        $this->assertStringContainsString('nie ma zestawu', mb_strtolower($prompt));
    }

    public function test_glasses_pool_drops_cleaning_pads(): void
    {
        $glasses = $this->product('PHEOS', 'Okulary ochronne Uvex Pheos', 'Uvex');
        $case = $this->product('ETUI-1', 'Etui na okulary ochronne', 'Uvex');
        $pad = $this->product('8541', '3M Doodlebug pad czyszczący brązowy', '3M');

        $pool = $this->pool($glasses);

        $this->assertTrue($pool->contains('id', $case->id));
        $this->assertFalse($pool->contains('id', $pad->id));
    }

    public function test_glasses_pool_keeps_case_not_second_pair(): void
    {
        $glasses = $this->product('PHEOS', 'Okulary ochronne Uvex Pheos', 'Uvex');
        $case = $this->product('ETUI-1', 'Etui na okulary ochronne', 'Uvex');
        $other = $this->product('PERSP', 'Okulary ochronne MSA Perspecta', 'MSA');

        $pool = $this->pool($glasses);

        $this->assertTrue($pool->contains('id', $case->id));
        $this->assertFalse($pool->contains('id', $other->id));
        $this->assertStringContainsString('etui', mb_strtolower($this->userPrompt($glasses, $pool)));
    }

    public function test_harness_pool_keeps_lanyard_not_mask(): void
    {
        $harness = $this->product('P-30', 'Szelki bezpieczeństwa P-30', 'Protekt');
        $lanyard = $this->product('B-KR', 'Linka bezpieczeństwa z amortyzatorem', 'Protekt');
        $mask = $this->product('SEC-3000', 'Półmaska Secura 3000', 'SECURA');

        $pool = $this->pool($harness);

        $this->assertTrue($pool->contains('id', $lanyard->id));
        $this->assertFalse($pool->contains('id', $mask->id));
        $this->assertStringContainsString('lonża', mb_strtolower($this->userPrompt($harness, $pool)));
    }

    public function test_system_prompt_forbids_substitutes_and_asks_for_kit(): void
    {
        $mask = $this->product('SEC-3000', 'Półmaska Secura 3000', 'SECURA');
        $this->product('3041', 'Filtropochłaniacz Secura 3041', 'SECURA');
        $messages = $this->messages($mask);
        $system = $messages[0]['content'];

        $this->assertStringContainsString('ZESTAW', $system);
        $this->assertStringContainsString('nie szukasz zamiennika', $system);
        $this->assertStringContainsString('drugiego egzemplarza tego samego typu', $system);
        $this->assertStringContainsString('SECURA', $system);
        $this->assertStringContainsString('"picks"', $system);
    }

    public function test_suggest_uses_only_ai_picks_from_pool_and_skips_linked(): void
    {
        $mask = $this->product('SEC-3000', 'Półmaska Secura 3000', 'SECURA');
        $linked = $this->product('3025', 'Pochłaniacz Secura 3025', 'SECURA');
        $fresh = $this->product('3041', 'Filtropochłaniacz Secura 3041', 'SECURA');
        ProductAccessory::query()->create([
            'product_id' => $mask->id,
            'related_product_id' => $linked->id,
            'source' => ProductAccessory::SOURCE_MANUAL,
            'link_key' => 'm:'.$linked->id,
            'related_sku' => $linked->sku,
            'related_name' => $linked->name,
            'score' => 100,
            'method' => 'manual',
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use ($fresh, $linked) {
            $blob = implode("\n", array_map(static fn (array $m): string => (string) $m['content'], $messages));
            $this->assertStringContainsString((string) $fresh->id, $blob);
            $this->assertStringNotContainsString('"id":'.$linked->id, $blob);

            return ['picks' => [
                ['id' => $fresh->id, 'role' => 'filtr', 'reason' => 'Do tej półmaski'],
                ['id' => $linked->id, 'role' => 'już jest', 'reason' => 'nie powinien wrócić'],
            ]];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = app(ProductKitSuggestionService::class)->suggest($mask);

        $this->assertSame(PpeAssortment::FAMILY_RESPIRATORY, $result['family']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame($fresh->id, $result['suggestions'][0]['id']);
        $this->assertSame('filtr', $result['suggestions'][0]['role']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function pool(Product $product)
    {
        $service = app(ProductKitSuggestionService::class);
        $family = app(PpeAssortment::class)->productFamily($product);
        $type = app(PpeAssortment::class)->articleTypePreferIdentity(
            trim($product->sku.' '.$product->name),
            (string) $product->name,
            $family
        );

        return $service->collectCandidates($product, $family, $type);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $pool
     */
    private function userPrompt(Product $product, $pool): string
    {
        return $this->messages($product, $pool)[1]['content'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>|null  $pool
     * @return list<array{role: string, content: string}>
     */
    private function messages(Product $product, $pool = null): array
    {
        $service = app(ProductKitSuggestionService::class);
        $family = app(PpeAssortment::class)->productFamily($product);
        $type = app(PpeAssortment::class)->articleTypePreferIdentity(
            trim($product->sku.' '.$product->name),
            (string) $product->name,
            $family
        );
        $pool ??= $service->collectCandidates($product, $family, $type);

        return $service->messages($product, $family, $type, $pool);
    }

    private function product(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }
}
