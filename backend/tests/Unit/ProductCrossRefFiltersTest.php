<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Support\ProductCrossRefFilters;
use Tests\TestCase;

final class ProductCrossRefFiltersTest extends TestCase
{
    public function test_defaults_are_type_class_and_norms(): void
    {
        $product = $this->mask([
            'name' => 'Półmaska filtrująca FFP2 z zaworem',
            'description' => 'FFP2 NR D z zaworem, składana, EN 149.',
            'payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'use_cases' => ['praca w pyle'],
                'features' => ['zawór Cool Flow'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'klasa_ochrony' => 'FFP2',
                    'normy_en' => ['EN 149'],
                    'material' => 'włóknina',
                    'materialy' => ['włóknina'],
                ],
            ],
        ]);
        $attrs = (new BhpAttributeNormalizer)->forProduct($product);
        $groups = (new ProductCrossRefFilters)->groupsFor($product, $attrs);
        $items = collect($groups)->pluck('items')->flatten(1);
        $defaults = $items->where('default', true)->pluck('id')->all();

        $this->assertContains('spec:typ:ffp', $defaults);
        $this->assertContains('spec:klasa:FFP2', $defaults);
        $this->assertContains('norm:en:149', $defaults);
        $this->assertNotContains('spec:zawor:1', $defaults);
        $this->assertNotContains('use:v:praca-w-pyle', $defaults);
    }

    public function test_must_and_rejects_missing_valve_and_wrong_norm(): void
    {
        $filters = new ProductCrossRefFilters;
        $normalizer = new BhpAttributeNormalizer;

        $withValve = $this->mask([
            'name' => 'Półmaska filtrująca FFP2 z zaworem',
            'description' => 'FFP2 z zaworem EN 149',
            'payload' => [
                'norms' => ['EN 149'],
                'features' => ['zawór'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'klasa_ochrony' => 'FFP2',
                    'normy_en' => ['EN 149'],
                ],
            ],
        ]);
        $plain = $this->mask([
            'name' => 'Półmaska filtrująca FFP2 bez zaworu',
            'description' => 'FFP2 bez zaworu EN 149',
            'payload' => [
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'klasa_ochrony' => 'FFP2',
                    'normy_en' => ['EN 149'],
                ],
            ],
        ]);
        $wrongNorm = $this->mask([
            'name' => 'Półmaska filtrująca FFP2 z zaworem',
            'description' => 'FFP2 z zaworem EN 140',
            'payload' => [
                'norms' => ['EN 140'],
                'features' => ['zawór'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'klasa_ochrony' => 'FFP2',
                    'normy_en' => ['EN 140'],
                ],
            ],
        ]);

        $must = ['spec:zawor:1', 'norm:en:149'];
        $this->assertTrue($filters->matchesAll($must, $withValve, $normalizer->forProduct($withValve)));
        $this->assertFalse($filters->matchesAll($must, $plain, $normalizer->forProduct($plain)));
        $this->assertFalse($filters->matchesAll($must, $wrongNorm, $normalizer->forProduct($wrongNorm)));
    }

    /**
     * @param  array{name: string, description: string, payload: array<string, mixed>}  $data
     */
    private function mask(array $data): Product
    {
        $product = new Product;
        $product->sku = 'T';
        $product->name = $data['name'];
        $product->manufacturer = 'X';
        $product->category = 'Ochrona dróg oddechowych';
        $product->description = $data['description'];
        $product->enrichment_payload = $data['payload'];

        return $product;
    }
}
