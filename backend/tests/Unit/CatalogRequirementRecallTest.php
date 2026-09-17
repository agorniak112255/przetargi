<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CatalogRequirementRecall;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogRequirementRecallTest extends TestCase
{
    use RefreshDatabase;

    private CatalogRequirementRecall $recall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recall = app(CatalogRequirementRecall::class);
    }

    #[Test]
    public function antistatic_gloves_query_builds_profile(): void
    {
        $this->assertTrue($this->recall->shouldBackfillCatalog('Rękawice antyelektrostatyczne'));
        $this->assertTrue($this->recall->shouldRecallToCandidatePool('Okulary ochronne UV'));
    }

    /**
     * Recall klasy obuwia idzie po hierarchii, nie po równości napisów: przy „S3” karta „S3L” (ten sam
     * S3 z określoną wkładką) i „S7” (S3 z wodoodpornością) muszą zostać, a „S1 P” — nie.
     */
    #[Test]
    public function footwear_class_recall_keeps_higher_classes_and_insert_suffixes(): void
    {
        $this->boot('ARYEL-S3L', 'Trzewik ARYEL 320 671460 S3L');
        $this->boot('TRZ-S7', 'Trzewik ochronny S7 SRC');
        $this->boot('TRZ-S1P', 'Trzewik roboczy ARCASIO 640 S1 P ESD');

        $found = $this->recall->retrieve(
            fn (): Builder => Product::query(),
            'Trzewiki robocze S3',
            20,
            fn (Product $p): string => $p->name.' '.(string) $p->description,
            fn (string $q, Product $p): int => 0,
            fn (Product $p): ?int => null,
        )->pluck('sku')->all();

        $this->assertContains('ARYEL-S3L', $found);
        $this->assertContains('TRZ-S7', $found);
        $this->assertNotContains('TRZ-S1P', $found);
    }

    private function boot(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => $name.' — trzewik bezpieczny, skóra, EN ISO 20345.',
            'catalog_price_net' => 100,
            'purchase_price' => 70,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
