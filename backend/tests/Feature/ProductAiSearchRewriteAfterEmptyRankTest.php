<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Po ocenie „nic nie pasuje” wyszukiwanie ma przepisać zapytanie, a nie pytać model drugi raz o tę samą pulę.
 * Do 24.09.2026 intentChanged() iterował po wartościach zbioru fraz (same `true`) i zawsze mówił „zmieniona”:
 * przepisanie praktycznie się nie wykonywało, a każda pusta ocena kończyła się drugą oceną. Nieudane przepisanie
 * (wyjątek, pusta odpowiedź) nie może z kolei prowadzić do szukania z intencji zbudowanej z awarii.
 * Oba warianty — fala (findMany) i pojedyncze wyszukiwanie (find).
 */
final class ProductAiSearchRewriteAfterEmptyRankTest extends TestCase
{
    use RefreshDatabase;

    private const GLOVES = 'Rękawice powlekane nitrylem EN 388 do prac montażowych';

    private const GLOVES_NEEDED = 'rękawice powlekane nitrylem';

    /** Żadna karta katalogu nie pasuje do pierwszej intencji — ranking pominięty (skipped). */
    private const NO_CARDS = 'zzqwidget do maszyn EN 12345';

    /** Jak NO_CARDS, ale z marką, której katalog nie ma. */
    private const NO_CARDS_ABSENT_BRAND = 'zzqwidget Zzqbrand do maszyn EN 12345';

    /** Marka z wymagania, której katalog nie ma. */
    private const ABSENT_BRAND = 'Rękawice Zzqbrand powlekane nitrylem EN 388 do prac montażowych';

    /** @var array{rank: int, rewrite: int} */
    private array $calls = ['rank' => 0, 'rewrite' => 0];

    private int $gloveId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // Karta bez opisu: wchodzi do puli i do oceny, ale nie do listy zapasowej — po „nic nie pasuje”
        // pozycja jest pusta i wyszukiwanie sięga po przepisanie.
        $this->gloveId = (int) Product::query()->create([
            'sku' => 'RKW-NITRYL-1',
            'name' => 'Rękawice powlekane nitrylem',
            'manufacturer' => 'TEST',
            'description' => null,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ])->id;
    }

    /** @return iterable<string, array{0: bool}> */
    public static function paths(): iterable
    {
        yield 'fala' => [true];
        yield 'pojedyncze' => [false];
    }

    #[DataProvider('paths')]
    public function test_empty_rank_with_unchanged_intent_asks_for_rewrite_instead_of_second_rank(bool $batch): void
    {
        $this->llm(rewrite: fn (): array => $this->glovesIntent());

        $result = $this->searchGloves($batch);

        $this->assertSame(1, $this->calls['rewrite'], 'po „nic nie pasuje” wyszukiwanie nie przepisało zapytania');
        $this->assertSame(1, $this->calls['rank'], 'przepisanie nic nie zmieniło, a model dostał drugi raz tę samą pulę');
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_EMPTY, $result['model_state'] ?? null);
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_EMPTY, $result['ai_note'] ?? null);
    }

    #[DataProvider('paths')]
    public function test_rewrite_with_new_intent_searches_and_ranks_again(bool $batch): void
    {
        $this->llm(rewrite: fn (): array => [
            ...$this->glovesIntent(),
            'needed' => 'rękawice montażowe nitrylowe',
            'search_phrases' => ['rękawice montażowe', 'rękawice nitrylowe montażowe'],
        ]);

        $result = $this->searchGloves($batch);

        $this->assertSame(1, $this->calls['rewrite']);
        $this->assertSame(2, $this->calls['rank'], 'nowa intencja z przepisania → nowa pula → druga ocena');
        $this->assertSame(['RKW-NITRYL-1'], array_column($result['products'] ?? [], 'sku'));
    }

    #[DataProvider('paths')]
    public function test_failed_rewrite_does_not_search_and_rank_again(bool $batch): void
    {
        // Klient w fali oddaje pustą tablicę, pojedyncze wywołanie rzuca — oba to brak odpowiedzi.
        $this->llm(rewrite: static function () use ($batch): array {
            if ($batch) {
                return [];
            }

            throw new RuntimeException('Limit zapytań modelu AI (HTTP 429).');
        });

        $result = $this->searchGloves($batch);

        $this->assertSame(1, $this->calls['rewrite']);
        $this->assertSame(1, $this->calls['rank'], 'nieudane przepisanie → szukanie z intencji zbudowanej z awarii i druga ocena');
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_EMPTY, $result['model_state'] ?? null, 'pierwsza ocena odpowiedziała — to nie awaria oceny');
        $this->assertSame(self::GLOVES_NEEDED, $result['needed'] ?? null, 'awaria przepisania podmieniła intencję na cały tekst wymagania');
    }

    public function test_single_rewrite_answering_with_empty_array_is_a_failure(): void
    {
        // Kontrakt jak w fali: pusta tablica z chatJson to brak odpowiedzi, a nie przepisanie na cały tekst wymagania.
        $this->llm(rewrite: static fn (): array => []);

        $result = $this->searchGloves(false);

        $this->assertSame(1, $this->calls['rank']);
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(self::GLOVES_NEEDED, $result['needed'] ?? null);
    }

    #[DataProvider('paths')]
    public function test_rewrite_repeating_the_searched_intent_does_not_rank_the_same_pool_again(bool $batch): void
    {
        // Ranking oddaje podzbiór fraz zrozumienia, a przepisanie powtarza zrozumienie. Fala porównywała przepisanie
        // z intencją rankingu (węższą), więc brała je za zmianę i oceniała tę samą pulę drugi raz.
        $this->llm(
            rewrite: fn (): array => $this->glovesIntent(),
            firstRank: [...$this->glovesIntent(), 'search_phrases' => ['rękawice powlekane nitrylem'], 'matches' => []],
        );

        $result = $this->searchGloves($batch);

        $this->assertSame(1, $this->calls['rewrite']);
        $this->assertSame(1, $this->calls['rank'], 'przepisanie powtórzyło intencję, z którą szukano — ta sama pula poszła drugi raz do oceny');
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
    }

    /** @return iterable<string, array{0: string, 1: bool, 2: string}> */
    public static function firstPassNotes(): iterable
    {
        yield 'brak kart, przepisanie bez zmian' => [self::NO_CARDS, false, 'Brak kart z opisem w katalogu do porównania.'];
        yield 'brak kart, przepisanie padło' => [self::NO_CARDS, true, 'Brak kart z opisem w katalogu do porównania.'];
        yield 'marka spoza katalogu, przepisanie bez zmian' => [self::ABSENT_BRAND, false, 'Marki Zzqbrand nie ma w katalogu'];
        yield 'marka spoza katalogu, przepisanie padło' => [self::ABSENT_BRAND, true, 'Marki Zzqbrand nie ma w katalogu'];
    }

    /**
     * Przepisanie, które padło albo nic nie zmieniło, zostawia wynik pierwszego przebiegu — z jego komunikatem.
     * Pojedyncze wyszukiwanie składało wtedy ogólne „model nie znalazł” (także gdy model żadnej karty nie widział),
     * a fala zostawiała właściwy powód; obie ścieżki mają mówić to samo.
     */
    #[DataProvider('firstPassNotes')]
    public function test_failed_or_unchanged_rewrite_keeps_first_pass_note_in_both_paths(string $query, bool $rewriteFails, string $note): void
    {
        $understood = $query === self::ABSENT_BRAND
            ? [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand']
            : ['needed' => 'zzqwidget', 'search_steps' => ['zzqwidget'], 'manufacturer' => null, 'model_name' => null,
                'size_note' => null, 'search_phrases' => ['zzqwidget'], 'constraints' => []];

        // Awaria przepisania: w fali klient oddaje pustą tablicę, w pojedynczym wywołaniu rzuca.
        $this->llm(rewrite: static fn (): array => $rewriteFails ? [] : $understood, understand: $understood);
        $wave = $this->app->make(AiProductSearch::class)->findMany([$query], 10, AiTask::ProductSearch, 2)[0];
        $waveCalls = $this->calls;

        $this->calls = ['rank' => 0, 'rewrite' => 0];
        $this->llm(
            rewrite: static fn (): array => $rewriteFails ? throw new RuntimeException('Limit zapytań modelu AI (HTTP 429).') : $understood,
            understand: $understood,
        );
        $single = $this->app->make(AiProductSearch::class)->find($query, 10, AiTask::ProductSearch);

        $this->assertStringContainsString($note, (string) ($single['ai_note'] ?? ''), 'pojedyncze wyszukiwanie zgubiło komunikat pierwszego przebiegu');
        $this->assertSame($wave['ai_note'] ?? null, $single['ai_note'] ?? null, 'fala i pojedyncze wyszukiwanie mówią co innego');
        $this->assertSame($wave['model_state'] ?? null, $single['model_state'] ?? null);
        $this->assertSame($waveCalls, $this->calls, 'fala i pojedyncze wyszukiwanie pytają model inaczej');
        if ($query === self::NO_CARDS) {
            $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $single['model_state'] ?? null, 'model nie widział żadnej karty');
        }
    }

    public function test_batch_does_not_rewrite_position_without_cards_when_brand_is_not_in_catalog(): void
    {
        // Jak pojedyncze wyszukiwanie: bez kart do oceny i przy marce spoza katalogu nie ma czego przepisywać.
        $understood = ['needed' => 'zzqwidget', 'search_steps' => ['zzqwidget'], 'manufacturer' => 'Zzqbrand', 'model_name' => null,
            'size_note' => null, 'search_phrases' => ['zzqwidget'], 'constraints' => []];
        $this->llm(rewrite: static fn (): array => $understood, understand: $understood);

        $wave = $this->app->make(AiProductSearch::class)->findMany([self::NO_CARDS_ABSENT_BRAND], 10, AiTask::ProductSearch, 2)[0];

        $this->assertSame(0, $this->calls['rewrite'], 'fala przepisała pozycję bez kart przy marce spoza katalogu');
        $this->assertSame(0, $this->calls['rank']);
        $this->assertStringContainsString('Marki Zzqbrand nie ma w katalogu', (string) ($wave['ai_note'] ?? ''));
    }

    #[DataProvider('paths')]
    public function test_position_without_cards_rewritten_and_ranked_reports_model_state(bool $batch): void
    {
        // Pierwsza intencja nie trafia w żadną kartę (skipped), przepisanie trafia i model ocenia pulę. W fali taka
        // pozycja wracała bez model_state — pomiar i dopasowanie widziały „nieznany” zamiast „ranked”.
        $understood = ['needed' => 'zzqwidget', 'search_steps' => ['zzqwidget'], 'manufacturer' => null, 'model_name' => null,
            'size_note' => null, 'search_phrases' => ['zzqwidget'], 'constraints' => []];
        $this->llm(
            rewrite: fn (): array => $this->glovesIntent(),
            understand: $understood,
            firstRank: ['matches' => [['id' => $this->gloveId, 'score' => 90, 'reason' => 'nitryl']]],
        );

        $result = $batch
            ? $this->app->make(AiProductSearch::class)->findMany([self::NO_CARDS], 10, AiTask::ProductSearch, 2)[0]
            : $this->app->make(AiProductSearch::class)->find(self::NO_CARDS, 10, AiTask::ProductSearch);

        $this->assertSame(1, $this->calls['rewrite']);
        $this->assertSame(1, $this->calls['rank'], 'fixture: przepisanie trafiło w kartę i model ją ocenił');
        $this->assertSame(['RKW-NITRYL-1'], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $result['model_state'] ?? null);
    }

    public function test_rewrite_that_still_finds_no_cards_reports_skipped_in_both_paths(): void
    {
        // Przepisanie zmienia intencję, ale nowa też nie trafia w żadną kartę. Fala zastępowała wynik pozycji
        // wynikiem bez model_state — pojedyncze wyszukiwanie mówiło „skipped”, fala nic.
        $understood = ['needed' => 'zzqwidget', 'search_steps' => ['zzqwidget'], 'manufacturer' => null, 'model_name' => null,
            'size_note' => null, 'search_phrases' => ['zzqwidget'], 'constraints' => []];
        $rewritten = [...$understood, 'needed' => 'qqzgadget', 'search_steps' => ['qqzgadget'], 'search_phrases' => ['qqzgadget']];
        $this->llm(rewrite: static fn (): array => $rewritten, understand: $understood);

        $wave = $this->app->make(AiProductSearch::class)->findMany([self::NO_CARDS], 10, AiTask::ProductSearch, 2)[0];
        $single = $this->app->make(AiProductSearch::class)->find(self::NO_CARDS, 10, AiTask::ProductSearch);

        $this->assertSame(2, $this->calls['rewrite'], 'fixture: obie ścieżki przepisały zapytanie');
        $this->assertSame(0, $this->calls['rank']);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $wave['model_state'] ?? null, 'fala zgubiła stan po ponownym szukaniu bez kart');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $single['model_state'] ?? null);
        $this->assertSame('Brak kart z opisem w katalogu do porównania.', $wave['ai_note'] ?? null);
    }

    public function test_single_failed_rewrite_is_logged_without_marking_understanding_as_failed(): void
    {
        Log::spy();
        $this->llm(rewrite: static fn (): array => throw new RuntimeException('Limit zapytań modelu AI (HTTP 429).'));

        $result = $this->searchGloves(false);

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context = []): bool => $message === 'product-ai-search.rewrite-failed'
                && str_contains((string) ($context['message'] ?? ''), 'HTTP 429')
        );
        $this->assertNull($result['trace']['intent_error'] ?? null, 'zrozumienie pochodzi z modelu — ślad nie może mówić, że „zrozum” padło');
    }

    /** @return iterable<string, array{0: bool}> */
    public static function researchSources(): iterable
    {
        yield 'intencja rankingu' => [false];
        yield 'przepisanie' => [true];
    }

    /**
     * Pierwsza ocena widziała kartę i nic nie wskazała, a ponowne szukanie (z intencji rankingu albo z przepisania)
     * nie znalazło żadnej. Fala ustawiała „skipped” w wyniku, ale końcowa pętla nadpisywała go stanem pierwszej oceny
     * i dokładała jej dostawcę — pojedyncze wyszukiwanie mówiło „skipped”.
     */
    #[DataProvider('researchSources')]
    public function test_research_without_cards_after_ranked_pass_is_skipped_in_both_paths(bool $viaRewrite): void
    {
        $nowhere = ['needed' => 'zzqwidget', 'search_steps' => ['zzqwidget'], 'manufacturer' => null, 'model_name' => null,
            'size_note' => null, 'search_phrases' => ['zzqwidget'], 'constraints' => []];
        $args = $viaRewrite
            ? ['rewrite' => static fn (): array => $nowhere, 'firstRank' => [...$this->glovesIntent(), 'matches' => []]]
            : ['rewrite' => fn (): array => $this->glovesIntent(), 'firstRank' => [...$nowhere, 'matches' => []]];

        $this->llm(...$args);
        $wave = $this->app->make(AiProductSearch::class)->findMany([self::NO_CARDS], 10, AiTask::ProductSearch, 2)[0];
        $waveCalls = $this->calls;
        $this->calls = ['rank' => 0, 'rewrite' => 0];
        $this->llm(...$args);
        $single = $this->app->make(AiProductSearch::class)->find(self::NO_CARDS, 10, AiTask::ProductSearch);

        $this->assertSame(1, $waveCalls['rank'], 'fixture: pierwsza ocena widziała kartę rękawic');
        $this->assertSame($waveCalls, $this->calls);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $wave['model_state'] ?? null, 'fala nadpisała „skipped” stanem pierwszej oceny');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $single['model_state'] ?? null);
        $this->assertSame('dostawca-testowy', $wave['model_providers']['understand'] ?? null, 'fixture: dostawca zrozumienia zostaje');
        $this->assertArrayHasKey('rank', $wave['model_providers'] ?? []);
        $this->assertNull($wave['model_providers']['rank'], 'dostawca pierwszej oceny przypisany wynikowi, którego model nie oceniał');
        $this->assertSame('Brak kart z opisem w katalogu do porównania.', $wave['ai_note'] ?? null);
    }

    public function test_skipped_state_is_not_promoted_to_ranked_by_rows_the_model_never_saw(): void
    {
        // Wiersze nazwanego modelu nie mają ai_match_source — reguła „wiersz bez źródła = ocena modelu” robiła
        // z nich „ranked”, choć przy „skipped” model tych kart nie widział.
        $rows = [['id' => $this->gloveId, 'sku' => 'RKW-NITRYL-1']];
        $state = (new \ReflectionMethod(ProductAiSearchService::class, 'modelStateForRows'))
            ->invoke($this->app->make(ProductAiSearchService::class), $rows, ProductAiSearchService::MODEL_STATE_SKIPPED);

        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $state);
    }

    #[DataProvider('paths')]
    public function test_state_after_second_rank_comes_from_second_rank(bool $batch): void
    {
        // Pierwsza ocena wskazała kartę poniżej progu (ranked, ale bez wierszy), druga po przepisaniu — nic.
        // Fala zostawiała stan pierwszej oceny („ranked” przy pustej liście), pojedyncze brało drugą („empty”).
        $this->llm(
            rewrite: fn (): array => [...$this->glovesIntent(), 'needed' => 'rękawice montażowe nitrylowe', 'search_phrases' => ['rękawice montażowe']],
            firstRank: [...$this->glovesIntent(), 'matches' => [['id' => $this->gloveId, 'score' => 10, 'reason' => 'inny rodzaj']]],
            secondRank: [...$this->glovesIntent(), 'matches' => []],
        );

        $result = $this->searchGloves($batch);

        $this->assertSame(2, $this->calls['rank'], 'fixture: przepisanie zmieniło intencję i pula poszła do drugiej oceny');
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_EMPTY, $result['model_state'] ?? null);
    }

    /** @return iterable<string, array{0: bool, 1: bool}> */
    public static function slangSides(): iterable
    {
        yield 'żargon w odpowiedzi rankingu' => [true, true];
        yield 'żargon tylko w przepisaniu' => [true, false];
        yield 'żargon w odpowiedzi rankingu, pojedyncze' => [false, true];
        yield 'żargon tylko w przepisaniu, pojedyncze' => [false, false];
    }

    /**
     * Normalizacja żargonu (applySlangIntent) wycina z fraz słabe słowa z zapytania („nitrylki”). Intencja, z którą
     * szukano, jest po normalizacji, a odpowiedź modelu — nie: ta sama intencja wyglądała na zmienioną i ta sama pula
     * szła drugi raz do oceny.
     */
    #[DataProvider('slangSides')]
    public function test_slang_normalization_alone_is_not_a_changed_intent(bool $batch, bool $slangInRank): void
    {
        $query = 'Rękawice nitrylki lekkie';
        $base = ['needed' => 'rękawice nitrylowe', 'search_steps' => ['rękawice', 'nitrylowe'], 'manufacturer' => null,
            'model_name' => null, 'size_note' => null, 'constraints' => []];
        $withSlang = [...$base, 'search_phrases' => ['rękawice nitrylowe', 'nitrylki']];
        $plain = [...$base, 'search_phrases' => ['rękawice nitrylowe']];
        $this->llm(
            rewrite: static fn (): array => $slangInRank ? $plain : $withSlang,
            understand: $withSlang,
            firstRank: [...($slangInRank ? $withSlang : $plain), 'matches' => []],
        );

        $result = $batch
            ? $this->app->make(AiProductSearch::class)->findMany([$query], 10, AiTask::ProductSearch, 2)[0]
            : $this->app->make(AiProductSearch::class)->find($query, 10, AiTask::ProductSearch);

        $this->assertGreaterThanOrEqual(1, $this->calls['rank'], 'fixture: karta rękawic weszła do puli i do oceny');
        $this->assertSame(1, $this->calls['rank'], 'ta sama intencja po normalizacji żargonu — ta sama pula poszła drugi raz do oceny');
        $this->assertSame([], array_column($result['products'] ?? [], 'sku'));
    }

    /** @return iterable<string, array{0: bool, 1: list<string>, 2: int}> */
    public static function rewrittenSteps(): iterable
    {
        yield 'nowe kroki, fala' => [true, ['rękawice', 'nitrylowe', 'montażowe'], 2];
        yield 'nowe kroki, pojedyncze' => [false, ['rękawice', 'nitrylowe', 'montażowe'], 2];
        yield 'te same kroki inną pisownią, fala' => [true, ['Rękawice', 'Powlekane nitrylem'], 1];
        yield 'te same kroki inną pisownią, pojedyncze' => [false, ['Rękawice', 'Powlekane nitrylem'], 1];
    }

    /**
     * Prompt przepisania prosi wprost o nowe kroki wyszukiwania, a kaskada katalogu z nich korzysta. Przepisanie,
     * które zmienia tylko kroki (te same frazy i nazwa), było brane za „bez zmian” i nowe kroki przepadały.
     * Ta sama lista w innej pisowni nie jest zmianą — inaczej ta sama pula szłaby drugi raz do oceny.
     *
     * @param  list<string>  $steps
     */
    #[DataProvider('rewrittenSteps')]
    public function test_rewrite_changing_only_search_steps_searches_again(bool $batch, array $steps, int $expectedRanks): void
    {
        $this->llm(rewrite: fn (): array => [...$this->glovesIntent(), 'search_steps' => $steps]);

        $result = $this->searchGloves($batch);

        $this->assertSame(1, $this->calls['rewrite']);
        $this->assertSame($expectedRanks, $this->calls['rank']);
        $this->assertSame($expectedRanks === 2 ? ['RKW-NITRYL-1'] : [], array_column($result['products'] ?? [], 'sku'));
    }

    /**
     * C0: ranking powtarza markę spoza katalogu we frazach, ale bez pola producenta. Pojedyncze scalało odpowiedź
     * rankingu z intencją szukania (marka zdjęta z fraz → bez zmian → przepisanie), fala porównywała niescaloną
     * (fraza marki „nowa” → druga ocena tej samej puli).
     */
    public function test_rank_echoing_absent_brand_is_not_a_change_in_both_paths(): void
    {
        $understood = [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand'];
        [$wave, $waveCalls, $single, $singleCalls] = $this->bothPaths(self::ABSENT_BRAND, [
            'rewrite' => static fn (): array => $understood,
            'understand' => $understood,
            'firstRank' => [...$this->glovesIntent(), 'search_phrases' => ['rękawice powlekane nitrylem', 'rękawice Zzqbrand', 'rękawice nitrylowe'], 'matches' => []],
        ]);

        $this->assertSame(['rank' => 1, 'rewrite' => 1], $waveCalls, 'fala oceniła drugi raz tę samą pulę');
        $this->assertSame($waveCalls, $singleCalls);
        $this->assertSame($wave['ai_note'] ?? null, $single['ai_note'] ?? null);
        $this->assertSame($wave['model_state'] ?? null, $single['model_state'] ?? null);
    }

    /**
     * C1: zrozumienie błędnie uznało markę za spoza katalogu, przepisanie podało markę z katalogu (TEST, jest w treści
     * wymagania). Fala scalała drugą ocenę ze zrozumieniem z pierwszego przebiegu: wymuszała „marki nie ma”, wyłączała
     * bramkę marki i opisywała kartę żądanej marki jako zamiennik. Pojedyncze scala z przepisaniem.
     */
    public function test_rewrite_naming_catalog_brand_wins_over_wrong_absent_brand_in_both_paths(): void
    {
        $query = 'Rękawice powlekane nitrylem TEST zgodne z EN 388 do prac montażowych i ogólnych w magazynie oraz na budowie';
        $understood = [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand'];
        [$wave, $waveCalls, $single, $singleCalls] = $this->bothPaths($query, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'manufacturer' => 'TEST'],
            'understand' => $understood,
            'firstRank' => [...$understood, 'matches' => []],
        ]);

        $this->assertSame(['rank' => 2, 'rewrite' => 1], $waveCalls, 'fixture: przepisanie zmieniło markę → nowe szukanie i druga ocena');
        $this->assertSame($waveCalls, $singleCalls);
        foreach (['fala' => $wave, 'pojedyncze' => $single] as $path => $result) {
            $this->assertArrayNotHasKey('manufacturer_absent_in_catalog', $result['parsed_intent'] ?? [], $path.': przepisanie podało markę z katalogu, a wynik mówi „marki nie ma”');
            $this->assertSame('TEST', $result['parsed_intent']['manufacturer'] ?? null, $path);
        }
        $this->assertSame($wave['ai_note'] ?? null, $single['ai_note'] ?? null);
        $this->assertSame(array_column($wave['products'] ?? [], 'sku'), array_column($single['products'] ?? [], 'sku'));
    }

    /** @return iterable<string, array{0: bool}> */
    public static function brandOmittingRewrites(): iterable
    {
        yield 'przepisanie pomija markę, reszta bez zmian' => [false];
        yield 'przepisanie pomija markę i zmienia frazy' => [true];
    }

    /**
     * C2: przepisanie pomija markę spoza katalogu. Samo pominięcie nie jest zmianą (retrieval i tak zdejmował markę),
     * a przy zmianie fraz komunikat „Marki … nie ma w katalogu” i flaga marki zostają — w obu ścieżkach.
     */
    #[DataProvider('brandOmittingRewrites')]
    public function test_rewrite_omitting_absent_brand_keeps_brand_note_in_both_paths(bool $changesPhrases): void
    {
        $understood = [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand'];
        $rewrite = $changesPhrases
            ? [...$this->glovesIntent(), 'needed' => 'rękawice montażowe nitrylowe', 'search_phrases' => ['rękawice montażowe']]
            : $this->glovesIntent();
        [$wave, $waveCalls, $single, $singleCalls] = $this->bothPaths(self::ABSENT_BRAND, [
            'rewrite' => static fn (): array => $rewrite,
            'understand' => $understood,
            'firstRank' => [...$understood, 'matches' => []],
            'secondRank' => [...$this->glovesIntent(), 'matches' => []],
        ]);

        $this->assertSame(['rank' => $changesPhrases ? 2 : 1, 'rewrite' => 1], $waveCalls, 'pominięcie marki wzięte za zmianę albo zmiana fraz przeoczona');
        $this->assertSame($waveCalls, $singleCalls);
        foreach (['fala' => $wave, 'pojedyncze' => $single] as $path => $result) {
            $this->assertStringContainsString('Marki Zzqbrand nie ma w katalogu', (string) ($result['ai_note'] ?? ''), $path.': zgubiony komunikat o marce');
            $this->assertTrue($result['parsed_intent']['manufacturer_absent_in_catalog'] ?? false, $path);
        }
    }

    public function test_rewrite_inventing_brand_outside_query_inherits_searched_brand_in_both_paths(): void
    {
        // Przepisanie podaje markę, której nie ma w treści wymagania — model ją zmyślił. Zostaje marka szukanej intencji.
        $understood = [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand'];
        [$wave, $waveCalls, $single, $singleCalls] = $this->bothPaths(self::ABSENT_BRAND, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'manufacturer' => 'UVEX'],
            'understand' => $understood,
            'firstRank' => [...$understood, 'matches' => []],
        ]);

        $this->assertSame(['rank' => 1, 'rewrite' => 1], $waveCalls, 'zmyślona marka wzięta za zmianę szukania');
        $this->assertSame($waveCalls, $singleCalls);
        $this->assertSame($wave['ai_note'] ?? null, $single['ai_note'] ?? null);
        $this->assertStringContainsString('Marki Zzqbrand nie ma w katalogu', (string) ($single['ai_note'] ?? ''));
    }

    public function test_rewrite_inventing_brand_and_changing_phrases_keeps_requested_brand_in_both_paths(): void
    {
        // Przepisanie zmyśla markę (spoza treści wymagania) i zmienia frazy: nowe szukanie jest, ale marka i komunikat
        // zostają z wymagania — zmyślona marka nie może trafić do „Marki … nie ma w katalogu”.
        $understood = [...$this->glovesIntent(), 'manufacturer' => 'Zzqbrand'];
        [$wave, $waveCalls, $single, $singleCalls] = $this->bothPaths(self::ABSENT_BRAND, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'manufacturer' => 'UVEX', 'needed' => 'rękawice montażowe nitrylowe', 'search_phrases' => ['rękawice montażowe']],
            'understand' => $understood,
            'firstRank' => [...$understood, 'matches' => []],
            'secondRank' => [...$this->glovesIntent(), 'matches' => []],
        ]);

        $this->assertSame(['rank' => 2, 'rewrite' => 1], $waveCalls, 'fixture: zmiana fraz → nowe szukanie');
        $this->assertSame($waveCalls, $singleCalls);
        foreach (['fala' => $wave, 'pojedyncze' => $single] as $path => $result) {
            $this->assertStringContainsString('Marki Zzqbrand nie ma w katalogu', (string) ($result['ai_note'] ?? ''), $path);
            $this->assertStringNotContainsString('UVEX', (string) ($result['ai_note'] ?? ''), $path.': zmyślona marka w komunikacie');
        }
    }

    public function test_steps_from_rewrite_reach_result_in_both_paths(): void
    {
        // Przepisanie zmienia same kroki: fala scalała drugą ocenę z pierwszym zrozumieniem i zwracała stare kroki
        // („powlekane nitrylem” z glovesIntent). Sanitizer kroków skraca przepisane kroki w katalogu testowym, więc
        // sprawdzamy zgodność ścieżek i brak kroku z pierwszego przebiegu.
        $steps = ['rękawice', 'nitrylowe', 'montażowe'];
        [$wave, , $single] = $this->bothPaths(self::GLOVES, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'search_steps' => $steps],
            'secondRank' => [...$this->glovesIntent(), 'search_steps' => [], 'matches' => []],
        ]);

        $this->assertSame($single['parsed_intent']['search_steps'] ?? null, $wave['parsed_intent']['search_steps'] ?? null);
        $this->assertNotContains('powlekane nitrylem', $wave['parsed_intent']['search_steps'] ?? [], 'fala zwróciła kroki z pierwszego przebiegu');
    }

    public function test_rewrite_without_steps_keeps_searched_steps_in_both_paths(): void
    {
        // Przepisanie bez kroków (reszta jak zrozumienie): kroki domyślne z zapytania różnią się od kroków szukania,
        // więc bez dziedziczenia (jak mergeRetrieveIntent) wyglądałyby na zmianę i ta sama pula szłaby drugi raz.
        [, $waveCalls, , $singleCalls] = $this->bothPaths(self::GLOVES, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'search_steps' => []],
        ]);

        $this->assertSame(['rank' => 1, 'rewrite' => 1], $waveCalls);
        $this->assertSame($waveCalls, $singleCalls);
    }

    public function test_ranked_state_after_second_rank_in_both_paths(): void
    {
        // C3: druga ocena wskazała kartę poniżej progu — model ocenił (ranked), choć lista jest pusta.
        [$wave, $waveCalls, $single] = $this->bothPaths(self::GLOVES, [
            'rewrite' => fn (): array => [...$this->glovesIntent(), 'needed' => 'rękawice montażowe nitrylowe', 'search_phrases' => ['rękawice montażowe']],
            'secondRank' => [...$this->glovesIntent(), 'matches' => [['id' => $this->gloveId, 'score' => 10, 'reason' => 'inny rodzaj']]],
        ]);

        $this->assertSame(2, $waveCalls['rank']);
        $this->assertSame([], array_column($wave['products'] ?? [], 'sku'));
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $wave['model_state'] ?? null);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $single['model_state'] ?? null);
    }

    /**
     * Fala, a potem pojedyncze wyszukiwanie tego samego wymagania na tych samych odpowiedziach modelu.
     *
     * @param  array<string, mixed>  $llmArgs  argumenty llm()
     * @return array{0: array<string, mixed>, 1: array{rank: int, rewrite: int}, 2: array<string, mixed>, 3: array{rank: int, rewrite: int}}
     */
    private function bothPaths(string $query, array $llmArgs): array
    {
        $this->calls = ['rank' => 0, 'rewrite' => 0];
        $this->llm(...$llmArgs);
        $wave = $this->app->make(AiProductSearch::class)->findMany([$query], 10, AiTask::ProductSearch, 2)[0];
        $waveCalls = $this->calls;

        $this->calls = ['rank' => 0, 'rewrite' => 0];
        $this->llm(...$llmArgs);
        $single = $this->app->make(AiProductSearch::class)->find($query, 10, AiTask::ProductSearch);

        return [$wave, $waveCalls, $single, $this->calls];
    }

    /** @return array<string, mixed> */
    private function searchGloves(bool $batch): array
    {
        $search = $this->app->make(AiProductSearch::class);

        return $batch
            ? $search->findMany([self::GLOVES], 10, AiTask::ProductSearch, 2)[0]
            : $search->find(self::GLOVES, 10, AiTask::ProductSearch);
    }

    /** @return array<string, mixed> */
    private function glovesIntent(): array
    {
        return [
            'needed' => self::GLOVES_NEEDED,
            'search_steps' => ['rękawice', 'powlekane nitrylem'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['rękawice powlekane nitrylem', 'rękawice nitrylowe', 'rękawice robocze'],
            'constraints' => ['EN 388'],
        ];
    }

    /**
     * Zrozumienie = glovesIntent; pierwsza ocena: „nic nie pasuje” z tą samą intencją; każda kolejna wskazuje kartę.
     *
     * @param  callable(): array<string, mixed>  $rewrite  odpowiedź na przepisanie (może rzucić)
     * @param  array<string, mixed>|null  $understand  odpowiedź na „zrozum” (domyślnie glovesIntent)
     * @param  array<string, mixed>|null  $firstRank  pierwsza odpowiedź rankingu (domyślnie „nic nie pasuje” z glovesIntent)
     * @param  array<string, mixed>|null  $secondRank  kolejne odpowiedzi rankingu (domyślnie karta z oceną 90)
     */
    private function llm(callable $rewrite, ?array $understand = null, ?array $firstRank = null, ?array $secondRank = null): void
    {
        $answer = function (array $messages) use ($rewrite, $understand, $firstRank, $secondRank): array {
            $kind = FakeSearchLlm::kind($messages);
            if ($kind === FakeSearchLlm::KIND_REWRITE) {
                $this->calls['rewrite']++;

                return $rewrite();
            }
            if ($kind !== FakeSearchLlm::KIND_RANK) {
                return $understand ?? $this->glovesIntent();
            }

            return ++$this->calls['rank'] === 1
                ? ($firstRank ?? [...$this->glovesIntent(), 'matches' => []])
                : ($secondRank ?? ['matches' => [['id' => $this->gloveId, 'score' => 90, 'reason' => 'nitryl, montaż']]]);
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing($answer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $sets) use ($answer): array {
            $out = array_map($answer, $sets);
            // Jak prawdziwy klient: dostawca każdej odpowiedzi fali — bez tego asercje o dostawcy byłyby puste.
            app(AiServedProviderTally::class)->recordBatch(array_fill(0, count($sets), 'dostawca-testowy'));

            return $out;
        });
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
