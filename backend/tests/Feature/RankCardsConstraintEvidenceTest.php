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
     * Zapytanie #55 z 23.09.2026: „Rękawice drelichowe pięciopalcowe EN374, EN420”. Drelichowe RD, RDP, RN stały
     * w czołówce puli, ale bez norm na karcie — 24 miejsca zajęły rękawice chemiczne z EN 374 i pozycja wyszła
     * „brak w katalogu”. Czołówka puli ma miejsca zagwarantowane; kolejność dalej od dowodu warunków.
     */
    public function test_top_of_pool_reaches_ranking_even_without_constraint_evidence(): void
    {
        $base = [
            'manufacturer' => 'REIS',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ];
        $pool = collect();
        foreach (['RD', 'RDP', 'RN'] as $sku) {
            $pool->push(Product::query()->create($base + [
                'sku' => $sku,
                'name' => 'Rękawice ochronne drelichowe '.$sku.'.',
                'description' => 'Rękawice drelichowe pięciopalcowe, wzmocnienie wewnętrznej strony dłoni.',
            ]));
        }
        for ($i = 1; $i <= 30; $i++) {
            $pool->push(Product::query()->create($base + [
                'sku' => 'CHEM-'.$i,
                'name' => 'Rękawice chemoodporne lateksowe '.$i,
                'description' => 'Rękawice chroniące przed chemikaliami.',
                'norms' => 'EN 374-1, EN 420',
            ]));
        }

        $service = app(ProductAiSearchService::class);
        $cards = (new \ReflectionMethod($service, 'cardsForRanking'))->invoke(
            $service,
            'Rękawice drelichowe pięciopalcowe EN374,EN420(2)(brak rozmiaru)',
            $pool,
            ['EN 374', 'EN 420'],
        )->pluck('sku')->all();

        $this->assertCount(24, $cards);
        foreach (['RD', 'RDP', 'RN'] as $sku) {
            $this->assertContains($sku, $cards, $sku.' z czołówki puli nie dotarła do modelu');
        }
        // karty z dowodem warunków dalej idą pierwsze
        $this->assertSame('CHEM-1', $cards[0]);
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
     * „odporność na zginanie do -50°C” i kod obciął ocenę do 50. Zdanie stało w opisie na znaku 950, a model widział
     * wtedy 360 znaków opisu i 4 cechy. Od 21.09.2026 model dostaje pełną kartę (opis do 3000 znaków), więc fragmenty
     * dowodu zostają dla tego, co stoi jeszcze dalej — nadal bez powtarzania tego, co model już widzi.
     */
    public function test_rank_card_carries_verbatim_evidence_for_conditions_hidden_beyond_visible_text(): void
    {
        $intro = str_repeat('Spodniobuty PROS MAX S5 FLUO to profesjonalna odzież wodoochronna z wbudowanymi kaloszami. ', 40);
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

        $this->assertStringNotContainsString('-50', mb_substr((string) $card->description, 0, 3000), 'zdanie stoi za granicą opisu wysyłanego modelowi');
        $evidence = $rankCard->invoke($service, $card, false, $constraints)['constraint_evidence'] ?? null;

        $this->assertIsArray($evidence);
        $this->assertCount(1, $evidence, 'tylko to, czego model nie widzi: wkładka jest w cechach, normy w polu norms');
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

    /**
     * Pełna karta: zdanie, które dawniej ginęło za 360. znakiem opisu, model czyta teraz wprost w opisie — razem ze
     * wszystkimi cechami i całą specyfikacją. Średnio widział 34% opisu właściwej karty, a miał na nim potwierdzić
     * 8–15 warunków; „brak dowodu” i sufit 50 brały się z przycięcia, nie z wyrobu.
     */
    public function test_rank_card_sends_the_whole_description_features_and_specs(): void
    {
        $intro = str_repeat('Spodniobuty PROS MAX S5 FLUO to profesjonalna odzież wodoochronna z wbudowanymi kaloszami. ', 6);
        $features = array_map(static fn (int $i): string => 'Cecha wyrobu numer '.$i, range(1, 9));
        $specs = array_map(static fn (int $i): string => 'Parametr '.$i.': wartość '.$i, range(1, 12));
        $card = Product::query()->create([
            'sku' => 'SBM01 FLUO',
            'name' => 'Spodniobuty Max ze wzmocnieniem kalosz typ S5 w kolorach fluo',
            'manufacturer' => 'AJ GROUP',
            'description' => $intro.'Materiał charakteryzuje się odpornością na zginanie bez pękania w temperaturze do -50°C.',
            'enrichment_payload' => ['features' => $features, 'specs' => $specs],
            'catalog_price_net' => 250,
            'purchase_price' => 202.3,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $service = app(ProductAiSearchService::class);
        $rankCard = new \ReflectionMethod($service, 'rankCard');

        $long = $rankCard->invoke($service, $card, false, ['odporność na zginanie do -50°C']);

        $this->assertGreaterThan(360, mb_strlen((string) $card->description));
        $this->assertSame((string) $card->description, $long['description'], 'cały opis, nie pierwsze 360 znaków');
        $this->assertSame($features, $long['features'], 'wszystkie cechy, nie pierwsze 4');
        $this->assertSame($specs, $long['specs'], 'cała specyfikacja, nie pierwsze 8 wierszy');
        $this->assertSame([], $long['constraint_evidence'], 'dowód stoi w opisie — nie powtarzamy go wycinkiem');

        $short = $rankCard->invoke($service, $card, true);
        $this->assertArrayNotHasKey('description', $short, 'tryb krótkich kart to świadomy wybór w Ustawieniach AI — bez zmian');
        $this->assertCount(2, $short['specs']);
    }

    /**
     * Golden nauszniki-snr-30 (pomiar 25.09.2026 na produkcji): igła „30” z warunku „SNR min 30 dB” trafiała w numery
     * kart (2630.030, AEB030-…), które szły do modelu pierwsze; wzorcowe bez „30” w tekście zostawały poza 24.
     */
    public function test_snr_threshold_number_is_not_evidence_when_choosing_cards(): void
    {
        $base = [
            'manufacturer' => 'JSP',
            'category' => 'Ochrona słuchu',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ];
        $pool = collect();
        for ($i = 1; $i <= 8; $i++) {
            $pool->push(Product::query()->create($base + ['sku' => 'TOP-'.$i, 'name' => 'Nauszniki nagłowne SNR 32 dB model '.$i]));
        }
        foreach (['X34-A', 'X34-B'] as $sku) {
            $pool->push(Product::query()->create($base + ['sku' => $sku, 'name' => 'Nauszniki nagłowne SNR 34 dB '.$sku]));
        }
        for ($i = 1; $i <= 16; $i++) {
            $pool->push(Product::query()->create($base + ['sku' => '2630.030-'.$i, 'name' => 'Nauszniki nagłowne SNR 32 dB wariant '.$i]));
        }

        $service = app(ProductAiSearchService::class);
        $cards = (new \ReflectionMethod($service, 'cardsForRanking'))->invoke(
            $service,
            'Ochronniki słuchu nagłowne o tłumieniu SNR minimum 30 dB',
            $pool,
            ['SNR min 30 dB'],
        )->pluck('sku')->all();

        $this->assertCount(24, $cards);
        $this->assertContains('X34-A', $cards);
        $this->assertContains('X34-B', $cards);
        $this->assertSame('TOP-1', $cards[0], 'bez igieł kolejność puli');
    }

    /**
     * Decyzja właściciela z 25.09.2026 (D12): karta z literą poziomu cięcia ISO 13997 nie niższą od wymaganej idzie do
     * oceny przed kartami bez poziomu. Golden opisowy15-02: wzorcowe z „4X43D” stały na 16.–57. miejscu puli i część
     * nie docierała do modelu, a miejsca zajmowały karty z tą samą liczbą słów warunku, bez litery ISO.
     */
    public function test_cards_with_proven_cut_level_reach_ranking_first(): void
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
        for ($i = 1; $i <= 28; $i++) {
            $pool->push(Product::query()->create($base + [
                'sku' => 'NOLVL-'.$i,
                'name' => 'Rękawice antyprzecięciowe powlekane '.$i,
                'norms' => 'EN 388:2016 4X42X',
            ]));
        }
        $pool->push(Product::query()->create($base + ['sku' => 'LVL-A', 'name' => 'Rękawice antyprzecięciowe HPPE', 'norms' => 'EN 388:2016 4X43A']));
        $pool->push(Product::query()->create($base + ['sku' => 'LVL-D', 'name' => 'Rękawice antyprzecięciowe HPPE', 'norms' => 'EN 388:2016 4X43D']));

        $service = app(ProductAiSearchService::class);
        $cards = (new \ReflectionMethod($service, 'cardsForRanking'))->invoke(
            $service,
            'Rękawice ochronne antyprzecięciowe powlekane EN 388, odporność na przecięcie poziom B',
            $pool,
            ['EN 388', 'odporność na przecięcie poziom B'],
        )->pluck('sku')->all();

        $this->assertCount(24, $cards);
        $this->assertSame('LVL-D', $cards[0], 'poziom D ≥ B — dowód, idzie pierwsza');
        $this->assertNotContains('LVL-A', array_slice($cards, 0, 1), 'poziom A < B nie jest dowodem');
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
