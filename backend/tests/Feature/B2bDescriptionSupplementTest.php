<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SupplementB2bDescriptionJob;
use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Kwalifikacja kart do uzupełnienia krótkiego opisu B2B ze stron konta (B2bDescriptionSupplement, decyzja
 * użytkownika 28.09.2026): tylko opis wciąż z B2B tego konta, krótszy niż próg, bez kart opisywanych innym
 * mechanizmem; zlecanie z próbą i odciskiem wejścia; zlecenie po przebiegu synchronizacji. Dane SYNTETYCZNE.
 */
final class B2bDescriptionSupplementTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';

    private const PAGE_URL = 'https://b2b.procera.pl/produkt/rekawica-nitrylowa-123';

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->account('procera', ['https://www.ansell.com/pl/', 'mapa-pro.com']);
    }

    public function test_context_of_short_b2b_description_on_the_account(): void
    {
        $card = $this->card('R-1', self::SHORT, ['shop_source_url' => self::PAGE_URL]);
        $first = $this->link($this->account, $card, 'a', sha1(self::SHORT));
        $second = $this->link($this->account, $card, 'b', sha1(self::SHORT));

        $context = $this->service()->context($card);

        $this->assertNotNull($context);
        $this->assertSame((int) $this->account->id, $context->accountId);
        $this->assertSame([(int) $first->id, (int) $second->id], $context->linkIds);
        $this->assertSame(sha1(self::SHORT), $context->descriptionHash);
        $this->assertNull($context->sourceDescriptionHash);
        $this->assertSame(sha1(self::SHORT), $context->sourceSha1);
        $this->assertSame(self::SHORT, $context->b2bText);
        $this->assertSame(self::PAGE_URL, $context->b2bUrl);
        $this->assertSame(['ansell.com', 'mapa-pro.com'], $context->hosts);
        $this->assertSame($this->account->enrichmentHostsSha1(), $context->hostsSha1);
        $this->assertSame(B2bDescriptionSupplement::DEFAULT_MIN_CHARS, $context->minChars);
        $this->assertSame(self::SHORT, $context->productDescription);
        // podane konto daje ten sam wynik
        $this->assertEquals($context, $this->service()->context($card, $this->account));
    }

    public function test_b2b_url_only_on_the_site_of_this_account_connector(): void
    {
        $foreignPage = $this->card('R-2', self::SHORT, ['shop_source_url' => 'https://www.ansell.com/pl/p/123']);
        $this->link($this->account, $foreignPage, 'a', sha1(self::SHORT));
        // witryna innego łącznika (Mascot) — też „adres z B2B”, ale nie tego konta
        $otherConnector = $this->card('R-3', $this->short(1), ['shop_source_url' => 'https://b2b.mascot.dk/p/1']);
        $this->link($this->account, $otherConnector, 'b', sha1($this->short(1)));
        $subdomain = $this->card('R-4', $this->short(2), ['shop_source_url' => 'https://www.b2b.procera.pl/p/1']);
        $this->link($this->account, $subdomain, 'c', sha1($this->short(2)));

        $this->assertSame('', $this->service()->context($foreignPage)?->b2bUrl);
        $this->assertSame('', $this->service()->context($otherConnector)?->b2bUrl);
        $this->assertSame('https://www.b2b.procera.pl/p/1', $this->service()->context($subdomain)?->b2bUrl);
    }

    public function test_threshold_counts_plain_text_against_the_account_limit(): void
    {
        $long = str_repeat('Rękawica ochronna nitrylowa do prac montażowych. ', 25);
        $this->assertGreaterThanOrEqual(1000, B2bDescriptionSupplement::plainLength($long));
        $longCard = $this->card('L-1', $long);
        $this->link($this->account, $longCard, 'l', sha1($long));
        // znaczniki HTML nie liczą się do długości
        $html = '<p>'.self::SHORT.'</p>'.str_repeat('<span class="x"></span>', 60);
        $this->assertGreaterThan(1000, mb_strlen($html));
        $htmlCard = $this->card('H-1', $html);
        $this->link($this->account, $htmlCard, 'h', sha1($html));
        $card = $this->card('S-1', self::SHORT);
        $this->link($this->account, $card, 's', sha1(self::SHORT));

        $this->assertNull($this->service()->context($longCard));
        $this->assertNotNull($this->service()->context($htmlCard));
        $this->assertNotNull($this->service()->context($card));

        // próg przy koncie: tekst dłuższy niż próg nie jest „krótki”
        $this->account->forceFill(['enrichment_min_chars' => mb_strlen(self::SHORT)])->save();
        $this->assertNull($this->service()->context($card));
        $this->account->forceFill(['enrichment_min_chars' => mb_strlen(self::SHORT) + 1])->save();
        $this->assertNotNull($this->service()->context($card));
    }

    public function test_account_without_hosts_or_without_connector_does_not_qualify(): void
    {
        $card = $this->card('R-1', self::SHORT);
        $this->link($this->account, $card, 'a', sha1(self::SHORT));

        $this->account->forceFill(['enrichment_sites' => []])->save();
        $this->assertNull($this->service()->context($card));

        $this->account->forceFill(['enrichment_sites' => ['ansell.com'], 'connector' => 'nieznany', 'sites' => ['nieznany.example']])->save();
        $this->assertNull($this->service()->context($card));
    }

    public function test_datasheet_connectors_are_excluded(): void
    {
        foreach (['tegro', 'artra', 'polstar'] as $i => $key) {
            $account = $this->account($key, ['ansell.com']);
            $card = $this->card('D-'.$i, $this->short($i));
            $this->link($account, $card, 'd'.$i, sha1($this->short($i)));

            $this->assertNull($this->service()->context($card), $key);
            $this->assertSame([], $this->service()->candidateIds($account), $key);
        }
    }

    public function test_foreign_language_connector_needs_a_translation(): void
    {
        $bolle = $this->account('bolle', ['bolle-safety.com']);
        $english = 'Safety glasses with anti-fog coating.';
        $untranslated = $this->card('B-1', $english);
        $this->link($bolle, $untranslated, 'b1', sha1($english));
        $translated = $this->card('B-2', self::SHORT);
        $this->link($bolle, $translated, 'b2', sha1(self::SHORT), sha1($english));

        $this->assertNull($this->service()->context($untranslated));
        $context = $this->service()->context($translated);
        $this->assertNotNull($context);
        $this->assertSame(sha1($english), $context->sourceDescriptionHash);
        $this->assertSame(sha1($english), $context->sourceSha1);
        $this->assertSame([(int) $translated->id], $this->service()->candidateIds($bolle));
    }

    public function test_foreign_text_cards_wait_for_translation(): void
    {
        foreach (['ardon', 'uvex'] as $i => $key) {
            $account = $this->account($key, ['ansell.com']);
            $english = 'Safety glasses with anti-fog coating for use in the workshop and with the helmet.';
            $czech = 'Zátkový chránič sluchu z pěnového materiálu, balení obsahuje dvě páry, útlum 37 dB.';
            $untranslatedEn = $this->card('F-en-'.$i, $english);
            $this->link($account, $untranslatedEn, 'fe'.$i, sha1($english));
            $untranslatedCz = $this->card('F-cz-'.$i, $czech);
            $this->link($account, $untranslatedCz, 'fc'.$i, sha1($czech));
            // polski tekst ze sklepu tego konta (bez tłumaczenia) — kwalifikuje się
            $polish = $this->card('F-pl-'.$i, $this->short(10 + $i));
            $this->link($account, $polish, 'fp'.$i, sha1($this->short(10 + $i)));
            // przetłumaczony opis (source hash = tekst obcy) — kwalifikuje się
            $translated = $this->card('F-tr-'.$i, $this->short(20 + $i));
            $this->link($account, $translated, 'ft'.$i, sha1($this->short(20 + $i)), sha1($english));

            $this->assertNull($this->service()->context($untranslatedEn), $key);
            $this->assertNull($this->service()->context($untranslatedCz), $key);
            $this->assertNotNull($this->service()->context($polish), $key);
            $this->assertNotNull($this->service()->context($translated), $key);
            $this->assertEqualsCanonicalizing([(int) $polish->id, (int) $translated->id], $this->service()->candidateIds($account), $key);
        }
    }

    public function test_card_waiting_in_the_queue_is_not_a_candidate_again(): void
    {
        $card = $this->card('Q-1', self::SHORT);
        $this->link($this->account, $card, 'q1', sha1(self::SHORT));
        $this->attempt($card, B2bDescriptionSupplementAttempt::STATUS_QUEUED, sha1(self::SHORT), $this->account->enrichmentHostsSha1(), 0);

        $this->assertSame([], $this->service()->candidateIds($this->account));
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account, false));

        // zlecenie sprzed doby — job zgubiony, karta wraca do kandydatów
        B2bDescriptionSupplementAttempt::query()->update(['updated_at' => now()->subDays(2)]);
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account));
    }

    public function test_repeated_failures_for_the_same_input_stop_automatic_retries(): void
    {
        $card = $this->card('F-1', self::SHORT);
        $this->link($this->account, $card, 'f1', sha1(self::SHORT));
        $hosts = $this->account->enrichmentHostsSha1();

        $this->attempt($card, B2bDescriptionSupplementAttempt::STATUS_FAILED, sha1(self::SHORT), $hosts, B2bDescriptionSupplementAttempt::MAX_FAILED_ATTEMPTS - 1);
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account));

        B2bDescriptionSupplementAttempt::query()->update(['attempts' => B2bDescriptionSupplementAttempt::MAX_FAILED_ATTEMPTS]);
        $this->assertSame([], $this->service()->candidateIds($this->account));
        // „także karty już próbowane” i nowy tekst u dostawcy zlecają kartę dalej
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account, false));
        B2bDescriptionSupplementAttempt::query()->update(['source_sha1' => sha1('poprzedni tekst u dostawcy')]);
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account));
    }

    public function test_links_with_different_hashes_or_edited_description_do_not_qualify(): void
    {
        $differentDescription = $this->card('X-1', self::SHORT);
        $this->link($this->account, $differentDescription, 'x1a', sha1(self::SHORT));
        $this->link($this->account, $differentDescription, 'x1b', sha1('inny tekst'));

        $differentSource = $this->card('X-2', $this->short(1));
        $this->link($this->account, $differentSource, 'x2a', sha1($this->short(1)), sha1('źródło A'));
        $this->link($this->account, $differentSource, 'x2b', sha1($this->short(1)), sha1('źródło B'));

        // opis zmieniony ręcznie po synchronizacji — odcisk powiązania nie pasuje
        $edited = $this->card('X-3', 'Opis poprawiony ręcznie przez dział zakupów.');
        $this->link($this->account, $edited, 'x3', sha1(self::SHORT));

        // etykieta zamiast opisu (Product::isDescriptionText)
        $label = $this->card('X-4', 'Jednostka: szt.');
        $this->link($this->account, $label, 'x4', sha1('Jednostka: szt.'));

        foreach ([$differentDescription, $differentSource, $edited, $label] as $card) {
            $this->assertNull($this->service()->context($card), (string) $card->sku);
        }
        $this->assertSame([], $this->service()->candidateIds($this->account));
    }

    public function test_description_written_by_the_supplement_does_not_qualify_again(): void
    {
        // stan po SupplementB2bDescriptionJob: hashe powiązania zgodne z opisem, opis krótszy niż próg
        $supplemented = 'Rękawica z wkładki nylonowej powlekanej nitrylem, kategoria II, do prac montażowych.';
        $card = $this->card('U-1', $supplemented, ['enrichment_payload' => ['b2b_supplement' => ['result_sha1' => sha1($supplemented)]]]);
        $this->link($this->account, $card, 'u', sha1($supplemented), sha1(self::SHORT));
        // lista stron zmieniona — próba z innym hosts_sha1 nie blokuje, a mimo to karta nie wraca
        $this->attempt($card, B2bDescriptionSupplementAttempt::STATUS_REPLACED, sha1(self::SHORT), sha1('stare strony'));

        $this->assertNull($this->service()->context($card));
        $this->assertSame([], $this->service()->candidateIds($this->account));
        $this->assertSame([], $this->service()->candidateIds($this->account, false));

        // nowy tekst u dostawcy zapisany synchronizacją: ślad już nie pasuje do opisu
        $card->forceFill(['description' => self::SHORT])->save();
        B2bProductLink::query()->where('product_id', $card->id)->update(['description_hash' => sha1(self::SHORT), 'source_description_hash' => null]);
        $this->assertNotNull($this->service()->context($card->refresh()));
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account));
    }

    public function test_busy_enrichment_status_does_not_qualify(): void
    {
        $card = $this->card('E-1', self::SHORT);
        $this->link($this->account, $card, 'e', sha1(self::SHORT));

        foreach ([Product::ENRICHMENT_MANUAL, Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING] as $status) {
            $card->forceFill(['enrichment_status' => $status])->saveQuietly();
            $this->assertNull($this->service()->context($card), $status);
            $this->assertSame([], $this->service()->candidateIds($this->account), $status);
        }
        foreach ([Product::ENRICHMENT_DONE, Product::ENRICHMENT_FAILED, Product::ENRICHMENT_NONE] as $status) {
            $card->forceFill(['enrichment_status' => $status])->saveQuietly();
            $this->assertNotNull($this->service()->context($card), $status);
            $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account), $status);
        }
    }

    public function test_manufacturer_description_disabled_on_the_account_does_not_qualify(): void
    {
        $card = $this->card('M-1', self::SHORT, ['manufacturer' => 'Ansell']);
        $this->link($this->account, $card, 'm', sha1(self::SHORT), null, 'ANSELL');
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'manufacturer' => 'ANSELL',
            'manufacturer_key' => B2bManufacturerRules::key('ANSELL'),
            'take_price' => true,
            'take_description' => false,
        ]);

        $this->assertNull($this->service()->context($card));
        $this->assertSame([], $this->service()->candidateIds($this->account));
    }

    public function test_several_accounts_with_hosts_pick_the_lowest_id(): void
    {
        $second = $this->account('mascot', ['mascot.dk']);
        $card = $this->card('W-1', self::SHORT);
        $this->link($this->account, $card, 'w1', sha1(self::SHORT));
        $this->link($second, $card, 'w2', sha1(self::SHORT));
        // karta tylko drugiego konta
        $own = $this->card('W-2', $this->short(1));
        $this->link($second, $own, 'w3', sha1($this->short(1)));

        $this->assertSame((int) $this->account->id, $this->service()->context($card)?->accountId);
        $this->assertSame((int) $second->id, $this->service()->context($card, $second)?->accountId);
        $this->assertSame([(int) $card->id], $this->service()->candidateIds($this->account));
        $this->assertSame([(int) $own->id], $this->service()->candidateIds($second));

        // pierwsze konto bez hostów — karta należy do drugiego
        $this->account->forceFill(['enrichment_sites' => null])->save();
        $this->assertSame((int) $second->id, $this->service()->context($card)?->accountId);
        $this->assertSame([(int) $card->id, (int) $own->id], $this->service()->candidateIds($second));
    }

    public function test_candidate_ids_skip_final_attempts_for_the_same_input(): void
    {
        $replaced = $this->card('A-1', self::SHORT);
        $this->link($this->account, $replaced, 'a1', sha1(self::SHORT));
        $noPages = $this->card('A-2', $this->short(1));
        $this->link($this->account, $noPages, 'a2', sha1($this->short(1)));
        $failed = $this->card('A-3', $this->short(2));
        $this->link($this->account, $failed, 'a3', sha1($this->short(2)));
        $otherHosts = $this->card('A-4', $this->short(3));
        $this->link($this->account, $otherHosts, 'a4', sha1($this->short(3)));
        $otherSource = $this->card('A-5', $this->short(4));
        $this->link($this->account, $otherSource, 'a5', sha1($this->short(4)));
        $fresh = $this->card('A-6', $this->short(5));
        $this->link($this->account, $fresh, 'a6', sha1($this->short(5)));

        $hosts = $this->account->enrichmentHostsSha1();
        $this->attempt($replaced, B2bDescriptionSupplementAttempt::STATUS_KEPT, sha1(self::SHORT), $hosts);
        $this->attempt($noPages, B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, sha1($this->short(1)), $hosts);
        $this->attempt($failed, B2bDescriptionSupplementAttempt::STATUS_FAILED, sha1($this->short(2)), $hosts);
        $this->attempt($otherHosts, B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, sha1($this->short(3)), sha1('inne strony'));
        $this->attempt($otherSource, B2bDescriptionSupplementAttempt::STATUS_KEPT, sha1('poprzedni tekst u dostawcy'), $hosts);

        $this->assertSame(
            [(int) $failed->id, (int) $otherHosts->id, (int) $otherSource->id, (int) $fresh->id],
            $this->service()->candidateIds($this->account),
        );
        $this->assertCount(6, $this->service()->candidateIds($this->account, false));
    }

    public function test_queue_records_attempts_and_dispatches_jobs(): void
    {
        Queue::fake();
        $card = $this->card('Q-1', self::SHORT);
        $this->link($this->account, $card, 'q1', sha1(self::SHORT));
        $retry = $this->card('Q-2', $this->short(1));
        $this->link($this->account, $retry, 'q2', sha1($this->short(1)));
        $this->attempt($retry, B2bDescriptionSupplementAttempt::STATUS_FAILED, sha1('stary'), sha1('stare'), 2, 'model nie odpowiedział');
        $long = $this->card('Q-3', str_repeat('Długi opis rękawicy z B2B. ', 60));
        $this->link($this->account, $long, 'q3', sha1((string) $long->description));

        $result = $this->service()->queue($this->account);

        $this->assertSame(['candidates' => 2, 'queued' => 2], $result);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertSame(sha1(self::SHORT), $attempt->source_sha1);
        $this->assertSame($this->account->enrichmentHostsSha1(), $attempt->hosts_sha1);
        $this->assertSame(0, $attempt->attempts);
        $again = $this->attemptOf($retry);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $again->status);
        $this->assertSame(sha1($this->short(1)), $again->source_sha1);
        $this->assertSame($this->account->enrichmentHostsSha1(), $again->hosts_sha1);
        // licznik prób zostaje, komunikat poprzedniej próby znika
        $this->assertSame(2, $again->attempts);
        $this->assertNull($again->message);
        $this->assertNull(B2bDescriptionSupplementAttempt::query()->where('product_id', $long->id)->first());

        Queue::assertPushed(SupplementB2bDescriptionJob::class, 2);
        Queue::assertPushedOn(SupplementB2bDescriptionJob::QUEUE, SupplementB2bDescriptionJob::class);
        Queue::assertPushed(
            SupplementB2bDescriptionJob::class,
            fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id
                && $job->b2bAccountId === (int) $this->account->id
                && $job->uniqueId() === $card->id.':'.$this->account->id,
        );
    }

    public function test_queue_limited_to_given_cards_and_empty_without_hosts(): void
    {
        Queue::fake();
        $card = $this->card('Q-1', self::SHORT);
        $this->link($this->account, $card, 'q1', sha1(self::SHORT));
        $other = $this->card('Q-2', $this->short(1));
        $this->link($this->account, $other, 'q2', sha1($this->short(1)));

        $this->assertSame(['candidates' => 1, 'queued' => 1], $this->service()->queue($this->account, [(int) $other->id]));
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $other->id);
        Queue::assertNotPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id);

        $this->account->forceFill(['enrichment_sites' => null])->save();
        $this->assertSame(['candidates' => 0, 'queued' => 0], $this->service()->queue($this->account));
    }

    public function test_successful_sync_queues_untried_short_descriptions(): void
    {
        Queue::fake();
        $shop = new SupplementFakeConnector;
        $shop->description = self::SHORT;

        $result = app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $shop);

        $this->assertSame(1, $result['created']);
        $card = Product::query()->where('sku', 'SUP-1')->sole();
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $this->attemptOf($card)->status);
    }

    public function test_dry_run_and_account_without_hosts_queue_nothing(): void
    {
        Queue::fake();
        $shop = new SupplementFakeConnector;
        $shop->description = self::SHORT;

        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), dryRun: true, delayMs: 0, connector: $shop);
        Queue::assertNotPushed(SupplementB2bDescriptionJob::class);

        $this->account->forceFill(['enrichment_sites' => null])->save();
        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $shop);
        Queue::assertNotPushed(SupplementB2bDescriptionJob::class);
        $this->assertSame(0, B2bDescriptionSupplementAttempt::query()->count());
    }

    public function test_queue_error_does_not_fail_the_sync_run(): void
    {
        Queue::fake();
        Log::spy();
        $this->app->instance(B2bDescriptionSupplement::class, new class
        {
            /** @return array{candidates: int, queued: int} */
            public function queue(B2bAccount $account, ?array $productIds = null, bool $onlyUntried = true): array
            {
                throw new RuntimeException('baza niedostępna');
            }
        });
        $shop = new SupplementFakeConnector;
        $shop->description = self::SHORT;

        $result = app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $shop);

        $this->assertSame(1, $result['created']);
        $this->assertSame('ok', $this->account->fresh()->last_sync_status);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'uzupełnienia krótkich opisów')
                && $context['error'] === 'baza niedostępna',
        );
    }

    /** Inny krótki tekst z B2B dla każdej karty (odcisk opisu jest sha1 całego tekstu). */
    private function short(int $n): string
    {
        return $n === 0 ? self::SHORT : self::SHORT.' Wariant '.$n.'.';
    }

    private function service(): B2bDescriptionSupplement
    {
        return app(B2bDescriptionSupplement::class);
    }

    /**
     * @param  list<string>|null  $enrichmentSites
     */
    private function account(string $connector, ?array $enrichmentSites): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'konto-'.$connector,
            'password' => 'haslo',
            'sites' => [$connector.'.example'],
            'connector' => $connector,
            'enrichment_sites' => $enrichmentSites,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function card(string $sku, string $description, array $extra = []): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawica '.$sku,
            'manufacturer' => 'Testowy',
            'description' => $description,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            ...$extra,
        ]);

        return $product->refresh();
    }

    private function link(B2bAccount $account, Product $product, string $remoteId, ?string $descriptionHash, ?string $sourceHash = null, ?string $manufacturer = null): B2bProductLink
    {
        return B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => $remoteId,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'manufacturer' => $manufacturer,
            'description_hash' => $descriptionHash,
            'source_description_hash' => $sourceHash,
        ]);
    }

    private function attempt(Product $product, string $status, string $sourceSha1, string $hostsSha1, int $attempts = 1, ?string $message = null): void
    {
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $this->account->id,
            'source_sha1' => $sourceSha1,
            'hosts_sha1' => $hostsSha1,
            'status' => $status,
            'attempts' => $attempts,
            'message' => $message,
            'attempted_at' => now(),
        ]);
    }

    private function attemptOf(Product $product): B2bDescriptionSupplementAttempt
    {
        return B2bDescriptionSupplementAttempt::query()
            ->where('product_id', $product->id)
            ->where('b2b_account_id', $this->account->id)
            ->sole();
    }
}

/** Łącznik testowy bez sieci: jedna pozycja SUP-1, cena zakupu 8, katalogowa 10, opis z pola $description. */
final class SupplementFakeConnector implements B2bConnector
{
    public string $description = '';

    public static function key(): string
    {
        return 'supplementtest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'supplement.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
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
