<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\B2b\B2bDescriptionSource;
use App\Support\CertificateLabels;
use App\Support\ProductNormsColumn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wersje opisu karty (etap 1 opisów z cenników, 08.10.2026). Automat zapisuje opis zawsze, chyba że nowy przebieg
 * dał opis gorszy od bieżącego — wtedy zostaje propozycja do przeglądu (decyzja właściciela 07.10.2026).
 *
 * Baza porównania = wersja published, której description_sha1 = sha1 bieżącego opisu karty. Zapis opisu innym torem
 * (synchronizacja B2B, reset, edycja ręczna) zmienia skrót i baza wygasa sama — karta nie jest wtedy chroniona.
 * Stare opisy dostają bazę komendą products:baseline-versions.
 *
 * superseded powstaje tylko z published (nowa published zastępuje poprzednią), więc zawsze oznacza opis, który był
 * na karcie — na nim opiera się powrót do poprzedniego opisu po odrzuceniu.
 *
 * Dane techniczne wersji (tylko w kopii payloadu wersji, nigdy w products.enrichment_payload) pod kluczem META_KEY:
 *   web_file_ids {images, documents} — zdjęcia i pliki z internetu dodane przez przebieg tej wersji (versionMeta());
 *   manufacturer_norms_before / manufacturer_norms_written — products.manufacturer_norms sprzed przebiegu i wartość,
 *     którą przebieg zapisał; oba tylko wtedy, gdy przebieg tę kolumnę zapisał (odrzucenie przywraca „before” tylko,
 *     gdy na karcie stoi dalej „written” — ManufacturerNormFacts::sameFacts, jak przy propozycji);
 *   url_blocked — odrzucona wersja blokuje swój adres źródła (tylko odrzucony opis z karty, nie propozycja);
 *   url_unblocked_at / url_unblocked_by — blokadę zdjęło zatwierdzenie wersji z tego adresu;
 *   page_image_urls — adresy zdjęć stron opisu przebiegu lidera modelu (etap 2, ≤ PAGE_IMAGE_URLS_MAX): członkowie
 *     modelu dostają z nich zdjęcie w kolorze swojej karty bez ponownego pobierania stron;
 *   human_choice — kopia z „Przywróć wersję” (publish z $humanChoice): opis wybrany przez człowieka, withdrawCurrent
 *     go nie cofa;
 *   withdrawn_at / withdrawn_reason — wersja zdjęta z karty przez withdrawCurrent.
 */
final class DescriptionVersionStore
{
    /** Ile ostatnich wersji superseded i osobno shadow zostaje na kartę; published i rejected zostają wszystkie. */
    public const KEEP_OTHER = 5;

    /** Ile najnowszych propozycji bez decyzji zostaje na kartę; propozycje z decyzją zostają wszystkie. */
    public const KEEP_OPEN_PROPOSALS = 3;

    /** Klucz danych technicznych wersji w jej kopii enrichment_payload. */
    public const META_KEY = '_version';

    /** Reguła 5 decide: o ile dowodów mniej niż w bazie, żeby nowy opis został propozycją. */
    public const MIN_EVIDENCE_DROP = 2;

    /** Ile adresów zdjęć stron opisu (page_image_urls, etap 2) zostaje w danych technicznych wersji. */
    public const PAGE_IMAGE_URLS_MAX = 40;

    /** Powód zapisu wersji z adresu strony podanego przez człowieka (decide reguła 1) — taki opis nie jest cofany. */
    public const MANUAL_URL_REASON = 'adres strony podany ręcznie';

    /** Powód zapisu wersji ze strony z tabeli części producenta przypiętej do karty (decide, po stronie odrzuconej). */
    public const PARTS_TABLE_REASON = 'strona z tabeli części producenta';

    /**
     * Klucze enrichment_payload karty, które nie należą do opisu, tylko do stanu karty: ślady łączenia i rozłączania
     * kart — rozmiary (merged_size_skus czyta ProductSizeMergeService przy łączeniu rozmiarów; bez niej kolejny import
     * zrobiłby duplikaty) i duplikaty z „Połącz zaznaczone” (merged_duplicate_skus — CardRedirectsBackfillCommand).
     * withdrawCurrent zostawia je na karcie, publish i zapis opisu z przebiegu biorą je z karty, nie z kopii wersji.
     *
     * Ślady pochodzenia opisu z B2B (b2b_sources, b2b_supplement, b2b_sources_rejected, b2b_supplement_undone,
     * replaced_description*) celowo nie: opisują opis, który był na karcie — przy nowym opisie z internetu znikają
     * razem z nim (inaczej AuditSourceIdentityCommand uznałby kartę za „opis z PDF B2B” i schował jej znaleziska).
     * To inna lista niż B2bClearFileLegacyCommand::KEEP_PAYLOAD_KEYS (tam opis zostaje z B2B, więc jego ślady też).
     * Reszta payloadu (listy, normy, źródła, identity, evidence, price_list_sources, model_group, attributes,
     * description_version_id) należy do opisu.
     */
    public const CARD_STATE_PAYLOAD_KEYS = [
        'merged_size_skus',
        'merged_duplicate_skus',
        'merged_size_variants',
        'size_merge',
        'unmerged_size_skus',
        'unmerged_at',
    ];

    /** Decyzje człowieka o samej wersji — opis zatwierdzony albo wybrany do przywrócenia. */
    private const HUMAN_DECISIONS = [
        ProductDescriptionVersion::DECISION_APPROVED,
        ProductDescriptionVersion::DECISION_RESTORED,
    ];

    /** Ranga werdyktu tożsamości strony źródła (SourceIdentity); brak werdyktu = ranga nieznana. */
    private const RANK = [
        ProductDescriptionVersion::VERDICT_HARD => 3,
        ProductDescriptionVersion::VERDICT_SOFT => 2,
        ProductDescriptionVersion::VERDICT_NONE => 1,
    ];

    /**
     * Stan karty sprzed przebiegu — propozycja przywraca z niego to, co przebieg zapisał przed decyzją (normy ze strony
     * producenta, status, nowe zdjęcia i pliki). web_files: zdjęcia i dokumenty z internetu (bez plików z panelu B2B),
     * ta sama miara co ProductEnrichmentService::webFileIds.
     *
     * @return array{
     *     status: string|null,
     *     error: string|null,
     *     norms: string|null,
     *     manufacturer_norms: mixed,
     *     packaging: string|null,
     *     web_files: array{images: list<int>, documents: list<int>}
     * }
     */
    public function snapshot(Product $p): array
    {
        return [
            'status' => $p->enrichment_status,
            'error' => $p->enrichment_error,
            'norms' => $p->norms,
            'manufacturer_norms' => $p->manufacturer_norms,
            'packaging' => $p->packaging,
            'web_files' => $this->webFileIds($p),
        ];
    }

    /**
     * Dane techniczne wersji z przebiegu (klucz '_version' w $data dla record() / publishRun()): zdjęcia i pliki
     * z internetu dodane od snapshotu (te, które karta ma teraz, a nie miała przed przebiegiem), a gdy przebieg zapisał
     * manufacturer_norms ($runWrites) — wartość sprzed przebiegu i zapisana. Odrzucenie opublikowanej wersji
     * w przeglądzie usuwa te pliki i przywraca normy producenta (tylko te, które zapisał ten przebieg i których nikt
     * potem nie zmienił). Przebieg, który kolumny nie pisał, nie niesie jej wartości — odrzucenie jej nie dotyka.
     * Wołać po pobraniu zdjęć i plików, przed zapisem wersji (sprzątanie starych plików niczego tu nie zmienia).
     *
     * @param  array{manufacturer_norms?: mixed, web_files?: array{images?: list<int>, documents?: list<int>}}  $before  snapshot()
     * @param  array<string, mixed>  $runWrites  kolumna => wartość zapisana przez ten przebieg (ProductEnrichmentService)
     * @return array{web_file_ids: array{images: list<int>, documents: list<int>}, manufacturer_norms_before?: mixed, manufacturer_norms_written?: mixed}
     */
    public function versionMeta(Product $p, array $before, array $runWrites = []): array
    {
        $now = $this->webFileIds($p);
        $previous = is_array($before['web_files'] ?? null) ? $before['web_files'] : [];

        $meta = [
            'web_file_ids' => [
                'images' => array_values(array_diff($now['images'], $this->intList($previous['images'] ?? []))),
                'documents' => array_values(array_diff($now['documents'], $this->intList($previous['documents'] ?? []))),
            ],
        ];
        if (array_key_exists('manufacturer_norms', $runWrites)) {
            $meta['manufacturer_norms_before'] = $before['manufacturer_norms'] ?? null;
            $meta['manufacturer_norms_written'] = $runWrites['manufacturer_norms'];
        }

        return $meta;
    }

    /**
     * Dane techniczne wersji (META_KEY) — puste, gdy wersja ich nie ma (wersja z przeglądu, bazowa, sprzed zmiany).
     *
     * @return array<string, mixed>
     */
    public function meta(ProductDescriptionVersion $v): array
    {
        $payload = is_array($v->enrichment_payload) ? $v->enrichment_payload : [];

        return is_array($payload[self::META_KEY] ?? null) ? $payload[self::META_KEY] : [];
    }

    /**
     * Czy nowy opis zapisać, czy zostawić jako propozycję (reguła z kontraktu etapu 1, sekcja 2):
     *   1. adres podany ręcznie → zapis;
     *   2. strona źródła odrzucona wcześniej w przeglądzie → propozycja (rejected_source);
     *   2a. strona z tabeli części producenta przypięta do karty (pinned_page) → zapis (PARTS_TABLE_REASON);
     *   3. karta bez opisu albo bez bieżącej bazy → zapis;
     *   4. obie rangi tożsamości znane: niższa → propozycja (worse_version), wyższa → zapis;
     *   5. ranga równa albo nieznana: baza ma liczbę dowodów, a nowy opis o co najmniej MIN_EVIDENCE_DROP mniej
     *      → propozycja; inaczej zapis (różnica o jeden dowód to szum pobrania, nie gorszy opis — ryzyko 8 kontraktu).
     * review_reason przy zapisie: soft → identity_soft, none → identity_none, hard i brak werdyktu → null.
     * Opis z B2B i force nie zmieniają reguły — kartę z opisem B2B przebieg obchodzi wcześniej, jak dotąd.
     * identity w kandydacie: werdykt ('hard'|'soft'|'none'|null) albo tablica z kluczem verdict (SourceIdentity::judgeCard).
     *
     * @param  array{identity?: mixed, evidence_count?: int|null, primary_source_url?: string|null, manual_url?: bool, pinned_page?: bool}  $candidate
     * @return array{action: 'publish'|'propose', review_reason: string|null, reason: string}
     */
    public function decide(Product $p, array $candidate): array
    {
        $verdict = $this->verdictOf($candidate['identity'] ?? null);
        $count = $this->countOf($candidate['evidence_count'] ?? null);
        $url = trim((string) ($candidate['primary_source_url'] ?? ''));
        $publish = fn (string $reason): array => [
            'action' => 'publish',
            'review_reason' => match ($verdict) {
                ProductDescriptionVersion::VERDICT_SOFT => Product::REVIEW_IDENTITY_SOFT,
                ProductDescriptionVersion::VERDICT_NONE => Product::REVIEW_IDENTITY_NONE,
                default => null,
            },
            'reason' => $reason,
        ];
        $propose = static fn (string $reviewReason, string $reason): array => [
            'action' => 'propose',
            'review_reason' => $reviewReason,
            'reason' => $reason,
        ];

        if (($candidate['manual_url'] ?? false) === true) {
            return $publish(self::MANUAL_URL_REASON);
        }
        if ($url !== '' && $this->isRejectedUrl($p, $url)) {
            return $propose(Product::REVIEW_REJECTED_SOURCE, 'strona źródła odrzucona wcześniej w przeglądzie');
        }
        // strona z tabeli części producenta przypięta do karty (kod → wiersz tabeli, decyzja właściciela 09.10.2026) —
        // zapis jak przy adresie ręcznym, także przy twardej bazie z innej strony i większej liczbie jej dowodów
        if (($candidate['pinned_page'] ?? false) === true) {
            return $publish(self::PARTS_TABLE_REASON);
        }
        if (! $p->hasDescriptionText()) {
            return $publish('karta bez opisu');
        }
        $base = $this->current($p);
        if ($base === null) {
            return $publish('obecny opis bez wersji bazowej');
        }

        $newRank = $verdict !== null ? self::RANK[$verdict] : null;
        $oldRank = self::RANK[(string) $base->identity_verdict] ?? null;
        if ($newRank !== null && $oldRank !== null && $newRank !== $oldRank) {
            return $newRank < $oldRank
                ? $propose(Product::REVIEW_WORSE_VERSION, "tożsamość strony słabsza niż w obecnym opisie ({$verdict} < {$base->identity_verdict})")
                : $publish("tożsamość strony mocniejsza niż w obecnym opisie ({$verdict} > {$base->identity_verdict})");
        }
        if ($base->evidence_count !== null && $count !== null && (int) $base->evidence_count - $count >= self::MIN_EVIDENCE_DROP) {
            return $propose(Product::REVIEW_WORSE_VERSION, "mniej dowodów ze źródła niż w obecnym opisie ({$count} < {$base->evidence_count})");
        }

        return $publish('opis nie gorszy od obecnego');
    }

    /**
     * Nowa wersja opisu. published zastępuje poprzednią published karty (→ superseded). Brakujące pola werdyktu,
     * dowodów i źródła bierze z enrichment_payload (identity, evidence_summary.explicit, completeness,
     * primary_source_url). description_sha1 liczy z podanego opisu — zapisujący podaje tekst dokładnie taki, jaki
     * trafia do products.description, inaczej baza nie zadziała.
     *
     * Opcjonalny $data['_version'] (versionMeta()) trafia do kopii payloadu wersji pod META_KEY — tylko do wersji,
     * nie na kartę; klucz META_KEY w samym enrichment_payload jest pomijany (dane techniczne niesie tylko '_version').
     *
     * @param  array<string, mixed>  $data  pola kolumn product_description_versions (+ '_version')
     */
    public function record(Product $p, string $status, string $origin, array $data, ?User $by = null): ProductDescriptionVersion
    {
        if (! in_array($status, ProductDescriptionVersion::STATUSES, true)) {
            throw new InvalidArgumentException("Nieznany status wersji opisu: {$status}");
        }
        if (! in_array($origin, ProductDescriptionVersion::ORIGINS, true)) {
            throw new InvalidArgumentException("Nieznane pochodzenie wersji opisu: {$origin}");
        }
        if ($p->id === null) {
            throw new InvalidArgumentException('Wersja opisu wymaga zapisanej karty');
        }

        $payload = is_array($data['enrichment_payload'] ?? null) ? $data['enrichment_payload'] : null;
        if ($payload !== null) {
            unset($payload[self::META_KEY]);
        }
        $meta = $this->cleanMeta($data[self::META_KEY] ?? null);
        if ($meta !== []) {
            $payload = ($payload ?? []) + [self::META_KEY => $meta];
        }
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $description = isset($data['description']) && is_string($data['description']) ? $data['description'] : null;
        $sourceUrl = $data['primary_source_url'] ?? ($payload['primary_source_url'] ?? null);
        $verdict = array_key_exists('identity_verdict', $data) ? $data['identity_verdict'] : ($identity['verdict'] ?? null);
        $identityReason = array_key_exists('identity_reason', $data) ? $data['identity_reason'] : ($identity['reason'] ?? null);
        $evidence = array_key_exists('evidence_count', $data)
            ? $data['evidence_count']
            : ($payload['evidence_summary']['explicit'] ?? null);
        $completeness = array_key_exists('completeness', $data) ? $data['completeness'] : ($payload['completeness'] ?? null);

        $attributes = [
            'product_id' => (int) $p->id,
            'status' => $status,
            'origin' => $origin,
            'description' => $description,
            'enrichment_payload' => $payload,
            'enrichment_trace' => is_array($data['enrichment_trace'] ?? null) ? $data['enrichment_trace'] : null,
            'packaging' => $this->shortText($data['packaging'] ?? null, 255),
            'description_sha1' => $description !== null ? sha1($description) : null,
            'primary_source_url' => $this->shortText($sourceUrl, 2000),
            'identity_verdict' => $this->verdictOf($verdict),
            'identity_reason' => $this->shortText($identityReason, 255),
            'evidence_count' => $this->countOf($evidence),
            'completeness' => is_numeric($completeness) ? max(0.0, min(1.0, (float) $completeness)) : null,
            'review_reason' => in_array($data['review_reason'] ?? null, Product::REVIEW_REASONS, true) ? $data['review_reason'] : null,
            'reason' => $this->shortText($data['reason'] ?? null, 255),
            'batch_id' => is_numeric($data['batch_id'] ?? null) ? (int) $data['batch_id'] : null,
            'created_by' => $by?->id,
        ];

        return DB::transaction(function () use ($p, $status, $attributes): ProductDescriptionVersion {
            if ($status === ProductDescriptionVersion::STATUS_PUBLISHED) {
                ProductDescriptionVersion::query()
                    ->where('product_id', $p->id)
                    ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
                    ->update(['status' => ProductDescriptionVersion::STATUS_SUPERSEDED, 'updated_at' => now()]);
            }
            $version = ProductDescriptionVersion::query()->create($attributes);
            $this->prune((int) $p->id);

            return $version;
        });
    }

    /**
     * Atomowy zapis opisu z przebiegu (ProductEnrichmentService): w jednej transakcji z blokadą wiersza karty ponowne
     * decide() na świeżym stanie karty, a przy „publish” — wersja published (record, z '_version' z $data) i zapis
     * karty przez $writeCard. Między pierwszym decide() a zapisem inny proces mógł zapisać lepszy opis albo
     * handlowiec odrzucić adres źródła — wtedy decyzja pod blokadą to „propose”: nic nie jest zapisywane, $writeCard
     * nie jest wołane, a wynik ma version = null (wołający robi to, co przy propozycji, z oddaną decyzją).
     * review_reason i reason wersji pochodzą z decyzji pod blokadą. Wyjątek z $writeCard cofa także wersję.
     *
     * @param  array{identity?: mixed, evidence_count?: int|null, primary_source_url?: string|null, manual_url?: bool, pinned_page?: bool}  $candidate  jak decide()
     * @param  array<string, mixed>  $data  jak record() (+ '_version' z versionMeta())
     * @param  callable(ProductDescriptionVersion, array{action: 'publish', review_reason: string|null, reason: string}): void  $writeCard
     * @return array{decision: array{action: 'publish'|'propose', review_reason: string|null, reason: string}, version: ProductDescriptionVersion|null}
     */
    public function publishRun(
        Product $p,
        array $candidate,
        array $data,
        callable $writeCard,
        string $origin = ProductDescriptionVersion::ORIGIN_ENRICHMENT,
        ?User $by = null,
    ): array {
        if ($p->id === null) {
            throw new InvalidArgumentException('Wersja opisu wymaga zapisanej karty');
        }

        return DB::transaction(function () use ($p, $candidate, $data, $writeCard, $origin, $by): array {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($p->id);
            $decision = $this->decide($locked, $candidate);
            if ($decision['action'] !== 'publish') {
                return ['decision' => $decision, 'version' => null];
            }
            $data['review_reason'] = $decision['review_reason'];
            $data['reason'] = $decision['reason'];
            $version = $this->record($locked, ProductDescriptionVersion::STATUS_PUBLISHED, $origin, $data, $by);
            $writeCard($version, $decision);

            return ['decision' => $decision, 'version' => $version];
        });
    }

    /**
     * Zapis wersji na kartę przez model (hak Product::saving przelicza search_blob, Product::updated zleca reindeks):
     * opis, payload z description_version_id, opakowanie (gdy wersja je ma), kolumna norm z listy norm opisu, status
     * gotowe, bez powodu przeglądu. Na karcie powstaje nowa wersja published (origin: restore, review_approve…)
     * z treścią wersji źródłowej — źródłowa zostaje w historii bez zmian, a jej zapisane źródła
     * (product_source_documents) przechodzą na nową wersję, żeby retencja źródeł ich nie usunęła.
     *
     * Zdjęcia i pliki karty zostają te, które są (propozycja nie zostawiła swoich, stara wersja straciła swoje przy
     * kolejnych przebiegach) — dlatego certificates i document_urls w payloadzie liczone są od nowa z dokumentów
     * z internetu obecnych na karcie (jak po pobraniu plików w przebiegu: CertificateLabels::relabel), a nie
     * przepisywane z wersji, w której mogą wskazywać pliki, których już nie ma.
     *
     * Payload karty = payload wersji bez kluczy stanu karty (CARD_STATE_PAYLOAD_KEYS) + te klucze z obecnej karty
     * (scalone rozmiary i duplikaty powstałe po wersji albo rozłączone od niej — prawdę o nich zna karta); kopia nowej
     * wersji niesie payload wersji źródłowej bez zmian.
     *
     * $humanChoice: człowiek sam wybrał ten opis („Przywróć wersję” w przeglądzie) — nowa wersja niesie to w danych
     * technicznych (human_choice) i withdrawCurrent jej nie cofa, niezależnie od wcześniejszej decyzji przy wersji
     * źródłowej (np. url_given, której przywrócenie nie nadpisuje).
     */
    public function publish(ProductDescriptionVersion $v, ?User $by, string $origin, bool $humanChoice = false): Product
    {
        $description = (string) ($v->description ?? '');
        if (! Product::isDescriptionText($description)) {
            throw new RuntimeException('Ta wersja nie ma tekstu opisu.');
        }

        return DB::transaction(function () use ($v, $by, $origin, $description, $humanChoice): Product {
            /** @var Product $product */
            $product = Product::query()->lockForUpdate()->findOrFail($v->product_id);
            $payload = is_array($v->enrichment_payload) ? $v->enrichment_payload : [];
            unset($payload[self::META_KEY], $payload['description_version_id']);
            $payload = $this->withFilesOnCard($product, $payload);
            $new = $this->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, $origin, [
                'description' => $description,
                'enrichment_payload' => $payload,
                'enrichment_trace' => $v->enrichment_trace,
                'packaging' => $v->packaging,
                'primary_source_url' => $v->primary_source_url,
                'identity_verdict' => $v->identity_verdict,
                'identity_reason' => $v->identity_reason,
                'evidence_count' => $v->evidence_count,
                'completeness' => $v->completeness,
                'reason' => 'z wersji #'.$v->id,
                self::META_KEY => $humanChoice ? ['human_choice' => true] : [],
            ], $by);
            app(SourceDocumentStore::class)->repointVersion($product, (int) $v->id, (int) $new->id);

            $payload = array_diff_key($payload, array_flip(self::CARD_STATE_PAYLOAD_KEYS)) + $this->cardState($product);
            $payload['description_version_id'] = (int) $new->id;
            $updates = [
                'description' => $description,
                'enrichment_payload' => $payload,
                'norms' => ProductNormsColumn::fromList($payload['norms'] ?? null),
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enrichment_error' => null,
                'review_reason' => null,
                'review_since' => null,
            ];
            if ($v->packaging !== null && $v->packaging !== '') {
                $updates['packaging'] = $v->packaging;
            }
            // ślad przebiegu należy do opisu — wraca razem z nim (wersja bazowa bez śladu zostawia obecny)
            if (is_array($v->enrichment_trace)) {
                $updates['enrichment_trace'] = $v->enrichment_trace;
            }
            if ($product->enriched_at === null) {
                $updates['enriched_at'] = now();
            }
            $product->update($updates);

            return $product;
        });
    }

    /** Bieżąca baza: wersja published z opisem równym obecnemu opisowi karty (po sha1). */
    public function current(Product $p): ?ProductDescriptionVersion
    {
        $description = $p->description;
        if ($p->id === null || ! is_string($description) || $description === '') {
            return null;
        }

        return ProductDescriptionVersion::query()
            ->where('product_id', $p->id)
            ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
            ->where('description_sha1', sha1($description))
            ->orderByDesc('id')
            ->first();
    }

    /** Opis karty ma bieżącą bazę — pamięć SKU (applyFromSkuCache) go nie nadpisuje. */
    public function hasProtectedPublished(Product $p): bool
    {
        return $p->hasDescriptionText() && $this->current($p) !== null;
    }

    /**
     * Cofnięcie opisu z karty (etap 3, W4 — decyzja właściciela 08.10.2026 §9.1): marka „tylko od producenta”, a opis
     * na karcie pochodzi spoza stron producenta. Bieżąca wersja (current()) → superseded z powodem cofnięcia (kolumna
     * reason + dane techniczne withdrawn_at/withdrawn_reason); tekst zostaje w historii i wraca przez „Przywróć wersję”
     * (publish() z wersji superseded). Na karcie: bez opisu i bez kolumny norm (należą do cofniętego tekstu), bez
     * danych opisu w enrichment_payload (cechy, materiały, normy, atrybuty i źródła z tej samej strony czyta dopasowanie
     * przetargów i wektor — wersja ma ich kopię, przywrócenie je odtwarza); zostają tylko klucze stanu karty
     * (CARD_STATE_PAYLOAD_KEYS: scalone rozmiary i duplikaty), a gdy ich nie ma — payload null; status „manual”
     * (karta bez opisu nie może zostać „done”:
     * hasUsableDescription), powód przeglądu manufacturer_missing od teraz. Wpis pamięci SKU z tym samym tekstem albo
     * z tej samej strony znika — inaczej przebieg bez force położyłby cofnięty opis z powrotem. Zdjęć, plików, norm
     * producenta (manufacturer_norms) i opakowania nie rusza.
     *
     * Null i bez żadnej zmiany, gdy:
     *   - karta nie ma bieżącej wersji (opis bez wersji zapisał inny tor — nie ma gdzie zachować tekstu);
     *   - opis położył człowiek: wersja z zatwierdzenia w przeglądzie (origin review_approve), z decyzją approved albo
     *     restored, kopia „Przywróć wersję” (human_choice w danych technicznych albo origin restore z wersji, którą
     *     człowiek wybrał), albo wersja z adresu
     *     podanego ręcznie (MANUAL_URL_REASON albo adres źródła = zaufany adres karty, Product::trustedShopUrl);
     *   - opis karty jest z cennika B2B (B2bDescriptionSource).
     * Status karty nie chroni opisu: przebieg ustawia „manual” przy braku strony producenta (W4), a ręcznie wpisany
     * opis nie ma wersji (pierwszy warunek). Wołający dostaje kartę zsynchronizowaną z zapisem (bez starego opisu
     * w pamięci — późniejszy zapis tej instancji nie przeliczy indeksu ze starego tekstu).
     */
    public function withdrawCurrent(Product $p, string $reason): ?ProductDescriptionVersion
    {
        if ($p->id === null) {
            return null;
        }
        $reason = trim($reason) !== '' ? trim($reason) : 'opis spoza strony producenta';

        $result = DB::transaction(function () use ($p, $reason): ?array {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($p->id);
            $current = $product !== null ? $this->current($product) : null;
            if ($product === null || $current === null || $this->isHumanChoice($product, $current)
                || app(B2bDescriptionSource::class)->has($product)) {
                return null;
            }

            $payload = is_array($current->enrichment_payload) ? $current->enrichment_payload : [];
            $payload[self::META_KEY] = array_merge($this->meta($current), [
                'withdrawn_at' => now()->toJSON(),
                'withdrawn_reason' => mb_substr($reason, 0, 255),
            ]);
            $current->forceFill([
                'status' => ProductDescriptionVersion::STATUS_SUPERSEDED,
                'reason' => mb_substr(trim((string) $current->reason) !== '' ? trim((string) $current->reason).' | cofnięty: '.$reason : 'cofnięty: '.$reason, 0, 255),
                'enrichment_payload' => $payload,
            ])->save();
            $this->forgetSkuCacheOf($product, $current);

            // hak Product::saving przelicza indeks tekstowy, Product::updated zleca reindeks wektora
            $cardState = $this->cardState($product);
            $product->update([
                'description' => null,
                'norms' => null,
                'enrichment_payload' => $cardState === [] ? null : $cardState,
                'enrichment_status' => Product::ENRICHMENT_MANUAL,
                'review_reason' => Product::REVIEW_MANUFACTURER_MISSING,
                'review_since' => now(),
            ]);

            return [$product, $current];
        });
        if ($result === null) {
            return null;
        }
        [$product, $withdrawn] = $result;
        $p->setRawAttributes($product->getAttributes(), true);

        return $withdrawn->fresh();
    }

    /**
     * Bieżąca wersja karty (current()) → superseded jako cofnięta (reason „… | cofnięty: …” i withdrawn_at /
     * withdrawn_reason w danych technicznych, jak withdrawCurrent) BEZ zmiany karty: opis, normy, status i powód
     * przeglądu zostają. Dla przebiegu, który w tej samej transakcji zaraz zapisze nowy opis (ProductEnrichmentService::
     * publishDescription — marka „tylko od producenta”: opis ze sklepu nie jest bazą dla opisu ze strony producenta);
     * decide() widzi wtedy kartę bez wersji bazowej. Wołający trzyma blokadę wiersza karty i transakcję — rollback cofa
     * też to. Wpis pamięci SKU tego opisu znika (jak w withdrawCurrent). Null i bez zmian: bez bieżącej wersji, wersja
     * wybrana przez człowieka albo z adresu podanego ręcznie (isHumanChoice), opis z cennika B2B.
     */
    public function supersedeAsWithdrawn(Product $p, string $reason): ?ProductDescriptionVersion
    {
        if ($p->id === null) {
            return null;
        }
        $reason = trim($reason) !== '' ? trim($reason) : 'opis spoza strony producenta';

        return DB::transaction(function () use ($p, $reason): ?ProductDescriptionVersion {
            $current = $this->current($p);
            if ($current === null || $this->isHumanChoice($p, $current) || app(B2bDescriptionSource::class)->has($p)) {
                return null;
            }
            $payload = is_array($current->enrichment_payload) ? $current->enrichment_payload : [];
            $payload[self::META_KEY] = array_merge($this->meta($current), [
                'withdrawn_at' => now()->toJSON(),
                'withdrawn_reason' => mb_substr($reason, 0, 255),
            ]);
            $current->forceFill([
                'status' => ProductDescriptionVersion::STATUS_SUPERSEDED,
                'reason' => mb_substr(trim((string) $current->reason) !== '' ? trim((string) $current->reason).' | cofnięty: '.$reason : 'cofnięty: '.$reason, 0, 255),
                'enrichment_payload' => $payload,
            ])->save();
            $this->forgetSkuCacheOf($p, $current);

            return $current->fresh();
        });
    }

    /**
     * Klucze stanu karty z jej obecnego enrichment_payload (CARD_STATE_PAYLOAD_KEYS) — nie należą do opisu.
     *
     * @return array<string, mixed>
     */
    private function cardState(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return array_intersect_key($payload, array_flip(self::CARD_STATE_PAYLOAD_KEYS));
    }

    /**
     * Opis położony na kartę decyzją człowieka (withdrawCurrent go nie cofa): zatwierdzony w przeglądzie, przywrócony
     * przez „Przywróć wersję” albo pobrany z adresu podanego ręcznie. Kopię z „Przywróć wersję” znaczy human_choice
     * w danych technicznych (publish z $humanChoice, ProductReviewService::restore) — także gdy wersja źródłowa miała już
     * inną decyzję (url_given), której przywrócenie nie nadpisuje. Kopie sprzed tego znacznika: decyzję niesie wersja
     * źródłowa („z wersji #N”); restore z odrzucenia albo wycofania partii (bez decyzji przy źródle) nie jest wyborem
     * tego tekstu przez człowieka.
     */
    private function isHumanChoice(Product $product, ProductDescriptionVersion $v): bool
    {
        if ($v->origin === ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE
            || in_array($v->decision, self::HUMAN_DECISIONS, true)
            || $this->isTrue($this->meta($v)['human_choice'] ?? null)
            || str_starts_with((string) $v->reason, self::MANUAL_URL_REASON)) {
            return true;
        }
        $url = trim((string) ($v->primary_source_url ?? ''));
        $trusted = $product->trustedShopUrl();
        if ($url !== '' && $trusted !== null && self::sourceUrlKey($url) === self::sourceUrlKey($trusted)) {
            return true;
        }
        if ($v->origin === ProductDescriptionVersion::ORIGIN_RESTORE
            && preg_match('/^z wersji #(\d+)/', (string) $v->reason, $m) === 1) {
            $source = ProductDescriptionVersion::query()
                ->where('product_id', $product->id)
                ->whereKey((int) $m[1])
                ->first(['id', 'status', 'origin', 'decision', 'reason', 'primary_source_url']);

            // wersję odrzuconą na kartę wraca tylko „Przywróć wersję” (odrzucenie i wycofanie partii biorą superseded)
            return $source !== null && $source->origin !== ProductDescriptionVersion::ORIGIN_RESTORE
                && ($source->status === ProductDescriptionVersion::STATUS_REJECTED || $this->isHumanChoice($product, $source));
        }

        return false;
    }

    /**
     * Wersja zdjęta z karty przez withdrawCurrent — automat (powrót poprzedniego opisu po odrzuceniu) nie kładzie jej
     * z powrotem; wraca tylko przez „Przywróć wersję”.
     */
    public function isWithdrawn(ProductDescriptionVersion $v): bool
    {
        $at = $this->meta($v)['withdrawn_at'] ?? null;

        return is_string($at) && $at !== '';
    }

    /**
     * Wpis pamięci SKU karty, który niesie cofnięty opis (ten sam tekst) albo pochodzi z tej samej strony źródła.
     * Wpis z innej strony zostaje.
     */
    private function forgetSkuCacheOf(Product $product, ProductDescriptionVersion $v): void
    {
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
        $cache = ProductEnrichmentCache::query()->where('manufacturer', $key['manufacturer'])->where('sku', $key['sku'])->first();
        if ($cache === null) {
            return;
        }
        $cachePayload = is_array($cache->enrichment_payload) ? $cache->enrichment_payload : [];
        $cacheUrls = array_filter([
            $cachePayload['primary_source_url'] ?? null,
            ...(is_array($cache->source_urls) ? $cache->source_urls : []),
        ], static fn (mixed $url): bool => is_string($url) && trim($url) !== '');
        $versionUrl = trim((string) ($v->primary_source_url ?? ''));
        $sameUrl = $versionUrl !== '' && in_array(
            self::sourceUrlKey($versionUrl),
            array_map(static fn (string $url): string => self::sourceUrlKey($url), $cacheUrls),
            true,
        );
        // wpis trzyma najwyżej 10 000 znaków opisu (ProductEnrichmentService::storeSkuCache)
        $sameText = $v->description !== null
            && trim((string) $cache->description) === trim(mb_substr((string) $v->description, 0, 10000));
        if ($sameUrl || $sameText) {
            $cache->delete();
        }
    }

    /**
     * Klucz porównania adresu strony źródła przy blokadzie (odrzucenie, zdjęcie blokady, pominięcie w przebiegu):
     * Product::normalizeShopUrl z hostem bez przedrostka „www.” i „m.” — ta sama strona pod wariantem hosta
     * (sklep.pl/x, www.sklep.pl/x, m.sklep.pl/x) to jedna strona. Przedrostek zostaje, gdy po nim nie ma już kropki
     * (sama domena „m.pl”).
     */
    public static function sourceUrlKey(string $url): string
    {
        return (string) preg_replace('#^(?:www|m)\.(?=[^/?]*\.)#', '', Product::normalizeShopUrl($url));
    }

    /**
     * Zablokowane adresy stron źródła (po jednym na adres po sourceUrlKey): adresy opisów odrzuconych
     * z karty w przeglądzie („to cudza strona”, blockSourceUrl), bez zdjętych zatwierdzeniem wersji z tego adresu
     * (unblockSourceUrl). Odrzucona propozycja adresu nie blokuje — z tego adresu bywa obecny dobry opis. Automat
     * nie zapisuje już z zablokowanego adresu opisu, tylko propozycję.
     *
     * @return list<string>
     */
    public function rejectedUrls(Product $p): array
    {
        if ($p->id === null) {
            return [];
        }
        $out = [];
        $rows = ProductDescriptionVersion::query()
            ->toBase()
            ->where('product_id', $p->id)
            ->where('status', ProductDescriptionVersion::STATUS_REJECTED)
            ->whereNotNull('primary_source_url')
            ->orderBy('id')
            ->get(['primary_source_url', 'enrichment_payload->'.self::META_KEY.'->url_blocked as url_blocked']);
        foreach ($rows as $row) {
            $url = trim((string) $row->primary_source_url);
            if ($url !== '' && $this->isTrue($row->url_blocked ?? null)) {
                $out[self::sourceUrlKey($url)] ??= $url;
            }
        }

        return array_values($out);
    }

    /**
     * Odrzucony opis z karty blokuje swój adres źródła (rejectedUrls) — handlowiec stwierdził, że to cudza strona.
     * Wersja bez adresu nie ma czego blokować.
     */
    public function blockSourceUrl(ProductDescriptionVersion $v): void
    {
        if (trim((string) ($v->primary_source_url ?? '')) === '') {
            return;
        }
        $this->writeMeta($v, ['url_blocked' => true]);
    }

    /**
     * Zatwierdzenie (albo przywrócenie) wersji z adresu X zdejmuje blokadę X: odrzucone wersje karty z tym adresem
     * zostają odrzucone (z decyzją i autorem), ale rejectedUrls je pomija.
     *
     * @return int ile wersji odblokowano
     */
    public function unblockSourceUrl(Product $p, ?string $url, ?User $by): int
    {
        $url = trim((string) $url);
        if ($p->id === null || $url === '') {
            return 0;
        }
        $key = self::sourceUrlKey($url);
        $count = 0;
        $versions = ProductDescriptionVersion::query()
            ->where('product_id', $p->id)
            ->where('status', ProductDescriptionVersion::STATUS_REJECTED)
            ->whereNotNull('primary_source_url')
            ->get();
        foreach ($versions as $version) {
            if (self::sourceUrlKey((string) $version->primary_source_url) !== $key
                || ! $this->isTrue($this->meta($version)['url_blocked'] ?? null)) {
                continue;
            }
            $this->writeMeta($version, [
                'url_blocked' => false,
                'url_unblocked_at' => now()->toJSON(),
                'url_unblocked_by' => $by?->id,
            ]);
            $count++;
        }

        return $count;
    }

    /** Czy odrzucona wersja blokuje swój adres (do historii wersji). */
    public function blocksUrl(ProductDescriptionVersion $v): bool
    {
        return $v->status === ProductDescriptionVersion::STATUS_REJECTED && $this->isTrue($this->meta($v)['url_blocked'] ?? null);
    }

    private function isRejectedUrl(Product $p, string $url): bool
    {
        $key = self::sourceUrlKey($url);
        foreach ($this->rejectedUrls($p) as $rejected) {
            if (self::sourceUrlKey($rejected) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retencja: published i rejected zostają wszystkie (rejected niesie decyzję człowieka i blokadę adresu), propozycje
     * z decyzją też; propozycji bez decyzji KEEP_OPEN_PROPOSALS najnowszych (czeka tylko najnowsza — starsze to
     * powtórzone przebiegi), z superseded i shadow po KEEP_OTHER najnowszych każdego — przebiegi w cieniu nie
     * wypierają poprzednich opisów.
     */
    private function prune(int $productId): void
    {
        $groups = [
            [ProductDescriptionVersion::STATUS_SUPERSEDED, self::KEEP_OTHER, false],
            [ProductDescriptionVersion::STATUS_SHADOW, self::KEEP_OTHER, false],
            [ProductDescriptionVersion::STATUS_PROPOSED, self::KEEP_OPEN_PROPOSALS, true],
        ];
        foreach ($groups as [$status, $keep, $undecidedOnly]) {
            $stale = ProductDescriptionVersion::query()
                ->where('product_id', $productId)
                ->where('status', $status)
                ->when($undecidedOnly, static fn ($q) => $q->whereNull('decision'))
                ->orderByDesc('id')
                ->skip($keep)
                ->take(1000)
                ->pluck('id')
                ->all();
            if ($stale !== []) {
                ProductDescriptionVersion::query()->whereKey($stale)->delete();
            }
        }
    }

    /**
     * certificates i document_urls z dokumentów z internetu obecnych na karcie (bez plików z panelu B2B — jak przebieg,
     * który liczy je z pobranych plików). Klucze, których wersja nie miała, dochodzą tylko przy niepustej wartości.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withFilesOnCard(Product $product, array $payload): array
    {
        $documents = ProductDocument::query()
            ->where('product_id', $product->id)
            ->whereNull('b2b_account_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $certificates = CertificateLabels::relabel(
            array_values(array_filter((array) ($payload['certificates'] ?? []), static fn (mixed $item): bool => is_string($item))),
            $documents,
        );
        if (array_key_exists('certificates', $payload) || $certificates !== []) {
            $payload['certificates'] = $certificates;
        }
        $documentUrls = $documents
            ->map(static fn (ProductDocument $d): string => trim((string) $d->source_url))
            ->filter(static fn (string $url): bool => $url !== '')
            ->unique()
            ->values()
            ->all();
        if (array_key_exists('document_urls', $payload) || $documentUrls !== []) {
            $payload['document_urls'] = $documentUrls;
        }

        return $payload;
    }

    /**
     * @return array{images: list<int>, documents: list<int>}
     */
    private function webFileIds(Product $p): array
    {
        $ids = static fn (string $model): array => $p->id === null ? [] : $model::query()
            ->where('product_id', $p->id)
            ->whereNull('b2b_account_id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return ['images' => $ids(ProductImage::class), 'documents' => $ids(ProductDocument::class)];
    }

    /**
     * Dopisuje pola do danych technicznych wersji (META_KEY w jej payloadzie); reszta payloadu bez zmian.
     *
     * @param  array<string, mixed>  $fields
     */
    private function writeMeta(ProductDescriptionVersion $v, array $fields): void
    {
        $payload = is_array($v->enrichment_payload) ? $v->enrichment_payload : [];
        $payload[self::META_KEY] = array_merge($this->meta($v), $fields);
        $v->forceFill(['enrichment_payload' => $payload])->save();
    }

    /**
     * Dane techniczne od wołającego: tylko znane klucze, identyfikatory plików jako liczby.
     *
     * @return array<string, mixed>
     */
    private function cleanMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }
        $out = [];
        if (is_array($meta['web_file_ids'] ?? null)) {
            $out['web_file_ids'] = [
                'images' => $this->intList($meta['web_file_ids']['images'] ?? []),
                'documents' => $this->intList($meta['web_file_ids']['documents'] ?? []),
            ];
        }
        // para: bez wartości zapisanej nie da się sprawdzić, czy normy na karcie są dalej z przebiegu
        if (array_key_exists('manufacturer_norms_before', $meta) && array_key_exists('manufacturer_norms_written', $meta)) {
            $out['manufacturer_norms_before'] = $meta['manufacturer_norms_before'];
            $out['manufacturer_norms_written'] = $meta['manufacturer_norms_written'];
        }
        // adresy zdjęć stron opisu lidera (etap 2: członkowie modelu dostają zdjęcie w kolorze swojej karty bez sieci) —
        // najwyżej PAGE_IMAGE_URLS_MAX, tylko w kopii payloadu wersji
        if (is_array($meta['page_image_urls'] ?? null)) {
            $urls = [];
            foreach ($meta['page_image_urls'] as $url) {
                $url = is_string($url) ? trim($url) : '';
                if ($url !== '' && mb_strlen($url) <= 2000 && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
                if (count($urls) >= self::PAGE_IMAGE_URLS_MAX) {
                    break;
                }
            }
            if ($urls !== []) {
                $out['page_image_urls'] = $urls;
            }
        }
        // opis wybrany przez człowieka („Przywróć wersję”, publish z $humanChoice) — withdrawCurrent go nie cofa
        if (($meta['human_choice'] ?? null) === true) {
            $out['human_choice'] = true;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            array_filter($values, static fn ($id): bool => is_numeric($id) && (int) $id > 0),
        )));
    }

    /** Wartość logiczna z JSON: MySQL json_unquote oddaje „true”, SQLite json_extract 1, odczyt modelu — true. */
    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** Werdykt z napisu albo z tablicy SourceIdentity (klucz verdict); inne wartości = nieznany. */
    private function verdictOf(mixed $identity): ?string
    {
        $verdict = is_array($identity) ? ($identity['verdict'] ?? null) : $identity;

        return is_string($verdict) && isset(self::RANK[$verdict]) ? $verdict : null;
    }

    private function countOf(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(65535, (int) $value)) : null;
    }

    private function shortText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
