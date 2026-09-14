<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Przetarg 1 (tenders:debug-match na produkcji, odtworzone na lokalnej kopii katalogu MySQL): karta oczekiwana
 * nie docierała do modelu. Poz. 7 — ATG 44-304 z dowodem wszystkich warunków stała na 53. miejscu puli za kartami
 * z jedną igłą; poz. 15 — kaskada z kompletem kroków kończyła wyszukiwanie, a AlphaTec 87320 nie ma słowa
 * „rękawice” w nazwie.
 */
final class RankCardsConstraintEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_with_evidence_of_more_conditions_reach_ranking_first(): void
    {
        $base = [
            'manufacturer' => 'MAPA',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ];
        $pool = collect();
        for ($i = 1; $i <= 30; $i++) {
            $pool->push(Product::query()->create($base + [
                'sku' => 'ONE-'.$i,
                'name' => 'Rękawice robocze powlekane '.$i,
                'description' => 'Rękawice powlekane, pakowane po 100 par.',
            ]));
        }
        $pool->push(Product::query()->create([
            'sku' => '44-304',
            'manufacturer' => 'ATG',
            'name' => 'Ściągacz, oblanie części chwytnej',
            'description' => 'Rękawice antyprzecięciowe ATG MaxiCut Oil do pracy w środowisku zaolejonym; ochrona przed ciepłem kontaktowym do 100°C przez 15 sekund; bez silikonu.',
            'norms' => 'EN 388:2016 + A1:2018, EN 407:2004',
        ] + $base));

        $service = app(ProductAiSearchService::class);
        $cards = (new \ReflectionMethod($service, 'cardsForRanking'))->invoke(
            $service,
            'Rękawice ochronne antyprzecięciowe powlekane, ochrona przed ciepłem kontaktowym do 100°C',
            $pool,
            ['EN 388:2016', 'EN 407:2004', 'ciepło kontaktowe 100°C', 'bez silikonu'],
        )->pluck('sku')->all();

        $this->assertCount(24, $cards);
        $this->assertSame('44-304', $cards[0], 'karta z dowodem największej liczby warunków idzie do modelu pierwsza');
    }

    /**
     * Produkcja (debug-match po 2e0099b): 44-304 w kandydatach, dowód 8/10 igieł, a w kartach rankingu najsłabsza
     * karta miała 1/10 — reguła odporności na przecięcie brała do rankingu tylko karty z przecięciem w nazwie.
     */
    public function test_card_outside_name_rule_with_full_evidence_reaches_ranking(): void
    {
        $base = [
            'manufacturer' => 'MAPA',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ];
        $candidates = collect();
        for ($i = 1; $i <= 30; $i++) {
            $candidates->push(Product::query()->create($base + [
                'sku' => 'CUT-'.$i,
                'name' => 'Rękawice antyprzecięciowe HPPE powlekane '.$i,
                'description' => 'Rękawice HPPE, pakowane po 100 par.',
            ]));
        }
        $candidates->push(Product::query()->create([
            'sku' => '44-304',
            'manufacturer' => 'ATG',
            'name' => 'Ściągacz, oblanie części chwytnej',
            'description' => 'Rękawice antyprzecięciowe ATG MaxiCut Oil do pracy w środowisku zaolejonym; ochrona przed ciepłem kontaktowym do 100°C przez 15 sekund; bez silikonu.',
            'norms' => 'EN 388:2016 + A1:2018, EN 407:2004',
        ] + $base));
        $query = 'Rękawice ochronne antyprzecięciowe powlekane, ochrona przed ciepłem kontaktowym do 100°C';
        $constraints = ['EN 388:2016', 'EN 407:2004', 'ciepło kontaktowe 100°C', 'bez silikonu'];

        $service = app(ProductAiSearchService::class);
        $rows = (new \ReflectionMethod($service, 'rowsFromCutResistanceMatches'))->invoke($service, $query, $candidates, 80);
        $this->assertNotContains('44-304', array_column($rows, 'sku'), 'reguła czyta nazwę karty — ATG nie jest kartą reguły');

        $prepared = (new \ReflectionMethod($service, 'ruleRowsRankedByModel'))->invoke($service, $query, $rows, $candidates, $constraints);
        $cards = $prepared['rank_cards']->pluck('sku')->all();

        $this->assertContains('44-304', $cards, 'karta z dowodem wszystkich warunków trafia do modelu mimo nazwy z cennika');
        $this->assertSame('44-304', $cards[0]);
        $this->assertNotContains('44-304', array_column($prepared['products'], 'sku'), 'zapas bez modelu to nadal tylko wiersze reguły');
    }

    /**
     * Przetarg 1 poz. 5 (debug-match 14.09, 3/3 przebiegi puste): model dał SBM01 FLUO 95, ale zgłosił brak dowodu
     * „odporność na zginanie do -50°C” i kod obciął ocenę do 50. Zdanie stoi w opisie na znaku 950, a model widzi
     * 360 znaków opisu i 4 cechy. Karta dla modelu niesie dosłowne fragmenty potwierdzające warunki — bez powtarzania
     * tego, co model już widzi.
     */
    public function test_rank_card_carries_verbatim_evidence_for_conditions_hidden_beyond_visible_text(): void
    {
        $intro = str_repeat('Spodniobuty PROS MAX S5 FLUO to profesjonalna odzież wodoochronna z wbudowanymi kaloszami. ', 6);
        $card = Product::query()->create([
            'sku' => 'SBM01 FLUO',
            'name' => 'Spodniobuty Max ze wzmocnieniem kalosz typ S5 w kolorach fluo',
            'manufacturer' => 'AJ GROUP',
            'category' => 'Odzież',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'description' => $intro.'Materiał charakteryzuje się odpornością na zginanie bez pękania w temperaturze do -50°C. Obustronnie zgrzewane szwy.',
            'norms' => 'EN ISO 20345 – obuwie bezpieczne (typ ochrony S5 SRC), EN 343',
            'enrichment_payload' => ['features' => [
                'Wodoszczelność i odporność na rozdarcia',
                'Wbudowane kalosze typu S5 z wkładką antyprzebiciową',
                'Wzmocnienia na kolanach',
                'Regulacja w pasie za pomocą sznurka',
                'Wymienne szelki z szerokiej gumy',
            ]],
            'catalog_price_net' => 250,
            'purchase_price' => 202.3,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $constraints = ['EN ISO 20345 S5', 'EN 343', 'wkładka antyprzebiciowa', 'odporność na zginanie do -50°C'];
        $service = app(ProductAiSearchService::class);
        $rankCard = new \ReflectionMethod($service, 'rankCard');

        $this->assertStringNotContainsString('-50', mb_substr((string) $card->description, 0, 360));
        $evidence = $rankCard->invoke($service, $card, false, $constraints)['constraint_evidence'] ?? null;

        $this->assertIsArray($evidence);
        $this->assertCount(1, $evidence, 'tylko to, czego model nie widzi: wkładka jest w 4 cechach, normy w polu norms');
        $this->assertStringContainsString('do -50°C', $evidence[0]);
        $this->assertLessThanOrEqual(200, mb_strlen($evidence[0]));
        $this->assertSame([], $rankCard->invoke($service, $card, false)['constraint_evidence'], 'bez warunków nie ma fragmentów');

        $messages = (new \ReflectionMethod($service, 'rankMessages'))->invoke(
            $service,
            'Spodniobuty wodoochronne z wgrzanymi kaloszami S5 SRC, EN 343, odporne na zginanie do -50°C',
            collect([$card]),
            10,
            'spodniobuty wodoochronne z kaloszami',
            $constraints,
            AiTask::ProductSearch,
        );
        $content = implode("\n", array_column($messages, 'content'));
        $this->assertStringContainsString('constraint_evidence', $content, 'pole dowodu wymienione w poleceniu i obecne na karcie');
        $this->assertStringContainsString('do -50', $content);
    }

    public function test_complete_cascade_does_not_end_retrieval_before_text_search(): void
    {
        Http::fake();
        Opisowy15Fixture::seed();
        $service = app(ProductAiSearchService::class);
        $intent = [
            'needed' => 'rękawice ochronne lateksowe flokowane',
            'search_steps' => ['rękawice', 'lateks naturalny', 'flokowane', 'długość 300 mm'],
            'search_phrases' => ['rękawice ochronne lateksowe flokowane', 'rękawice lateksowe flokowane do kontaktu z żywnością'],
            'constraints' => ['kontakt z żywnością', 'AQL 1,5', 'odporność na ścieranie'],
        ];

        $candidates = (new \ReflectionMethod($service, 'retrieveCandidates'))
            ->invoke($service, Opisowy15Fixture::requirement(15), $intent, 80)
            ->pluck('sku')
            ->all();
        $cascade = $service->lastTrace()['cascade'];
        $last = end($cascade);

        $this->assertFalse($last['ended_retrieval'], 'kaskada nie kończy wyszukiwania — jej karty to jedna lista w fuzji rang');
        $this->assertContains('87320100-BULK', $candidates, 'AlphaTec 87320 (bez „rękawice” w nazwie) wchodzi do puli z wyszukiwania tekstowego');
    }
}
