<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
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
     * Igły warunków: dopasowanie od początku słowa („pary” ≠ „opary”, „zawor” → „zaworem”) i cała fraza z liczbą
     * („AQL 1,5” → „aql 1 5”, bo przecinek rozbijał liczbę na cyfry za krótkie na igłę). Wiersz oceniony przez model
     * niesie liczbę potwierdzonych warunków do decyzji przetargu.
     */
    public function test_constraint_needles_match_word_starts_and_numeric_phrases_and_reach_model_rows(): void
    {
        $base = [
            'manufacturer' => '3M',
            'category' => 'Drogi oddechowe',
            'ppe_family' => PpeAssortment::FAMILY_RESPIRATORY,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ];
        $plain = Product::query()->create($base + [
            'sku' => '9312+',
            'name' => 'Półmaska filtrująca FFP1 z zaworem 9312+',
            'description' => 'Półmaska FFP1 z zaworem wydechowym chroni przed pyłami i oparami wodnymi. AQL 1,5.',
        ]);
        $carbon = Product::query()->create($base + [
            'sku' => '9914',
            'name' => 'Półmaska filtrująca 9914 FFP1 (pyły i pary organiczne)',
            'description' => 'Półmaska FFP1 z zaworem i warstwą węgla aktywnego, pary organiczne poniżej NDS. EN 149. AQL 1,5.',
        ]);
        $constraints = ['pary organiczne', 'AQL 1,5', 'EN 149'];
        $service = app(ProductAiSearchService::class);

        $plainEvidence = $service->debugConstraintEvidence($plain, $constraints);
        $carbonEvidence = $service->debugConstraintEvidence($carbon, $constraints);

        $this->assertContains('aql 1 5', $plainEvidence['needles'], 'warunek z liczbą jest igłą jako cała fraza');
        $this->assertSame(['aql 1 5'], $plainEvidence['matched'], '„oparami” nie potwierdza „pary”');
        // „EN 149” daje token „149” i całą frazę „en 149” — obie liczą się tak samo dla każdej karty.
        $this->assertSame(['pary', 'organiczne', 'aql 1 5', '149', 'en 149'], $carbonEvidence['matched']);

        $rows = (new \ReflectionMethod($service, 'rowsFromLlmMatches'))->invoke(
            $service,
            'Półmaska FFP1 z zaworem, z węglem aktywnym przeciw parom organicznym',
            collect([$plain, $carbon]),
            ['matches' => [
                ['id' => (int) $plain->id, 'score' => 95, 'reason' => 'FFP1 z zaworem', 'missing_key' => []],
                ['id' => (int) $carbon->id, 'score' => 95, 'reason' => 'FFP1 z węglem', 'missing_key' => []],
            ]],
            10,
            'półmaska FFP1',
            null,
            $constraints,
        );
        $hits = array_column($rows, 'ai_constraint_hits', 'sku');

        $this->assertSame(1, $hits['9312+'] ?? null);
        $this->assertSame(5, $hits['9914'] ?? null);
        $this->assertSame(5, $rows[0]['ai_constraint_total'] ?? null);
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
