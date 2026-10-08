<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SupplementB2bDescriptionJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\B2bSupplementNoPages;
use App\Services\Enrichment\B2bSupplementSearchOutage;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SupplementB2bDescriptionJob — zapis uzupełnionego opisu (compare-and-set), ślady prób i niezmiennik synchronizacji:
 * ten sam tekst u dostawcy zostawia uzupełniony opis, nowy tekst wraca na kartę. ProductEnrichmentService jest final,
 * więc atrapa Mockery podmienia go w kontenerze (job bierze usługę z kontenera). Dane SYNTETYCZNE.
 */
final class SupplementB2bDescriptionJobTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';

    private const AI_TEXT = 'Rękawica ochronna z wkładki nylonowej powlekanej nitrylem, kategoria II. Powłoka zapewnia pewny chwyt '
        .'na suchych i lekko zaolejonych powierzchniach, a ściągacz chroni przed zanieczyszczeniami. Przeznaczona do prac '
        .'montażowych i magazynowych. Dostępna w rozmiarach 7–11.';

    private const PAGE_URL = 'https://b2b.procera.pl/produkt/rekawica-123';

    private const WEB_URL = 'https://www.ansell.com/pl/pl/products/rekawica-123';

    private B2bAccount $account;

    /** @var MockInterface&ProductEnrichmentService */
    private MockInterface $enrichment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = B2bAccount::query()->create([
            'username' => 'konto-procera',
            'password' => 'haslo',
            'sites' => ['b2b.procera.pl'],
            'connector' => 'procera',
            'enrichment_sites' => ['ansell.com'],
        ]);
        $this->enrichment = Mockery::mock();
        $this->app->instance(ProductEnrichmentService::class, $this->enrichment);
    }

    public function test_replaces_short_description_with_trace_and_link_hashes(): void
    {
        $card = $this->card(self::SHORT, [
            'shop_source_url' => self::PAGE_URL,
            'norms' => 'EN 420',
            'enrichment_payload' => [
                'features' => ['stara cecha'],
                'attributes' => ['kategoria_bhp' => 'ochrona_oczu'],
            ],
        ]);
        $links = [$this->link($card, 'a', sha1(self::SHORT)), $this->link($card, 'b', sha1(self::SHORT))];
        $this->queued($card);
        $this->enrichment->shouldReceive('supplementB2bDescription')
            ->once()
            ->withArgs(fn (Product $product, B2bSupplementContext $context): bool => (int) $product->id === (int) $card->id
                && $context->b2bText === self::SHORT
                && $context->b2bUrl === self::PAGE_URL
                && $context->hosts === ['ansell.com'])
            ->andReturn($this->modelResult());

        $this->runJob($card);

        $card->refresh();
        $this->assertSame(self::AI_TEXT, $card->description);
        // stan wzbogacania i kolumna norm bez zmian
        $this->assertSame(Product::ENRICHMENT_NONE, $card->enrichment_status);
        $this->assertSame('EN 420', $card->norms);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(['powłoka nitrylowa'], $payload['features']);
        $this->assertSame([self::PAGE_URL, self::WEB_URL], $payload['source_urls']);
        $this->assertSame('b2b_supplement', $payload['primary_source_kind']);
        $this->assertSame(self::SHORT, $payload['replaced_description']);
        $this->assertSame(sha1(self::AI_TEXT), $payload['replaced_description_hash']);
        $trace = $payload['b2b_supplement'];
        $this->assertSame((int) $this->account->id, $trace['b2b_account_id']);
        $this->assertSame(self::SHORT, $trace['b2b_text']);
        $this->assertSame(sha1(self::SHORT), $trace['source_sha1']);
        $this->assertSame(['ansell.com'], $trace['hosts']);
        $this->assertSame([self::WEB_URL], $trace['web_source_urls']);
        $this->assertSame(['EN 388 4131X'], $trace['dropped']);
        $this->assertSame(['odporność na przecięcie'], $trace['dropped_claims']);
        $this->assertSame(sha1(self::AI_TEXT), $trace['result_sha1']);
        $this->assertNotEmpty($trace['described_at']);
        // atrybuty liczone od nowa z karty po zmianie, nie przepisane ze starego payloadu
        $this->assertIsArray($payload['attributes']);
        $this->assertNotSame('ochrona_oczu', $payload['attributes']['kategoria_bhp'] ?? null);

        foreach ($links as $link) {
            $link->refresh();
            $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
            $this->assertSame(sha1(self::SHORT), $link->source_description_hash);
        }
        // opis wciąż liczy się jako „z B2B” — zbiorcze AI kartę omija
        $this->assertArrayHasKey((int) $card->id, app(B2bDescriptionSource::class)->productIds([(int) $card->id]));

        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $attempt->status);
        $this->assertSame(1, $attempt->attempts);
        $this->assertSame(sha1(self::AI_TEXT), $attempt->result_sha1);
        $this->assertSame([self::PAGE_URL, self::WEB_URL], $attempt->source_urls);
        $this->assertNotNull($attempt->attempted_at);
        $this->assertNull($attempt->message);
        // opis już nie jest tekstem z B2B — karta nie wraca do uzupełniania
        $this->assertSame([], app(B2bDescriptionSupplement::class)->candidateIds($this->account, false));
    }

    public function test_duplicate_job_of_a_card_with_a_result_does_nothing(): void
    {
        // produkcja 28.09.2026: deadlock przy usuwaniu zadania — kolejka oddała je drugi raz po retry_after
        $card = $this->card(self::SHORT);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->queued($card);
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);

        // duplikat: karta już nie „krótka z B2B” — dawniej zapisywał „karta już się nie kwalifikuje”
        $this->runJob($card);

        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $attempt->status);
        $this->assertSame(sha1(self::AI_TEXT), $attempt->result_sha1);

        // karta bez stron — duplikat nie szuka od nowa
        $other = $this->card($this->shortOther(), ['sku' => 'R-2']);
        $this->link($other, 'b', sha1($this->shortOther()));
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $other->id, 'b2b_account_id' => $this->account->id, 'source_sha1' => sha1($this->shortOther()),
            'hosts_sha1' => $this->account->enrichmentHostsSha1(), 'status' => B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, 'attempts' => 1,
        ]);
        $this->runJob($other);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, $this->attemptOf($other)->status);
    }

    public function test_at_most_two_cards_work_at_once(): void
    {
        Queue::fake();
        [$card, $before] = $this->untouchedSetup();
        // dwie inne karty pracują — oba miejsca zajęte
        $held = [
            Cache::lock(SupplementB2bDescriptionJob::RUN_SLOT_KEY.'0', 600),
            Cache::lock(SupplementB2bDescriptionJob::RUN_SLOT_KEY.'1', 600),
        ];
        foreach ($held as $lock) {
            $this->assertTrue($lock->get());
        }
        $this->enrichment->shouldNotReceive('supplementB2bDescription');

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $this->attemptOf($card)->status);
        $this->assertSame(0, $this->attemptOf($card)->attempts);
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id && $job->delay !== null);

        // miejsce zwolnione — karta pracuje
        $held[0]->release();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);
        $held[1]->release();
    }

    public function test_running_card_shows_its_stage_and_start(): void
    {
        [$card] = $this->untouchedSetup();
        $seen = [];
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product, B2bSupplementContext $context, callable $progress) use ($card, &$seen): array {
                $progress('szukanie na stronach konta (ansell.com)');
                $attempt = $this->attemptOf($card);
                $seen = [$attempt->status, $attempt->stage, $attempt->started_at !== null];

                return $this->modelResult();
            });

        $this->runJob($card);

        $this->assertSame([B2bDescriptionSupplementAttempt::STATUS_RUNNING, 'szukanie na stronach konta (ansell.com)', true], $seen);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);
    }

    public function test_stopped_card_is_skipped_by_the_queued_job(): void
    {
        [$card, $before] = $this->untouchedSetup();
        app(B2bDescriptionSupplement::class)->stop($this->account);
        $this->enrichment->shouldNotReceive('supplementB2bDescription');

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_CANCELLED, $this->attemptOf($card)->status);
    }

    public function test_stop_during_work_ends_at_the_next_stage_without_saving(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product, B2bSupplementContext $context, callable $progress): array {
                // użytkownik klika „Zatrzymaj” w trakcie pracy modelu
                app(B2bDescriptionSupplement::class)->stop(null);
                $progress('model pisze opis (1 stron z internetu)');

                return $this->modelResult();
            });

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_CANCELLED, $this->attemptOf($card)->status);
    }

    public function test_stopped_cards_resume_by_button_not_by_sync(): void
    {
        [$card] = $this->untouchedSetup();
        $supplement = app(B2bDescriptionSupplement::class);
        $supplement->stop($this->account);

        Queue::fake();
        $this->assertSame(['candidates' => 0, 'queued' => 0], $supplement->queue($this->account, null, true, false));
        $this->assertSame(['candidates' => 1, 'queued' => 1], $supplement->queue($this->account));
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $this->attemptOf($card)->status);
    }

    public function test_search_outage_requeues_the_card_without_counting_an_attempt(): void
    {
        Queue::fake();
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSupplementSearchOutage('Wyszukiwarka nie odpowiedziała przy uzupełnianiu opisu R-1'));

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertSame(0, $attempt->attempts);
        $this->assertStringStartsWith('Wyszukiwarka niedostępna — ponowię o ', (string) $attempt->message);
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id
            && $job->outageWaitSince !== null
            && $job->delay !== null);
        // czekająca karta nie jest kandydatem drugi raz
        $this->assertSame([], app(B2bDescriptionSupplement::class)->candidateIds($this->account));
    }

    public function test_search_outage_after_the_wait_budget_is_a_failure(): void
    {
        Queue::fake();
        [$card] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSupplementSearchOutage('Wyszukiwarka nie odpowiedziała'));

        $since = now()->getTimestamp() - SupplementB2bDescriptionJob::OUTAGE_WAIT_BUDGET_SECONDS;
        app()->call([new SupplementB2bDescriptionJob((int) $card->id, (int) $this->account->id, $since), 'handle']);

        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->attempts);
        Queue::assertNothingPushed();
    }

    public function test_trace_marks_variant_gate_and_keeps_previous_payload(): void
    {
        $card = $this->card(self::SHORT, ['enrichment_payload' => ['merged_size_skus' => ['R-1-S'], 'attributes' => ['x' => 'y']]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());

        $this->runJob($card);

        $trace = ((array) $card->refresh()->enrichment_payload)['b2b_supplement'];
        $this->assertSame(SupplementB2bDescriptionJob::VARIANT_GATE, $trace['variant_gate']);
        // stan sprzed uzupełnienia bez atrybutów (liczone z karty)
        $this->assertSame(['merged_size_skus' => ['R-1-S']], $trace['previous_payload']);
    }

    public function test_undo_ungated_restores_b2b_text_payload_and_hashes_then_requeues(): void
    {
        Queue::fake();
        $card = $this->card(self::SHORT, ['enrichment_payload' => ['merged_size_skus' => ['R-1-S']]]);
        $links = [$this->link($card, 'a', sha1(self::SHORT)), $this->link($card, 'b', sha1(self::SHORT))];
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        // opis sprzed bramki wariantu: ślad bez znacznika
        $payload = (array) $card->refresh()->enrichment_payload;
        unset($payload['b2b_supplement']['variant_gate']);
        $card->forceFill(['enrichment_payload' => $payload])->saveQuietly();
        // uzupełniony już z bramką — nie ruszać
        $gated = $this->card($this->shortOther(), ['sku' => 'R-2']);
        $this->link($gated, 'g', sha1($this->shortOther()));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($gated);

        $supplement = app(B2bDescriptionSupplement::class);
        $preview = $supplement->undoUngated($this->account, false);
        $this->assertSame([(int) $card->id], $preview['candidates']);
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $result = $supplement->undoUngated($this->account, true);

        $this->assertSame([(int) $card->id], $result['undone']);
        $card->refresh();
        $this->assertSame(self::SHORT, $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(['R-1-S'], $payload['merged_size_skus']);
        $this->assertArrayNotHasKey('b2b_supplement', $payload);
        $this->assertArrayNotHasKey('features', $payload);
        $this->assertArrayNotHasKey('replaced_description', $payload);
        $this->assertSame(self::AI_TEXT, $payload['b2b_supplement_undone']['description']);
        foreach ($links as $link) {
            $link->refresh();
            $this->assertSame(sha1(self::SHORT), $link->description_hash);
            $this->assertNull($link->source_description_hash);
        }
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $this->attemptOf($card)->status);
        // karta znów czeka na uzupełnienie, ta z bramką — nie
        $this->assertSame(self::AI_TEXT, $gated->refresh()->description);
        $this->assertSame([(int) $card->id], $supplement->candidateIds($this->account));
    }

    public function test_undo_command_previews_then_undoes_and_requeues(): void
    {
        Queue::fake();
        $card = $this->card(self::SHORT);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        $payload = (array) $card->refresh()->enrichment_payload;
        unset($payload['b2b_supplement']['variant_gate']);
        $card->forceFill(['enrichment_payload' => $payload])->saveQuietly();
        // artisan buduje wszystkie komendy — atrapa usługi (bez typu) nie przejdzie przez ich konstruktory
        $this->app->forgetInstance(ProductEnrichmentService::class);

        $this->artisan('b2b:supplement-descriptions', ['--account' => $this->account->id, '--undo-ungated' => true])
            ->expectsOutputToContain('opisów uzupełnionych przed bramką wariantu: 1')
            ->assertSuccessful();
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
        Queue::assertNothingPushed();

        $this->artisan('b2b:supplement-descriptions', ['--account' => $this->account->id, '--undo-ungated' => true, '--apply' => true])
            ->expectsOutputToContain('zlecono ponownie: 1')
            ->assertSuccessful();
        $this->assertSame(self::SHORT, $card->refresh()->description);
        Queue::assertPushed(SupplementB2bDescriptionJob::class, 1);
    }

    public function test_undo_cards_restores_b2b_text_and_the_norms_column_written_from_the_supplement_list(): void
    {
        // Audyt Bolle 08.10.2026: okulary ProBlu z „EN 166” z szablonu sklepu; kolumnę norm wpisało potem
        // products:restore-norms-column z listy norm uzupełnienia.
        Queue::fake();
        $result = $this->modelResult();
        $result['payload']['norms'] = ['EN 166'];
        $card = $this->card(self::SHORT, ['enrichment_payload' => ['merged_size_skus' => ['R-1-S']]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($result);
        $this->runJob($card);
        // po uzupełnieniu: kolumna z listy norm (restore-norms-column) i klucz dopisany przez inny proces
        $later = (array) $card->refresh()->enrichment_payload;
        $later['merged_size_skus'] = ['R-1-S', 'R-1-M'];
        $card->forceFill(['norms' => 'EN 166', 'enrichment_payload' => $later])->saveQuietly();
        // kolumna zmieniona później przez człowieka — zostaje
        $edited = $this->card($this->shortOther(), ['sku' => 'R-2']);
        $this->link($edited, 'e', sha1($this->shortOther()));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($result);
        $this->runJob($edited);
        $edited->refresh()->forceFill(['norms' => 'EN 166, EN 170'])->saveQuietly();
        // karta bez uzupełnienia — tylko komunikat
        $plain = $this->card(self::SHORT, ['sku' => 'R-3']);

        $supplement = app(B2bDescriptionSupplement::class);
        $preview = $supplement->undoCards($this->account, [(int) $card->id, (int) $edited->id, (int) $plain->id, 999999], 'EN 166 z szablonu sklepu', false, false);
        $this->assertSame([(int) $card->id, (int) $edited->id], $preview['candidates']);
        $this->assertSame([
            (int) $plain->id => 'opis karty nie jest wynikiem uzupełnienia (zmieniony albo nieuzupełniony)',
            999999 => 'karty nie ma',
        ], $preview['skipped']);
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $done = $supplement->undoCards($this->account, [(int) $card->id, (int) $edited->id], 'EN 166 z szablonu sklepu', false, true);

        $this->assertSame([(int) $card->id, (int) $edited->id], $done['undone']);
        $card->refresh();
        $this->assertSame(self::SHORT, $card->description);
        $this->assertNull($card->norms);
        $payload = (array) $card->enrichment_payload;
        // klucz spoza wyniku uzupełnienia zmieniony po uzupełnieniu — zostaje w obecnej postaci
        $this->assertSame(['R-1-S', 'R-1-M'], $payload['merged_size_skus']);
        $this->assertArrayNotHasKey('norms', $payload);
        $this->assertArrayNotHasKey('replaced_description', $payload);
        $this->assertSame('EN 166 z szablonu sklepu', $payload['b2b_supplement_undone']['reason']);
        $this->assertSame('EN 166', $payload['b2b_supplement_undone']['norms']);
        $this->assertSame('EN 166, EN 170', $edited->refresh()->norms);
        $this->assertArrayNotHasKey('norms', (array) $edited->enrichment_payload['b2b_supplement_undone']);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('cofnięte — EN 166 z szablonu sklepu, do ponownego uzupełnienia', $attempt->message);
        $this->assertContains((int) $card->id, $supplement->candidateIds($this->account));
    }

    public function test_undo_cards_keeping_b2b_text_is_final_and_not_queued_again_by_sync(): void
    {
        Queue::fake();
        $card = $this->card(self::SHORT);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        // lista stron konta zmieniona od uzupełnienia — wynik ostateczny ma dotyczyć obecnej listy
        $this->account->forceFill(['enrichment_sites' => ['ansell.com', 'specshop.pl']])->save();
        $this->app->forgetInstance(ProductEnrichmentService::class);

        $this->artisan('b2b:supplement-descriptions', [
            '--account' => $this->account->id, '--undo' => [(string) $card->id], '--reason' => 'dane spoza źródeł', '--keep-b2b' => true,
        ])->expectsOutputToContain('opisów uzupełnionych do cofnięcia: 1 z 1')->assertSuccessful();
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $this->artisan('b2b:supplement-descriptions', [
            '--account' => $this->account->id, '--undo' => [(string) $card->id], '--reason' => 'dane spoza źródeł', '--keep-b2b' => true, '--apply' => true,
        ])->expectsOutputToContain('cofnięto: 1')->assertSuccessful();

        $this->assertSame(self::SHORT, $card->refresh()->description);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_KEPT, $attempt->status);
        $this->assertSame('cofnięte — dane spoza źródeł; zostaje opis z B2B', $attempt->message);
        $this->assertSame($this->account->fresh()->enrichmentHostsSha1(), $attempt->hosts_sha1);
        $this->assertSame(sha1(self::SHORT), $attempt->source_sha1);
        Queue::assertNothingPushed();
        // synchronizacja (tylko karty bez ostatecznej próby) jej nie zleca; „także karty już próbowane” — tak
        $this->assertNotContains((int) $card->id, app(B2bDescriptionSupplement::class)->candidateIds($this->account));
        $this->assertContains((int) $card->id, app(B2bDescriptionSupplement::class)->candidateIds($this->account, false));
    }

    public function test_undo_cards_command_requeues_and_needs_one_account_and_a_reason(): void
    {
        Queue::fake();
        $card = $this->card(self::SHORT);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());
        $this->runJob($card);
        // limit nieudanych prób wyczerpany — wskazana karta i tak wraca do kolejki
        B2bDescriptionSupplementAttempt::query()->where('product_id', $card->id)
            ->update(['attempts' => B2bDescriptionSupplementAttempt::MAX_FAILED_ATTEMPTS]);
        $this->app->forgetInstance(ProductEnrichmentService::class);

        $this->artisan('b2b:supplement-descriptions', ['--undo' => [(string) $card->id], '--reason' => 'x', '--apply' => true])
            ->expectsOutputToContain('--undo wymaga jednego konta')
            ->assertFailed();
        $this->artisan('b2b:supplement-descriptions', ['--account' => $this->account->id, '--undo' => [(string) $card->id], '--apply' => true])
            ->expectsOutputToContain('--undo wymaga powodu')
            ->assertFailed();
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $this->artisan('b2b:supplement-descriptions', [
            '--account' => $this->account->id, '--undo' => [$card->id.',999999'], '--reason' => 'strona innego wariantu', '--apply' => true,
        ])->expectsOutputToContain('zlecono ponownie: 1')
            ->expectsOutputToContain('karta #999999 pominięta: karty nie ma')
            ->assertSuccessful();

        $this->assertSame(self::SHORT, $card->refresh()->description);
        Queue::assertPushed(SupplementB2bDescriptionJob::class, 1);
    }

    public function test_undo_of_legacy_trace_drops_supplement_keys_and_keeps_translation_hash(): void
    {
        // dawny ślad (bez previous_payload) na karcie z tłumaczeniem: source hash = tekst obcy
        $english = 'Nitrile coated protective glove, category II.';
        $card = $this->card(self::AI_TEXT, ['enrichment_payload' => [
            'features' => ['powłoka nitrylowa'],
            'source_urls' => [self::WEB_URL],
            'primary_source_kind' => 'b2b_supplement',
            'replaced_description' => self::SHORT,
            'replaced_description_hash' => sha1(self::AI_TEXT),
            'b2b_sources' => ['described_at' => 'x'],
            'b2b_supplement' => [
                'b2b_account_id' => $this->account->id,
                'b2b_text' => self::SHORT,
                'source_sha1' => sha1($english),
                'result_sha1' => sha1(self::AI_TEXT),
                'web_source_urls' => [self::WEB_URL],
            ],
        ]]);
        $link = $this->link($card, 'a', sha1(self::AI_TEXT), sha1($english));
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $card->id, 'b2b_account_id' => $this->account->id, 'source_sha1' => sha1($english),
            'hosts_sha1' => $this->account->enrichmentHostsSha1(), 'status' => B2bDescriptionSupplementAttempt::STATUS_REPLACED,
            'attempts' => 1, 'result_sha1' => sha1(self::AI_TEXT),
        ]);
        // opis zmieniony po uzupełnieniu — zostaje
        $edited = $this->card('Opis poprawiony ręcznie przez dział zakupów.', ['sku' => 'R-3', 'enrichment_payload' => [
            'b2b_supplement' => ['b2b_text' => self::SHORT, 'source_sha1' => sha1(self::SHORT), 'result_sha1' => sha1(self::AI_TEXT)],
        ]]);
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $edited->id, 'b2b_account_id' => $this->account->id, 'source_sha1' => sha1(self::SHORT),
            'hosts_sha1' => $this->account->enrichmentHostsSha1(), 'status' => B2bDescriptionSupplementAttempt::STATUS_REPLACED,
        ]);

        $result = app(B2bDescriptionSupplement::class)->undoUngated($this->account, true);

        $this->assertSame([(int) $card->id], $result['undone']);
        $this->assertSame('opis zmieniony od uzupełnienia', $result['skipped'][(int) $edited->id]);
        $payload = (array) $card->refresh()->enrichment_payload;
        $this->assertSame(self::SHORT, $card->description);
        foreach (['features', 'source_urls', 'primary_source_kind', 'replaced_description', 'b2b_supplement'] as $key) {
            $this->assertArrayNotHasKey($key, $payload, $key);
        }
        $this->assertSame(['described_at' => 'x'], $payload['b2b_sources']);
        $link->refresh();
        $this->assertSame(sha1(self::SHORT), $link->description_hash);
        $this->assertSame(sha1($english), $link->source_description_hash);
        $this->assertSame('Opis poprawiony ręcznie przez dział zakupów.', $edited->refresh()->description);
    }

    public function test_existing_replaced_description_is_not_overwritten(): void
    {
        $card = $this->card(self::SHORT, ['enrichment_payload' => [
            'replaced_description' => 'Opis sprzed tekstu z B2B',
            'replaced_description_hash' => sha1(self::SHORT),
        ]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());

        $this->runJob($card);

        $payload = (array) $card->refresh()->enrichment_payload;
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame('Opis sprzed tekstu z B2B', $payload['replaced_description']);
        $this->assertSame(sha1(self::SHORT), $payload['replaced_description_hash']);
        $this->assertSame(self::SHORT, $payload['b2b_supplement']['b2b_text']);
        // job zlecony bez próby (poza queue()) zakłada ją sam
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);
    }

    public function test_rejected_description_keeps_the_card_untouched(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSourcesDescriptionRejected('opis nie jest dłuższy niż tekst B2B'));

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_KEPT, $attempt->status);
        $this->assertSame('opis nie jest dłuższy niż tekst B2B', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        $this->assertNull($attempt->result_sha1);
        $this->assertNotNull($attempt->attempted_at);
    }

    public function test_no_pages_keeps_the_card_untouched(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSupplementNoPages('brak potwierdzonych stron wyrobu'));

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, $attempt->status);
        $this->assertSame('brak potwierdzonych stron wyrobu', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        // wynik ostateczny dla tego wejścia — kolejny przebieg karty nie zleca
        $this->assertSame([], app(B2bDescriptionSupplement::class)->candidateIds($this->account));
        $this->assertSame([(int) $card->id], app(B2bDescriptionSupplement::class)->candidateIds($this->account, false));
    }

    public function test_error_records_failed_attempt_and_is_rethrown(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new RuntimeException('model odpowiedział 500'));

        try {
            $this->runJob($card);
            $this->fail('wyjątek miał polecieć dalej');
        } catch (RuntimeException $e) {
            $this->assertSame('model odpowiedział 500', $e->getMessage());
        }

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('model odpowiedział 500', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        // do ponowienia
        $this->assertSame([(int) $card->id], app(B2bDescriptionSupplement::class)->candidateIds($this->account));
    }

    public function test_card_no_longer_eligible_closes_the_attempt_without_model(): void
    {
        [$card] = $this->untouchedSetup();
        // opis poprawiony ręcznie po zleceniu
        $card->forceFill(['description' => 'Opis poprawiony ręcznie przez dział zakupów.'])->save();
        $this->enrichment->shouldNotReceive('supplementB2bDescription');

        $this->runJob($card);

        $this->assertSame('Opis poprawiony ręcznie przez dział zakupów.', $card->refresh()->description);
        $this->assertSame(sha1(self::SHORT), B2bProductLink::query()->where('product_id', $card->id)->value('description_hash'));
        $attempt = $this->attemptOf($card);
        // nie wynik ostateczny — powód bywa chwilowy, kolejne zlecenie sprawdzi kartę od nowa
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(SupplementB2bDescriptionJob::NOT_ELIGIBLE_MESSAGE, $attempt->message);
        $this->assertSame(0, $attempt->attempts);
        $this->assertSame(sha1(self::SHORT), $attempt->source_sha1);
    }

    public function test_description_changed_while_the_model_answered_is_not_overwritten(): void
    {
        [$card] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product): array {
                Product::query()->whereKey($product->id)->update(['description' => 'Opis poprawiony ręcznie w trakcie.']);

                return $this->modelResult();
            });

        $this->runJob($card);

        $card->refresh();
        $this->assertSame('Opis poprawiony ręcznie w trakcie.', $card->description);
        $this->assertArrayNotHasKey('b2b_supplement', (array) $card->enrichment_payload);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::SHORT), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('opis karty zmieniony', (string) $attempt->message);
    }

    public function test_sync_writing_new_text_while_the_model_answered_wins(): void
    {
        [$card] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product): array {
                B2bProductLink::query()->where('product_id', $product->id)->update(['description_hash' => sha1('nowy tekst')]);

                return $this->modelResult();
            });

        $this->runJob($card);

        $this->assertSame(self::SHORT, $card->refresh()->description);
        $this->assertStringContainsString('import zapisał nowy opis', (string) $this->attemptOf($card)->message);
    }

    /**
     * Dystrybutor: synchronizacja zleca uzupełnienie (kolejka w testach jest synchroniczna), ten sam tekst u dostawcy
     * zostawia uzupełniony opis bez nowego pytania modelu, nowy tekst wraca na kartę i karta znowu czeka.
     */
    public function test_sync_keeps_supplement_for_same_source_and_restores_changed_source(): void
    {
        $shop = new SupplementSyncConnector;
        $shop->description = self::SHORT;
        $result = $this->modelResult();
        $result['payload']['norms'] = ['EN 388'];
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($result);

        $this->sync($shop);

        $card = Product::query()->where('sku', 'SUP-1')->sole();
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);
        // kolumna norm z listy uzupełnienia (products:restore-norms-column)
        $card->forceFill(['norms' => 'EN 388'])->saveQuietly();

        // ten sam tekst u dostawcy: opis zostaje, model niepytany (once), powiązanie bez zmian
        $this->sync($shop);

        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
        $this->assertSame(sha1(self::SHORT), $link->source_description_hash);

        // nowy tekst u dostawcy wraca na kartę; uzupełniony opis zostaje w replaced_description i w śladzie
        // b2b_supplement_undone, a jego listy, źródła i kolumna norm odchodzą razem z nim (audyt Bolle 08.10.2026:
        // zostawały normy ze sklepów przy tekście od dostawcy)
        Queue::fake();
        $shop->description = 'Rękawica nitrylowa kat. II — nowy opis w sklepie dostawcy.';
        $this->sync($shop);

        $card->refresh();
        $this->assertSame($shop->description, $card->description);
        $this->assertNull($card->norms);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(self::AI_TEXT, $payload['replaced_description']);
        foreach (['b2b_supplement', 'norms', 'features', 'source_urls', 'primary_source_kind'] as $key) {
            $this->assertArrayNotHasKey($key, $payload, $key);
        }
        $this->assertSame(self::AI_TEXT, $payload['b2b_supplement_undone']['description']);
        $this->assertSame('EN 388', $payload['b2b_supplement_undone']['norms']);
        $this->assertSame('nowy tekst u dostawcy (synchronizacja B2B)', $payload['b2b_supplement_undone']['reason']);
        $link->refresh();
        $this->assertSame(sha1($shop->description), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        // nowy tekst = nowe wejście: karta znowu zlecona
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertSame(sha1($shop->description), $attempt->source_sha1);
    }

    /**
     * Witryna producenta z obcojęzycznym tekstem (jak Bolle): opis producenta zastępuje każdy opis karty, ale
     * uzupełnienie przetłumaczonego tekstu zostaje, dopóki tekst źródła się nie zmieni (keepsTranslation).
     */
    public function test_manufacturer_site_keeps_supplement_of_translation_until_source_changes(): void
    {
        $this->account->forceFill(['connector' => 'bolle', 'sites' => ['bolle-safety.com']])->save();
        $shop = new SupplementManufacturerConnector;
        $english = 'Nitrile coated safety glove, category II.';
        $shop->description = $english;
        Queue::fake();

        $this->sync($shop);

        $card = Product::query()->where('sku', 'SUP-1')->sole();
        $this->assertSame($english, $card->description);
        // tekst obcojęzyczny przed tłumaczeniem nie jest uzupełniany
        Queue::assertNotPushed(SupplementB2bDescriptionJob::class);

        // stan po TranslateB2bProductTextJob
        $card->forceFill(['description' => self::SHORT])->save();
        B2bProductLink::query()->where('product_id', $card->id)->update([
            'description_hash' => sha1(self::SHORT),
            'source_description_hash' => sha1($english),
        ]);
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->withArgs(fn (Product $product, B2bSupplementContext $context): bool => $context->sourceSha1 === sha1($english))
            ->andReturn($this->modelResult());
        app(B2bDescriptionSupplement::class)->queue($this->account->fresh());
        $this->runJob($card);
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $this->sync($shop);

        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
        $this->assertSame(sha1($english), $link->source_description_hash);

        $shop->description = 'Nitrile coated safety glove, category II, new coating.';
        $this->sync($shop);

        $card->refresh();
        $this->assertSame($shop->description, $card->description);
        $this->assertSame(self::AI_TEXT, $card->enrichment_payload['replaced_description']);
        // listy uzupełnienia odchodzą razem z jego opisem także przy witrynie producenta (Bolle)
        $this->assertArrayNotHasKey('features', (array) $card->enrichment_payload);
        $this->assertArrayNotHasKey('b2b_supplement', (array) $card->enrichment_payload);
        $this->assertNull(B2bProductLink::query()->where('product_id', $card->id)->value('source_description_hash'));
    }

    /**
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}
     */
    private function modelResult(): array
    {
        return [
            'description' => self::AI_TEXT,
            'payload' => [
                'features' => ['powłoka nitrylowa'],
                'norms' => [],
                'source_urls' => [self::PAGE_URL, self::WEB_URL],
                'primary_source_url' => self::WEB_URL,
                'primary_source_kind' => 'b2b_supplement',
                'confidence' => 0.8,
            ],
            'norms' => null,
            'packaging' => null,
            'web_source_urls' => [self::WEB_URL],
            'dropped' => ['EN 388 4131X'],
            'dropped_claims' => ['odporność na przecięcie'],
        ];
    }

    /**
     * @return array{0: Product, 1: array<string, mixed>}
     */
    private function untouchedSetup(): array
    {
        $card = $this->card(self::SHORT, ['enrichment_payload' => ['features' => ['cecha z B2B']]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->queued($card);

        return [$card, $this->snapshot($card)];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $card): array
    {
        $card->refresh();

        return [
            'description' => $card->description,
            'payload' => $card->enrichment_payload,
            'status' => $card->enrichment_status,
            'links' => B2bProductLink::query()->where('product_id', $card->id)->orderBy('id')
                ->get(['description_hash', 'source_description_hash'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function assertUntouched(Product $card, array $before): void
    {
        $this->assertSame($before, $this->snapshot($card));
    }

    private function runJob(Product $card): void
    {
        app()->call([new SupplementB2bDescriptionJob((int) $card->id, (int) $this->account->id), 'handle']);
    }

    private function queued(Product $card): void
    {
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $this->account->id,
            'source_sha1' => sha1((string) $card->description),
            'hosts_sha1' => $this->account->enrichmentHostsSha1(),
            'status' => B2bDescriptionSupplementAttempt::STATUS_QUEUED,
        ]);
    }

    private function sync(B2bConnector $connector): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function card(string $description, array $extra = []): Product
    {
        $product = Product::query()->create([
            'sku' => 'R-1',
            'name' => 'Rękawica nitrylowa R-1',
            'manufacturer' => 'Testowy',
            'description' => $description,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            ...$extra,
        ]);

        return $product->refresh();
    }

    private function link(Product $product, string $remoteId, string $descriptionHash, ?string $sourceHash = null): B2bProductLink
    {
        return B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'remote_id' => $remoteId,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'description_hash' => $descriptionHash,
            'source_description_hash' => $sourceHash,
        ]);
    }

    /** Drugi krótki tekst z B2B (inna karta). */
    private function shortOther(): string
    {
        return self::SHORT.' Wariant 2.';
    }

    private function attemptOf(Product $product): B2bDescriptionSupplementAttempt
    {
        return B2bDescriptionSupplementAttempt::query()
            ->where('product_id', $product->id)
            ->where('b2b_account_id', $this->account->id)
            ->sole();
    }
}

/** Łącznik testowy dystrybutora bez sieci: jedna pozycja SUP-1, opis z pola $description. */
class SupplementSyncConnector implements B2bConnector
{
    public string $description = '';

    public static function key(): string
    {
        return 'supplementsynctest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'supplement-sync.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): static
    {
        return new static;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(remoteId: '1', sku: 'SUP-1', name: 'Rękawica nitrylowa SUP-1');
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Testowy';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 8.0, base: 10.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->description;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/** Witryna producenta marki „Testowy” z tekstem po angielsku (jak Bolle). */
final class SupplementManufacturerConnector extends SupplementSyncConnector implements B2bForeignLanguageSource, B2bManufacturerSite
{
    public static function ownBrand(): string
    {
        return 'Testowy';
    }
}
