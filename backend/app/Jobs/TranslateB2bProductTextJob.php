<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\B2b\B2bTextTranslator;
use App\Services\B2b\B2bTranslationRejected;
use App\Services\Enrichment\EnrichmentSlots;
use App\Support\B2bProductNameMatch;
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
 * Tłumaczenie na polski opisu (i nazwy nowej karty) zapisanych przez import B2B łącznika B2bForeignLanguageSource.
 *
 * Decyzja użytkownika 15.09.2026: opisy i nazwy nowych kart z importu mają być po polsku, bez przechowywania
 * oryginalnego opisu. Tłumaczymy wyłącznie tekst, który zapisał import i którego nikt nie ruszył: opis tylko
 * gdy sha1(opisu karty) === link.description_hash, nazwę tylko gdy nazwa karty === link.remote_name.
 *
 * - Slot z EnrichmentSlots (wspólny limit zapytań AI z Ustawień AI): import tysiąca kart zlecał tyle samo
 *   równoległych wywołań modelu — 15.09.2026 kończyło się HTTP 429. Brak slotu = ponowne zlecenie z opóźnieniem,
 *   bez zużycia próby (jak EnrichProductJob).
 * - Compare-and-set: model odpowiada nawet minutę, a w tym czasie karta może zostać poprawiona ręcznie, wzbogacona
 *   albo nadpisana kolejnym przebiegiem importu. Zapis w transakcji z blokadą karty i linku i tylko wtedy, gdy opis,
 *   nazwa i hashe są dokładnie takie jak przy starcie — inaczej tłumaczenie przepada, karta zostaje nietknięta.
 * - Niezmiennik: link.source_description_hash jest niepusty TYLKO gdy opis karty jest tłumaczeniem
 *   (sha1 tekstu źródła, z którego powstał), a link.description_hash = sha1(opisu na karcie) — dzięki temu import
 *   rozpozna, że opis nie był edytowany ręcznie, a ponowny przebieg z tym samym źródłem nie zleca tłumaczenia od nowa.
 * - Odrzucenie (23.09.2026): odcisk wysłanego tekstu na powiązaniu (translation_rejected_hash) — pending() nie zgłasza
 *   go ponownie, bo model z temperaturą 0 odpowiedziałby tak samo; nowy tekst u dostawcy ma inny odcisk. Karty
 *   z odrzuceniem wypisuje b2b:translate --rejected (do ręcznego tłumaczenia). Udane tłumaczenie czyści odcisk.
 * - Kontekst nazwy karty (23.09.2026): nazwa karty idzie do modelu jako słownictwo katalogu tylko wtedy, gdy nazywa
 *   ten sam wyrób co nazwa u dostawcy (B2bProductNameMatch) — karty z nazwą innego wyrobu psuły tłumaczenie.
 *
 * Unikalność do startu (ShouldBeUniqueUntilProcessing): kolejne przebiegi importu nie dublują czekającego joba,
 * a ponowne zlecenie z handle() przy braku slotu nie jest po cichu odrzucane przez blokadę tego samego joba
 * (przy ShouldBeUnique blokada trwa do końca handle()). Duplikat w trakcie pracy odrzuci compare-and-set.
 */
class TranslateB2bProductTextJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enrich';

    /** Kolumna products.name to VARCHAR(1000) — dłuższej nazwy nie zapisujemy (i nie ucinamy po cichu). */
    private const MAX_NAME_LENGTH = 1000;

    /** Odpowiedź modelu w logu odrzucenia — do oceny, czy odrzucenie było słuszne; dłuższa jest ucinana. */
    private const LOGGED_RESPONSE_CHARS = 4000;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 90, 180];

    // Klient AI potrafi czekać ~2 min na przeciążony model; do tego do 2 min czekania na slot.
    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $productId,
        public readonly int $b2bAccountId,
        /** Tłumaczyć też nazwę (tylko nowa karta łącznika B2bKeepsExistingNames). */
        public readonly bool $translateName = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(B2bTextTranslator $translator, EnrichmentSlots $slots): void
    {
        $product = Product::query()->find($this->productId);
        $link = $product !== null ? $this->findLink() : null;
        if ($product === null || $link === null) {
            return;
        }

        $start = $this->snapshot($product, $link);
        if ($start === null) {
            return;
        }

        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            // Limit z Ustawień AI obłożony — karta wraca do kolejki bez zużycia próby.
            self::dispatch($this->productId, $this->b2bAccountId, $this->translateName)
                ->delay(now()->addSeconds(10));
            $this->delete();

            return;
        }

        try {
            // Sama nazwa (opis edytowany ręcznie) — opis pusty, jego tłumaczenie ignorujemy.
            // nazwa karty w katalogu jako kontekst terminologiczny — tylko gdy nazywa ten sam wyrób (contextName)
            $translated = $translator->translate($start['description'] ?? '', $start['name'], self::contextName($product, $link));
        } catch (B2bTranslationRejected $e) {
            $this->reject($product, (int) $link->id, $start, $e->getMessage(), $e->modelResponse);

            return;
        } finally {
            $slot->release();
        }

        $description = $start['description'] !== null ? trim((string) ($translated['description'] ?? '')) : null;
        $name = $start['name'] !== null ? trim((string) ($translated['name'] ?? '')) : null;
        if ($description === '' || $name === '') {
            $this->reject($product, (int) $link->id, $start, 'puste tłumaczenie');

            return;
        }
        // Nazwa bez zmian zostałaby równa nazwie u dostawcy, więc pending() zgłaszałby ją przy każdym przebiegu. Sama
        // nazwa — odrzucenie (zapamiętane, na liście --rejected); z opisem — opis zapisujemy, nazwa wróci sama i wtedy
        // odpadnie tą samą drogą.
        if ($name !== null && $name === trim((string) $start['name'])) {
            if ($description === null) {
                $this->reject($product, (int) $link->id, $start, 'nazwa bez zmian po tłumaczeniu');

                return;
            }
            $name = null;
        }
        if ($name !== null && mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $this->reject($product, (int) $link->id, $start, 'nazwa po tłumaczeniu dłuższa niż '.self::MAX_NAME_LENGTH.' znaków');

            return;
        }

        $skipReason = $this->store((int) $link->id, $start, $description, $name);
        if ($skipReason !== null) {
            Log::info('Tłumaczenie tekstu B2B niezapisane — karta zmieniła się w trakcie', [
                'product_id' => $this->productId,
                'b2b_account_id' => $this->b2bAccountId,
                'sku' => $product->sku,
                'reason' => $skipReason,
            ]);

            return;
        }

        Log::info('Przetłumaczono tekst karty z importu B2B', [
            'product_id' => $this->productId,
            'sku' => $product->sku,
            'description' => $description !== null ? 'tak' : 'nie',
            'name' => $name !== null ? 'tak' : 'nie',
            'description_length' => $start['description'] !== null
                ? mb_strlen($start['description']).' → '.mb_strlen((string) $description)
                : null,
            'name_length' => $start['name'] !== null
                ? mb_strlen($start['name']).' → '.mb_strlen((string) $name)
                : null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Tłumaczenie tekstu B2B nie powiodło się', [
            'product_id' => $this->productId,
            'b2b_account_id' => $this->b2bAccountId,
            'error' => $e?->getMessage(),
        ]);
    }

    private function findLink(bool $lock = false): ?B2bProductLink
    {
        $query = B2bProductLink::query()
            ->where('b2b_account_id', $this->b2bAccountId)
            ->where('product_id', $this->productId)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Nazwa karty jako kontekst dla modelu — tylko gdy nazywa ten sam wyrób co nazwa u dostawcy
     * (B2bProductNameMatch). W innym razie null — lepiej bez kontekstu niż z fałszywym.
     */
    private static function contextName(Product $product, B2bProductLink $link): ?string
    {
        $cardName = (string) $product->name;
        // Nazwa karty równa nazwie u dostawcy to wciąż tekst źródła, nie polskie słownictwo katalogu — model brał ją
        // za gotową polską nazwę i oddawał nazwę bez tłumaczenia (23.09.2026: 180 z 202 nazw Bolle po naprawie).
        if (trim($cardName) === trim((string) $link->remote_name)) {
            return null;
        }

        return B2bProductNameMatch::sameProduct($cardName, $link->remote_name, (string) $product->sku) ? $cardName : null;
    }

    /**
     * Co na karcie wciąż jest tekstem źródła do przetłumaczenia: opis zapisany przez import i nietknięty od tamtej
     * pory, nazwa ze źródła (tylko gdy $withName). Karta z tłumaczeniem opisu (source_description_hash) — opisu nie,
     * nazwa ze źródła nadal tak.
     * Wspólne dla joba, importu (zaległe karty przy każdym przebiegu) i b2b:translate.
     *
     * @return array{description: bool, name: bool}
     */
    public static function pending(Product $product, B2bProductLink $link, bool $withName): array
    {
        $current = (string) ($product->description ?? '');
        $pending = [
            // opis przetłumaczony (source_description_hash) — gotowy; nazwa ze źródła niezależnie od opisu
            // (23.09.2026: 18 kart Bolle z 15.09 miało przetłumaczony opis, a nazwę wciąż po angielsku)
            'description' => $link->source_description_hash === null
                && trim($current) !== ''
                && $link->description_hash !== null
                && hash_equals($link->description_hash, sha1($current)),
            'name' => $withName
                && $link->remote_name !== null
                && trim($link->remote_name) !== ''
                && (string) $product->name === $link->remote_name,
        ];
        // Ten sam tekst już raz odrzucony — model (temperatura 0) odpowie tak samo; nowy tekst ma inny odcisk.
        if ($link->translation_rejected_hash !== null
            && hash_equals($link->translation_rejected_hash, self::rejectionKey($product, $pending))) {
            return ['description' => false, 'name' => false];
        }

        return $pending;
    }

    /**
     * Odcisk tekstu, który poszedłby do tłumaczenia (opis i nazwa wg pending) — klucz odrzucenia na powiązaniu.
     *
     * @param  array{description: bool, name: bool}  $pending
     */
    public static function rejectionKey(Product $product, array $pending): string
    {
        return sha1(json_encode([
            $pending['description'] ? (string) $product->description : null,
            $pending['name'] ? (string) $product->name : null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Co tłumaczyć i stan karty/linku, który musi przetrwać do zapisu. Null = nic do tłumaczenia.
     *
     * @return array{description: string|null, name: string|null, description_hash: string|null, remote_name: string|null, product_description: string|null, product_name: string, rejection_key: string}|null
     */
    private function snapshot(Product $product, B2bProductLink $link): ?array
    {
        $pending = self::pending($product, $link, $this->translateName);
        // klucz liczony przed filtrem okna „Producenci” — tak samo jak w pending() przy kolejnym przebiegu
        $rejectionKey = self::rejectionKey($product, $pending);
        // Opis producenta wyłączony w oknie „Producenci”: tłumaczenie opisu też jest zapisem opisu z tego cennika.
        // Nazwa nowej karty dalej się tłumaczy — to nie opis.
        if ($pending['description'] && ! app(B2bManufacturerRules::class)->descriptionAllowed($link, $product)) {
            $pending['description'] = false;
        }
        if (! $pending['description'] && ! $pending['name']) {
            return null;
        }

        return [
            'description' => $pending['description'] ? (string) $product->description : null,
            'name' => $pending['name'] ? (string) $product->name : null,
            'description_hash' => $link->description_hash,
            'remote_name' => $link->remote_name,
            'product_description' => $product->description,
            'product_name' => (string) $product->name,
            'rejection_key' => $rejectionKey,
        ];
    }

    /**
     * Compare-and-set. Zwraca powód pominięcia albo null, gdy zapisano.
     *
     * @param  array{description: string|null, name: string|null, description_hash: string|null, remote_name: string|null, product_description: string|null, product_name: string, rejection_key: string}  $start
     */
    private function store(int $linkId, array $start, ?string $description, ?string $name): ?string
    {
        return DB::transaction(function () use ($linkId, $start, $description, $name): ?string {
            $product = Product::query()->lockForUpdate()->find($this->productId);
            $link = B2bProductLink::query()->lockForUpdate()->find($linkId);
            if ($product === null || $link === null) {
                return 'karta albo powiązanie usunięte';
            }
            if ($start['description'] !== null && $link->source_description_hash !== null) {
                return 'opis już przetłumaczony';
            }
            if ($link->description_hash !== $start['description_hash']) {
                return 'import zapisał nowy opis';
            }
            if ($start['description'] !== null && $product->description !== $start['product_description']) {
                return 'opis karty zmieniony';
            }
            if ($start['name'] !== null
                && ((string) $product->name !== $start['product_name'] || $link->remote_name !== $start['remote_name'])) {
                return 'nazwa karty albo nazwa u dostawcy zmieniona';
            }

            if ($description !== null) {
                $product->description = $description;
                $link->description_hash = sha1($description);
                $link->source_description_hash = sha1((string) $start['description']);
            }
            if ($name !== null) {
                $product->name = $name;
            }
            $link->translation_rejected_hash = null;
            $link->translation_rejected_reason = null;
            $link->translation_rejected_at = null;
            // haki modelu przebudują search_blob i zlecą reindeks embeddingu
            $product->save();
            $link->save();

            return null;
        });
    }

    /**
     * Odrzucenie: wpis w logu (z odpowiedzią modelu, gdy jest) i odcisk tekstu na powiązaniu — kolejne przebiegi
     * nie zlecają tego samego tekstu, a karta trafia na listę do ręcznego tłumaczenia (b2b:translate --rejected).
     * Odcisk tylko gdy powiązanie wciąż wskazuje ten sam tekst (import mógł w międzyczasie zapisać nowy).
     *
     * @param  array{description: string|null, description_hash: string|null, rejection_key: string}  $start
     * @param  array<string, mixed>|null  $modelResponse
     */
    private function reject(Product $product, int $linkId, array $start, string $reason, ?array $modelResponse = null): void
    {
        Log::warning('Tłumaczenie tekstu B2B odrzucone', [
            'product_id' => $this->productId,
            'b2b_account_id' => $this->b2bAccountId,
            'sku' => $product->sku,
            'reason' => $reason,
            ...($modelResponse !== null ? ['model_response' => mb_substr(
                (string) json_encode($modelResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0,
                self::LOGGED_RESPONSE_CHARS,
            )] : []),
        ]);

        B2bProductLink::query()
            ->whereKey($linkId)
            // opis w wysłanym tekście — powiązanie musi go wciąż mieć nieprzetłumaczony; sama nazwa — bez tego warunku
            ->when($start['description'] !== null, static fn ($q) => $q->whereNull('source_description_hash'))
            ->where(fn ($q) => $start['description_hash'] === null
                ? $q->whereNull('description_hash')
                : $q->where('description_hash', $start['description_hash']))
            ->update([
                'translation_rejected_hash' => $start['rejection_key'],
                'translation_rejected_reason' => mb_substr($reason, 0, 500),
                'translation_rejected_at' => now(),
            ]);
    }
}
