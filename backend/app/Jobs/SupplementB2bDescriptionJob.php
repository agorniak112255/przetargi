<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\B2bSupplementNoPages;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uzupełnienie krótkiego opisu B2B ze stron konta (decyzja użytkownika 28.09.2026; kwalifikacja karty:
 * B2bDescriptionSupplement::context). Model pisze opis z tekstu B2B i stron znalezionych najpierw na hostach konta
 * (ProductEnrichmentService::supplementB2bDescription); nic lepszego → karta zostaje z opisem z B2B, a próba
 * (b2b_description_supplement_attempts) mówi, dlaczego.
 *
 * Hashe powiązań konta jak przy tłumaczeniu i opisie z karty katalogowej: description_hash = sha1(nowego opisu),
 * source_description_hash = odcisk tekstu źródła, z którego opis powstał (context->sourceSha1: source_description_hash
 * tłumaczenia albo description_hash tekstu z B2B). Dzięki temu:
 * - synchronizacja z tym samym tekstem u dostawcy zostawia opis (B2bCatalogSync::keepsTranslation — także witryna
 *   producenta, która poza tym zastępuje każdy opis karty);
 * - nowy tekst u dostawcy wraca na kartę (uzupełniony opis w enrichment_payload.replaced_description i w śladzie
 *   b2b_supplement), a karta znowu czeka na uzupełnienie — z nowym odciskiem źródła;
 * - opis wciąż liczy się jako „z B2B” (B2bDescriptionSource) — zbiorcze uzupełnianie AI kartę omija.
 * enrichment_status i kolumna norm bez zmian; bez pamięci AI po SKU, zdjęć, dokumentów i akcesoriów.
 *
 * Slot z EnrichmentSlots i compare-and-set jak w DescribeB2bProductFromDatasheetJob: model i strony to nawet kilka
 * minut, w tym czasie kartę mógł zmienić import albo człowiek — wtedy wynik przepada, karta zostaje nietknięta.
 */
class SupplementB2bDescriptionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enrich';

    public const NOT_ELIGIBLE_MESSAGE = 'karta już się nie kwalifikuje';

    /**
     * Wersja bramki stron zapisana w śladzie (b2b_supplement.variant_gate). 1 = strona z internetu musi nieść kod
     * wariantu karty (28.09.2026, ProductEnrichmentService::supplementPageNamesCardVariant). Opis bez tego znacznika
     * powstał przed bramką i b2b:supplement-descriptions --undo-ungated go cofa.
     */
    public const VARIANT_GATE = 1;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60, 180];

    // wyszukiwanie na hostach konta, pobranie stron i model — jak zwykłe wzbogacanie (EnrichProductJob)
    public int $timeout = 420;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $productId,
        public readonly int $b2bAccountId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->productId.':'.$this->b2bAccountId;
    }

    public function handle(B2bDescriptionSupplement $supplement, EnrichmentSlots $slots): void
    {
        $product = Product::query()->find($this->productId);
        $account = B2bAccount::query()->find($this->b2bAccountId);
        if ($product === null || $account === null) {
            // próba znika razem z kartą albo kontem (klucze obce)
            return;
        }
        $context = $supplement->context($product, $account);
        if ($context === null) {
            // Nie wynik ostateczny: powód bywa chwilowy (wzbogacanie w toku, opis producenta wyłączony na chwilę
            // w oknie „Producenci”) — kolejne zlecenie sprawdzi kartę od nowa. Bez liczenia próby.
            $this->recordAttempt(B2bDescriptionSupplementAttempt::STATUS_FAILED, null, self::NOT_ELIGIBLE_MESSAGE, countAttempt: false);

            return;
        }

        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            // Limit z Ustawień AI obłożony — karta wraca do kolejki bez zużycia próby.
            self::dispatch($this->productId, $this->b2bAccountId)->delay(now()->addSeconds(10));
            $this->delete();

            return;
        }

        try {
            // Z kontenera przy każdym wywołaniu — usługa jest final, testy podmieniają ją w kontenerze.
            /** @var ProductEnrichmentService $enrichment */
            $enrichment = app(ProductEnrichmentService::class);
            $result = $enrichment->supplementB2bDescription($product, $context);
        } catch (B2bSupplementNoPages $e) {
            $this->recordAttempt(B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, $context, $e->getMessage());

            return;
        } catch (B2bSourcesDescriptionRejected $e) {
            Log::info('Uzupełnienie opisu B2B odrzucone — karta zostaje z opisem z B2B', [
                'product_id' => $this->productId,
                'b2b_account_id' => $this->b2bAccountId,
                'sku' => $product->sku,
                'reason' => $e->getMessage(),
            ]);
            $this->recordAttempt(B2bDescriptionSupplementAttempt::STATUS_KEPT, $context, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->recordAttempt(B2bDescriptionSupplementAttempt::STATUS_FAILED, $context, $e->getMessage());

            throw $e;
        } finally {
            $slot->release();
        }

        $skipReason = $this->store($context, $result);
        if ($skipReason === null) {
            $this->recordAttempt(
                B2bDescriptionSupplementAttempt::STATUS_REPLACED,
                $context,
                null,
                resultSha1: sha1($result['description']),
                sourceUrls: array_values(array_filter(array_map('strval', (array) ($result['payload']['source_urls'] ?? [])))),
            );
        } else {
            // do ponowienia: kolejne zlecenie sprawdzi kartę od nowa (context), a zmieniony tekst źródła ma nowy odcisk
            $this->recordAttempt(B2bDescriptionSupplementAttempt::STATUS_FAILED, $context, 'wynik niezapisany — '.$skipReason);
        }
        Log::info($skipReason === null
            ? 'Krótki opis B2B uzupełniony ze stron konta'
            : 'Uzupełnienie opisu B2B niezapisane — karta zmieniła się w trakcie', [
                'product_id' => $this->productId,
                'b2b_account_id' => $this->b2bAccountId,
                'sku' => $product->sku,
                'reason' => $skipReason,
                'web_source_urls' => $result['web_source_urls'],
                'dropped_levels' => $result['dropped'],
                'dropped_claims' => $result['dropped_claims'],
            ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Uzupełnienie opisu B2B nie powiodło się', [
            'product_id' => $this->productId,
            'b2b_account_id' => $this->b2bAccountId,
            'error' => $e?->getMessage(),
        ]);
        // job zabity (limit czasu) nie przechodzi przez catch w handle — próba nie może zostać „w kolejce” na zawsze
        B2bDescriptionSupplementAttempt::query()
            ->where('product_id', $this->productId)
            ->where('b2b_account_id', $this->b2bAccountId)
            ->where('status', B2bDescriptionSupplementAttempt::STATUS_QUEUED)
            ->update([
                'status' => B2bDescriptionSupplementAttempt::STATUS_FAILED,
                'message' => mb_substr((string) ($e?->getMessage() ?? 'błąd joba'), 0, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * Compare-and-set. Zwraca powód pominięcia albo null, gdy zapisano.
     *
     * @param  array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}  $result
     */
    private function store(B2bSupplementContext $context, array $result): ?string
    {
        return DB::transaction(function () use ($context, $result): ?string {
            $product = Product::query()->lockForUpdate()->find($this->productId);
            $links = B2bProductLink::query()
                ->where('b2b_account_id', $this->b2bAccountId)
                ->where('product_id', $this->productId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($product === null || $links->isEmpty()) {
                return 'karta albo powiązanie usunięte';
            }
            $linkIds = $links->map(static fn (B2bProductLink $link): int => (int) $link->id)->all();
            if ($linkIds !== $context->linkIds) {
                return 'powiązania konta z kartą zmienione';
            }
            foreach ($links as $link) {
                if ($link->description_hash !== $context->descriptionHash
                    || $link->source_description_hash !== $context->sourceDescriptionHash) {
                    return 'import zapisał nowy opis';
                }
            }
            if ((string) $product->description !== $context->productDescription) {
                return 'opis karty zmieniony';
            }

            $description = $result['description'];
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            // stan sprzed uzupełnienia (bez atrybutów — liczone z karty) — B2bDescriptionSupplement::undoUngated przywraca go 1:1
            $previousPayload = $payload;
            unset($previousPayload['attributes'], $previousPayload['b2b_supplement']);
            // atrybuty liczone od nowa z karty po zmianie — stare mogły nieść cechy spoza nowych źródeł
            unset($payload['attributes']);
            $payload = [
                ...$payload,
                ...$result['payload'],
                'b2b_supplement' => [
                    'b2b_account_id' => $this->b2bAccountId,
                    'b2b_text' => mb_substr($context->b2bText, 0, 10000),
                    'source_sha1' => $context->sourceSha1,
                    'hosts' => $context->hosts,
                    'web_source_urls' => $result['web_source_urls'],
                    'dropped' => $result['dropped'],
                    'dropped_claims' => $result['dropped_claims'],
                    'described_at' => now()->toIso8601String(),
                    'result_sha1' => sha1($description),
                    'variant_gate' => self::VARIANT_GATE,
                    'previous_payload' => $previousPayload,
                ],
            ];
            // Jeden poziom historii: tekst z B2B zostaje w śladzie b2b_supplement, a replaced_description tylko wtedy,
            // gdy to miejsce jest wolne (stoi tam opis sprzed tekstu z B2B, którego nie da się odtworzyć ze sklepu).
            if (trim((string) ($payload['replaced_description'] ?? '')) === '') {
                $payload['replaced_description'] = mb_substr($context->productDescription, 0, 10000);
                $payload['replaced_description_at'] = now()->toIso8601String();
                $payload['replaced_description_hash'] = sha1($description);
            }
            $product->description = $description;
            $product->enrichment_payload = $payload;
            $payload['attributes'] = app(BhpAttributeNormalizer::class)->forProduct($product);
            $product->enrichment_payload = $payload;
            // haki modelu przebudują search_blob i zlecą reindeks embeddingu
            $product->save();
            foreach ($links as $link) {
                $link->description_hash = sha1($description);
                $link->source_description_hash = $context->sourceSha1;
                $link->save();
            }

            return null;
        });
    }

    /**
     * Stan próby dla pary karta+konto. $context = null (karta już się nie kwalifikuje) — odcisk wejścia bez zmian.
     *
     * @param  list<string>|null  $sourceUrls
     */
    private function recordAttempt(
        string $status,
        ?B2bSupplementContext $context,
        ?string $message,
        bool $countAttempt = true,
        ?string $resultSha1 = null,
        ?array $sourceUrls = null,
    ): void {
        DB::transaction(function () use ($status, $context, $message, $countAttempt, $resultSha1, $sourceUrls): void {
            $attempt = B2bDescriptionSupplementAttempt::query()
                ->where('product_id', $this->productId)
                ->where('b2b_account_id', $this->b2bAccountId)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                if ($context === null) {
                    // job bez próby (zlecony poza queue()) i karta bez kwalifikacji — nie ma czego zapisać
                    return;
                }
                $attempt = new B2bDescriptionSupplementAttempt([
                    'product_id' => $this->productId,
                    'b2b_account_id' => $this->b2bAccountId,
                    'attempts' => 0,
                ]);
            }
            if ($context !== null) {
                $attempt->source_sha1 = $context->sourceSha1;
                $attempt->hosts_sha1 = $context->hostsSha1;
            }
            $attempt->status = $status;
            $attempt->message = $message !== null ? mb_substr($message, 0, 500) : null;
            if ($countAttempt) {
                $attempt->attempts = (int) $attempt->attempts + 1;
            }
            if ($status === B2bDescriptionSupplementAttempt::STATUS_REPLACED) {
                $attempt->result_sha1 = $resultSha1;
                $attempt->source_urls = $sourceUrls;
            } elseif ($context !== null) {
                // źródła i wynik należą do tej próby — bez przeniesienia z poprzedniej
                $attempt->result_sha1 = null;
                $attempt->source_urls = null;
            }
            $attempt->attempted_at = now();
            $attempt->save();
        });
    }
}
