<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ponowne pobranie zdjęcia, gdy źródło chwilowo odmówiło (products:retry-images).
 *
 * ansell.com stoi za Incapsulą: plik zdjęcia raz przychodzi, raz zamiast niego strona zapory — z ~500 prób
 * 112 odpadło, a w tej samej sekundzie R-065 przeszło, R-074 nie. Jina zdjęcia nie ratuje: czyta plik, ale go
 * nie oddaje (zrzut robi tylko ze stron HTML). Przebieg opisu zapisuje więc na karcie wybrane adresy, które
 * odpadły chwilowo, a polecenie z harmonogramu ponawia je co kilka godzin. Adresy wybrał już weryfikator zdjęć
 * przy przebiegu — tu nie ma nowego wyboru, tylko ponowienie tego samego pliku.
 */
final class ProductImageRetry
{
    public const PAYLOAD_KEY = 'image_retry';

    public const ERROR_NOTE = ' Ponowimy pobieranie automatycznie.';

    /** Co 3 h z harmonogramu — 8 prób to około doby; zapora, która nie puszcza przez dobę, nie jest chwilowa. */
    public const MAX_ATTEMPTS = 8;

    private const ERROR_PREFIX = 'Opis OK, nie udało się pobrać zdjęcia';

    /** Znacznik w komunikacie po ostatniej próbie — --from-trace nie dopisuje takiej karty drugi raz. */
    private const GAVE_UP = 'Ponawianie zakończone';

    /** Adres ze śladu przycięty do 300 znaków (EnrichmentAttemptLog) — przycięty nie jest już adresem pliku. */
    private const TRACE_URL_LIMIT = 300;

    private const MAX_URLS = 5;

    public function __construct(private readonly ProductImageDownloader $images) {}

    /**
     * @param  list<string>  $urls
     * @return array{urls: list<string>, attempts: int}
     */
    public static function fresh(array $urls): array
    {
        return [
            'urls' => array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS),
            'attempts' => 0,
        ];
    }

    /** Karty czekające na ponowienie. */
    public function pending(): Builder
    {
        return Product::query()
            ->whereNotNull('enrichment_payload->'.self::PAYLOAD_KEY)
            ->where('enrichment_status', Product::ENRICHMENT_DONE)
            ->orderBy('id');
    }

    /**
     * Karty sprzed tej zmiany: opis jest, zdjęcia nie ma, a ślad przebiegu mówi o chwilowej odmowie źródła.
     * Bez kart, dla których ponawianie już się skończyło.
     */
    public function legacyCandidates(): Builder
    {
        return Product::query()
            ->where('enrichment_status', Product::ENRICHMENT_DONE)
            ->where('enrichment_error', 'like', self::ERROR_PREFIX.'%')
            ->where('enrichment_error', 'not like', '%'.self::GAVE_UP.'%')
            ->whereNull('enrichment_payload->'.self::PAYLOAD_KEY)
            ->whereDoesntHave('images')
            ->orderBy('id');
    }

    /**
     * Adresy zdjęć z kroku „nie pobrano” w śladzie przebiegu — tylko gdy powodem była chwilowa odmowa.
     *
     * @return list<string>
     */
    public function urlsFromTrace(Product $product): array
    {
        $trace = is_array($product->enrichment_trace) ? $product->enrichment_trace : [];
        $urls = [];
        foreach ((array) ($trace['steps'] ?? []) as $step) {
            if (! is_array($step) || ($step['t'] ?? '') !== 'image') {
                continue;
            }
            $message = mb_strtolower((string) ($step['m'] ?? ''));
            if (! str_starts_with($message, 'nie pobrano:')
                || preg_match('~text/html|application/json|http (403|429|5\d\d)\b|redirects~u', $message) !== 1) {
                continue;
            }
            foreach ((array) ($step['urls'] ?? []) as $url) {
                if (is_string($url) && str_starts_with($url, 'http') && mb_strlen($url) < self::TRACE_URL_LIMIT
                    && ! ProductImageDownloader::isManufacturerSiteGraphicUrl($url)) {
                    $urls[] = $url;
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
    }

    /**
     * Zapisuje adresy ze śladu jako oczekujące na ponowienie.
     *
     * @param  list<string>  $urls
     */
    public function schedule(Product $product, array $urls): bool
    {
        return $this->writeIfUnchanged($product, static function (Product $card) use ($urls): void {
            $payload = is_array($card->enrichment_payload) ? $card->enrichment_payload : [];
            $payload[self::PAYLOAD_KEY] = self::fresh($urls);
            $card->enrichment_payload = $payload;
            $card->enrichment_error = self::withoutNote((string) $card->enrichment_error).self::ERROR_NOTE;
        });
    }

    /**
     * Jedna próba dla karty.
     *
     * @return 'saved'|'waiting'|'gave_up'|'skipped'
     */
    public function retry(Product $product): string
    {
        // Polecenie obsługuje karty po kolei przez kilkadziesiąt minut — karta mogła w tym czasie przejść nowy
        // przebieg opisu. Trwa (RUNNING): nie ruszamy, przebieg sam zapisze nowy payload.
        $product->refresh();
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        if ($product->enrichment_status !== Product::ENRICHMENT_DONE || ! is_array($payload[self::PAYLOAD_KEY] ?? null)) {
            return 'skipped';
        }
        $state = $payload[self::PAYLOAD_KEY];
        // Adresy zapisał przebieg sprzed poprawki wyboru (albo ślad w jego kolejności): #8564 ma beczki przed
        // packshotem, a pobierane jest jedno zdjęcie — pierwsze, które zapora przepuści. Grafika witryny
        // (widżet rozmiarów) nie jest zdjęciem wyrobu, nawet jeśli wtedy trafiła do kolejki.
        $urls = ProductImageDownloader::packshotsFirst(array_values(array_filter(
            (array) ($state['urls'] ?? []),
            static fn ($url): bool => is_string($url) && str_starts_with($url, 'http')
                && ! ProductImageDownloader::isManufacturerSiteGraphicUrl($url)
        )));

        // Zdjęcie przyszło inną drogą (galeria B2B, nowy przebieg) — nie ma czego ponawiać.
        if ($product->images()->exists()) {
            $this->finish($product, clearError: true);

            return 'skipped';
        }
        if ($urls === []) {
            $this->finish($product, clearError: false);

            return 'skipped';
        }

        $saved = $this->images->downloadMany($product, $urls, 1);
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        if ($saved !== []) {
            $this->finish($product, clearError: true);
            Log::info('Product image retry saved', [
                'product_id' => $product->id,
                'url' => $saved[0]->source_url,
                'attempt' => $attempts,
            ]);

            return 'saved';
        }

        $still = $this->images->lastRetryLaterUrls();
        Log::info('Product image retry failed', [
            'product_id' => $product->id,
            'attempt' => $attempts,
            'failures' => $this->images->lastFailures(),
        ]);
        if ($still === [] || $attempts >= self::MAX_ATTEMPTS) {
            // Ostatnia próba albo odmowa już nie chwilowa (404, usunięte z karty) — koniec ponawiania.
            $written = $this->writeIfUnchanged($product, static function (Product $card) use ($attempts): void {
                $payload = is_array($card->enrichment_payload) ? $card->enrichment_payload : [];
                unset($payload[self::PAYLOAD_KEY]);
                $card->enrichment_payload = $payload;
                $error = self::withoutNote((string) $card->enrichment_error);
                if (str_starts_with($error, self::ERROR_PREFIX)) {
                    $error .= ' '.self::GAVE_UP.' po '.$attempts.' '.($attempts === 1 ? 'próbie' : 'próbach').'.';
                }
                $card->enrichment_error = $error;
            });

            return $written ? 'gave_up' : 'skipped';
        }

        $written = $this->writeIfUnchanged($product, static function (Product $card) use ($still, $attempts): void {
            $payload = is_array($card->enrichment_payload) ? $card->enrichment_payload : [];
            $payload[self::PAYLOAD_KEY] = [
                'urls' => $still,
                'attempts' => $attempts,
                'last_at' => now()->toIso8601String(),
            ];
            $card->enrichment_payload = $payload;
        });

        return $written ? 'waiting' : 'skipped';
    }

    private function finish(Product $product, bool $clearError): void
    {
        $this->writeIfUnchanged($product, static function (Product $card) use ($clearError): void {
            $payload = is_array($card->enrichment_payload) ? $card->enrichment_payload : [];
            unset($payload[self::PAYLOAD_KEY]);
            $card->enrichment_payload = $payload;
            $error = (string) $card->enrichment_error;
            $card->enrichment_error = $clearError && str_starts_with($error, self::ERROR_PREFIX)
                ? null
                // bez adresów nic nie ponowimy — obietnica w komunikacie znika razem z kluczem
                : ($card->enrichment_error === null ? null : self::withoutNote($error));
        });
    }

    /**
     * Zapis stanu ponawiania na świeżo odczytanej, zablokowanej karcie — i tylko gdy od odczytu $product nie przeszła
     * nowego przebiegu opisu (ten sam enriched_at, nadal DONE). Inaczej stary payload nadpisałby nowy: normy, źródła,
     * nowy image_retry.
     *
     * saveQuietly: zmiana enrichment_payload budzi przebudowę bloba i nowy wektor w Qdrant (Product::booted),
     * a klucz image_retry do żadnego z nich nie wchodzi — każda próba liczyłaby embedding tej samej treści od nowa.
     *
     * @param  callable(Product): void  $mutate
     */
    private function writeIfUnchanged(Product $product, callable $mutate): bool
    {
        $enrichedAt = $product->getRawOriginal('enriched_at');

        return DB::transaction(static function () use ($product, $enrichedAt, $mutate): bool {
            $card = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if ($card === null
                || $card->enrichment_status !== Product::ENRICHMENT_DONE
                || $card->getRawOriginal('enriched_at') !== $enrichedAt) {
                return false;
            }
            $mutate($card);
            $card->saveQuietly();
            $product->setRawAttributes($card->getAttributes(), true);

            return true;
        });
    }

    private static function withoutNote(string $error): string
    {
        return str_ends_with($error, self::ERROR_NOTE)
            ? mb_substr($error, 0, mb_strlen($error) - mb_strlen(self::ERROR_NOTE))
            : $error;
    }
}
