<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\ProductSizeMergeService;
use App\Support\CanonicalBrand;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Decyzja człowieka na ekranie „Łączenie kart” (plan łączenia kart, etap C): połączenie propozycji albo odrzucenie.
 *
 * Połączenie = ProductSizeMergeService::mergeDuplicate(zostaje: karta producenta, znika: karta dystrybutora) — nazwa,
 * opis i SKU karty producenta zostają, sloty cen, powiązania B2B, tabelki, zdjęcia, identyfikatory i historia cen
 * przechodzą. Przed połączeniem: ponowna weryfikacja kluczem (CardMatchFinder::evaluate — między odświeżeniem
 * a kliknięciem dystrybutor mógł zmienić kod, a karta producenta stracić właściciela), strażnicy jak
 * w products:merge-duplicate i pełna kopia zapasowa JSON wierszy, które scalenie przenosi albo kasuje (slot ceny tego
 * samego źródła na obu kartach — zostaje nowszy, starszy znika). Wszystko w jednej transakcji: przy błędzie nic się
 * nie zmienia, propozycja zostaje w swoim statusie, wyjątek niesie powód po polsku.
 */
final class CardMatchMerger
{
    /**
     * tabela => kolumna karty — wiersze obu kart, które mergeDuplicate przenosi albo kasuje (kopia zapasowa); te same
     * tabele w kopii łączenia rozmiarów (CardMatchSizeMerger)
     */
    public const BACKUP_TABLES = [
        'b2b_product_links' => 'product_id',
        'product_source_prices' => 'product_id',
        'product_shop_cards' => 'product_id',
        'product_images' => 'product_id',
        'product_documents' => 'product_id',
        'product_price_history' => 'product_id',
        'product_identifiers' => 'product_id',
        'product_image_rejections' => 'product_id',
        'presta_product_matches' => 'product_id',
        'tender_items' => 'main_product_id',
        // mapa połączeń: wiersze karty dystrybutora przechodzą na kartę producenta (CardRedirectStore::repoint)
        'card_redirects' => 'product_id',
    ];

    /** tabela => opis — dane duplikatu, których mergeDuplicate nie przenosi; kaskada skasowałaby je razem z kartą */
    private const BLOCKING = [
        'product_variants' => 'wersje',
        'product_special_prices' => 'ceny specjalne',
        'product_accessories' => 'akcesoria',
    ];

    /**
     * CardMatchFinder z kontenera przy użyciu, nie w konstruktorze: klasa jest final (atrapa w testach przez
     * $app->instance), a połączenie nie może zależeć od jej reguł w testach tej klasy.
     */
    public function __construct(
        private readonly ProductSizeMergeService $sizeMerge,
        private readonly CardOwnership $ownership,
        private readonly Container $container,
        private readonly CardRedirectStore $redirects,
    ) {}

    /**
     * @throws DomainException z powodem po polsku, gdy połączyć nie wolno (nic nie zmienione)
     */
    public function merge(CardMatchCandidate $candidate, User $user): CardMatchCandidate
    {
        try {
            DB::transaction(function () use ($candidate, $user): void {
                $locked = $this->lock($candidate);
                $this->refuseOtherKinds($locked);
                if ($locked->status !== CardMatchCandidate::STATUS_PENDING) {
                    throw new DomainException('Propozycja #'.$locked->id.' ma status „'.$locked->status
                        .'” — połączyć można tylko propozycję do decyzji.');
                }
                $source = Product::query()->find($locked->source_product_id);
                $target = $locked->target_product_id !== null ? Product::query()->find($locked->target_product_id) : null;
                if ($source === null) {
                    throw new DomainException('Karta dystrybutora #'.$locked->source_product_id.' już nie istnieje — odśwież propozycje.');
                }
                if ($target === null) {
                    throw new DomainException('Karta producenta #'.($locked->target_product_id ?? '—').' już nie istnieje — odśwież propozycje.');
                }
                // strażnicy i ponowna weryfikacja przed kopią zapasową — odmowa nie zostawia pliku kopii
                $this->guard($source, $target);
                $this->verifyPair($source, (int) $locked->target_product_id);

                $backupPath = $this->writeBackup($locked, $source, $target, $user);
                $snapshot = [
                    'sku' => (string) $source->sku,
                    'name' => (string) $source->name,
                    'manufacturer' => (string) $source->manufacturer,
                ];
                $this->attach($locked, $source, $target, $user, true);

                $locked->forceFill([
                    'status' => CardMatchCandidate::STATUS_MERGED,
                    'source_snapshot' => $snapshot,
                    'backup_path' => $backupPath,
                    'decided_by' => $user->id,
                    'decided_at' => now(),
                ])->save();
                // inne propozycje tej karty dystrybutora wskazują kartę, której już nie ma — odświeżenie i tak by je
                // usunęło; odrzucone i połączone zostają jako ślad decyzji
                CardMatchCandidate::query()
                    ->where('source_product_id', $locked->source_product_id)
                    ->where('id', '!=', $locked->id)
                    ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
                    ->delete();
            });
        } catch (JsonException $e) {
            throw new DomainException('Kopia zapasowa nie powstała: '.$e->getMessage().' — nic nie połączono.', 0, $e);
        }

        return $candidate->refresh();
    }

    /**
     * Dołączenie karty dystrybutora do karty producenta w transakcji wywołującego: strażnicy, ponowna weryfikacja
     * (verifyPair), mapa połączeń (reason merge, $candidate), scalenie (mergeDuplicate), kolejność zdjęć (gdy
     * $orderImages) i kategoria. Bez kopii zapasowej i bez zmiany statusu propozycji — to robi wywołujący. Łączenie
     * rozmiarów (CardMatchSizeMerger) dołącza tak kartę P4S 6X00 do karty modelu 3M po połączeniu jej rozmiarów,
     * a kolejność zdjęć ustawia samo (zdjęcia kart rozmiarów na końcu).
     *
     * @throws DomainException z powodem po polsku (wywołujący wycofuje transakcję)
     */
    public function attachWithin(CardMatchCandidate $candidate, Product $source, Product $target, User $user, bool $orderImages = true): void
    {
        $this->guard($source, $target);
        $this->verifyPair($source, (int) $target->id);
        $this->attach($candidate, $source, $target, $user, $orderImages);
    }

    /**
     * Mapa połączeń przed scaleniem (powiązania i identyfikatory są jeszcze na karcie dystrybutora), scalenie,
     * kolejność zdjęć i kategoria — po strażnikach i weryfikacji.
     */
    private function attach(CardMatchCandidate $candidate, Product $source, Product $target, User $user, bool $orderImages): void
    {
        $sourceCategory = trim((string) $source->category);
        $targetImages = $orderImages
            ? ProductImage::query()
                ->where('product_id', $target->id)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        $this->redirects->recordMerge($source, $target, CardRedirect::REASON_MERGE, $candidate, $user);
        $this->sizeMerge->mergeDuplicate($target, $source);

        if ($orderImages) {
            $this->keepTargetImageOrder((int) $target->id, $targetImages);
        }
        $target->refresh();
        // jak products:merge-duplicate: pusta kategoria karty producenta bierze kategorię duplikatu
        if (trim((string) $target->category) === '' && $sourceCategory !== '') {
            $target->category = $sourceCategory;
            // zwykły save(): hak modelu przelicza indeks tekstowy i zleca reindeks wektora
            $target->save();
        }
    }

    /**
     * Odrzucona para nie wraca przy odświeżeniu (CardMatchFinder nie rusza statusu rejected). Notatka człowieka idzie
     * do reason (ekran pokazuje go przy odrzuconych); powód niepewnej propozycji zostaje przed nią. Pusta notatka —
     * reason bez zmian.
     *
     * @throws DomainException
     */
    public function reject(CardMatchCandidate $candidate, User $user, ?string $note = null): CardMatchCandidate
    {
        DB::transaction(function () use ($candidate, $user, $note): void {
            $locked = $this->lock($candidate);
            if (! in_array($locked->status, [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT], true)) {
                throw new DomainException('Propozycja #'.$locked->id.' ma już status „'.$locked->status.'”.');
            }
            $note = trim((string) $note);
            $reason = trim((string) $locked->reason);
            if ($note !== '') {
                // notatka człowieka cała, skrócony raczej powód niepewnej propozycji (kolumna ma 500 znaków)
                $note = mb_substr($note, 0, 500);
                $room = 500 - mb_strlen($note) - 3;
                $reason = $reason !== '' && $room > 0 ? mb_substr($reason, 0, $room).' · '.$note : $note;
            }
            $locked->forceFill([
                'status' => CardMatchCandidate::STATUS_REJECTED,
                'reason' => $reason !== '' ? $reason : null,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();
        });

        return $candidate->refresh();
    }

    /**
     * Wiersz propozycji zablokowany do końca transakcji — dwa kliknięcia naraz nie połączą pary dwa razy. Propozycja
     * mogła zniknąć: połączenie innej pary tej samej karty dystrybutora albo odświeżenie ją usuwa.
     */
    private function lock(CardMatchCandidate $candidate): CardMatchCandidate
    {
        $locked = CardMatchCandidate::query()->lockForUpdate()->find($candidate->id);
        if (! $locked instanceof CardMatchCandidate) {
            throw new DomainException('Propozycja #'.$candidate->id.' już nie istnieje — odśwież listę.');
        }

        return $locked;
    }

    /**
     * „Połącz” łączy tylko parę karta dystrybutora → jedna karta producenta (kind=merge). Łączenie rozmiarów
     * i rozdzielanie (plan „pozycja → karta”) mają własne akcje (CardMatchSizeMerger, CardMatchSplitter) — mergeDuplicate
     * z target_product_id=null i tak by nie zadziałało, ale powód ma mówić, co zrobić.
     */
    private function refuseOtherKinds(CardMatchCandidate $locked): void
    {
        $kind = (string) ($locked->kind ?? CardMatchCandidate::KIND_MERGE);
        if ($kind === CardMatchCandidate::KIND_MERGE) {
            return;
        }
        throw new DomainException(match ($kind) {
            CardMatchCandidate::KIND_SIZE_MERGE => 'Ta propozycja to łączenie rozmiarów — połącz ją przyciskiem „Połącz rozmiary” na zakładce „Łączenie rozmiarów” albo odrzuć.',
            CardMatchCandidate::KIND_SPLIT => 'Ta propozycja to rozdzielanie — rozdziel ją przyciskiem „Rozdziel” na zakładce „Rozdzielanie” albo odrzuć.',
            default => 'Ta propozycja ma nieznany rodzaj („'.$kind.'”) — nie da się jej połączyć. Możesz ją odrzucić.',
        });
    }

    /**
     * Strażnicy niezależni od reguł dopasowania — to, czego scalenie nie umie przenieść albo po czym coś by zginęło.
     */
    private function guard(Product $source, Product $target): void
    {
        if ((int) $source->id === (int) $target->id) {
            throw new DomainException('Karta nie może być połączona sama ze sobą.');
        }
        if (! CanonicalBrand::same($source->manufacturer, $target->manufacturer)) {
            throw new DomainException('Inna marka producenta („'.$source->manufacturer.'” / „'.$target->manufacturer.'”).');
        }
        foreach (self::BLOCKING as $table => $label) {
            $count = DB::table($table)->where('product_id', $source->id)->count();
            if ($count > 0) {
                throw new DomainException("Karta dystrybutora ma {$label} ({$count}) — połączenie ich nie przenosi.");
            }
        }
        $fileSlots = ProductSourcePrice::query()
            ->whereIn('product_id', [$source->id, $target->id])
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->count();
        if ($fileSlots > 1) {
            throw new DomainException('Obie karty mają cenę z pliku — starsza by zginęła.');
        }
        if (! $this->ownership->isProtected($target)) {
            throw new DomainException('Karta #'.$target->id.' nie ma już właściciela (konta B2B ani cennika producenta) — odśwież propozycje.');
        }
    }

    /** Ponowna weryfikacja kluczem: ta sama karta producenta ($expectedTargetId) i pewna para (nie konflikt). */
    private function verifyPair(Product $source, int $expectedTargetId): void
    {
        $result = $this->container->make(CardMatchFinder::class)->evaluate($source, true);
        if ($result === null) {
            throw new DomainException('Karta dystrybutora nie ma już wspólnego klucza z kartą producenta — odśwież propozycje.');
        }
        // klucze karty wskazują dziś kilka kart producenta (łączenie rozmiarów albo rozdzielanie) — to inna decyzja
        $kind = (string) ($result['kind'] ?? CardMatchCandidate::KIND_MERGE);
        if ($kind !== CardMatchCandidate::KIND_MERGE) {
            throw new DomainException('Propozycja zmieniła rodzaj — klucze karty dystrybutora wskazują teraz kilka kart producenta ('
                .($kind === CardMatchCandidate::KIND_SIZE_MERGE ? 'łączenie rozmiarów' : 'rozdzielanie').'). Odśwież propozycje.');
        }
        $status = (string) ($result['status'] ?? '');
        $targetId = isset($result['target_product_id']) ? (int) $result['target_product_id'] : null;
        if ($status !== CardMatchCandidate::STATUS_PENDING) {
            $reason = trim((string) ($result['reason'] ?? ''));

            throw new DomainException('Ponowne sprawdzenie dało propozycję niepewną'.($reason !== '' ? ': '.$reason : '').'.');
        }
        if ($targetId !== $expectedTargetId) {
            throw new DomainException('Klucz wskazuje teraz inną kartę producenta (#'.($targetId ?? '—').') — odśwież propozycje.');
        }
    }

    /**
     * Zdjęcie główne karty producenta zostaje główne: moveMedia przenosi zdjęcia duplikatu z ich is_primary
     * i sort_order, więc karta miałaby dwa zdjęcia główne. Kolejność: zdjęcia karty producenta (jak były), potem
     * przeniesione. Karta bez zdjęć bierze zdjęcia duplikatu w ich kolejności. Zapis tylko wierszy, które się zmieniają.
     *
     * @param  list<int>  $targetImageIds  zdjęcia karty producenta sprzed połączenia, główne pierwsze
     */
    private function keepTargetImageOrder(int $targetId, array $targetImageIds): void
    {
        $images = ProductImage::query()
            ->where('product_id', $targetId)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $ordered = [];
        foreach ($targetImageIds as $id) {
            if ($images->has($id)) {
                $ordered[] = $images->get($id);
                $images->forget($id);
            }
        }
        array_push($ordered, ...$images->values()->all());

        foreach ($ordered as $position => $image) {
            $primary = $position === 0;
            if ((int) $image->sort_order !== $position || (bool) $image->is_primary !== $primary) {
                $image->forceFill(['sort_order' => $position, 'is_primary' => $primary])->save();
            }
        }
    }

    /**
     * Pełna kopia zapasowa przed połączeniem: obie karty i wszystkie wiersze, które scalenie przenosi albo kasuje
     * (także karty producenta — jej slot ceny, tabelka czy zamiennik tego samego źródła może zniknąć), cenniki
     * z kartą dystrybutora na liście i zamienniki obu kart.
     *
     * @throws JsonException
     */
    private function writeBackup(CardMatchCandidate $candidate, Product $source, Product $target, User $user): string
    {
        $cards = [];
        foreach (['target' => $target, 'source' => $source] as $role => $product) {
            $rows = [];
            foreach (self::BACKUP_TABLES as $table => $column) {
                // pozycje przetargów karty producenta nie zmieniają się — tylko duplikatu
                if ($table === 'tender_items' && $role === 'target') {
                    continue;
                }
                if (Schema::hasTable($table)) {
                    $rows[$table] = DB::table($table)->where($column, $product->id)->orderBy('id')->get()
                        ->map(static fn (object $row): array => (array) $row)->all();
                }
            }
            $cards[$role] = [
                'product' => (array) DB::table('products')->where('id', $product->id)->first(),
                'rows' => $rows,
            ];
        }

        $ids = [(int) $source->id, (int) $target->id];
        $substitutes = Schema::hasTable('product_substitutes')
            ? DB::table('product_substitutes')
                ->where(static fn ($q) => $q->whereIn('main_product_id', $ids)->orWhereIn('substitute_product_id', $ids))
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all()
            : [];
        $priceLists = [];
        if (Schema::hasTable('price_lists')) {
            foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
                $productIds = is_array($list->product_ids) ? array_map('intval', $list->product_ids) : [];
                if (in_array((int) $source->id, $productIds, true)) {
                    $priceLists[] = ['id' => (int) $list->id, 'product_ids' => $list->product_ids];
                }
            }
        }

        $payload = [
            'kind' => 'card-match-merge',
            'created_at' => now()->toIso8601String(),
            'user' => ['id' => (int) $user->id, 'name' => (string) $user->name],
            'candidate' => $candidate->getAttributes(),
            'keep_product_id' => (int) $target->id,
            'drop_product_id' => (int) $source->id,
            'cards' => $cards,
            'product_substitutes' => $substitutes,
            'price_lists' => $priceLists,
        ];

        $dir = storage_path('app/repair-backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new DomainException('Kopia zapasowa nie powstała: brak katalogu '.$dir.' — nic nie połączono.');
        }
        $path = $dir.DIRECTORY_SEPARATOR.'card-match-'.$candidate->id.'-'.now()->format('Ymd-His').'.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new DomainException('Kopia zapasowa nie powstała: zapis '.$path.' się nie udał — nic nie połączono.');
        }

        return $path;
    }
}
