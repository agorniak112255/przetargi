<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\B2b\B2bDescriptionSource;
use Carbon\CarbonImmutable;

/**
 * Skąd karty cennika z pliku mają opis (Cenniki → „Z pliku”, filtry „Pobierz opisy ponownie”): źródło opisu liczone
 * z enrichment_payload->primary_source_url i ->primary_source_kind (ścieżka JSON, bez czytania całego payloadu),
 * opis z B2B z B2bDescriptionSource. Porcje po 1000 kart — cenniki z plików obejmują dziesiątki tysięcy kart.
 *
 * Kolejność rozstrzygania jednej karty:
 *   none — karta bez opisu (Product::hasDescriptionText: także opis będący samą nazwą z cennika),
 *   b2b — opis ze sklepu dostawcy (B2bDescriptionSource) albo opis AI z karty katalogowej/uzupełnienia B2B,
 *   manufacturer — primary_source_kind manufacturer/catalog (strona producenta albo jego katalog PDF),
 *   price_list_sites — adres źródła na stronach cennika (PriceListSourceSettings::position),
 *   other — reszta (sklepy spoza listy, adres ręczny spoza listy, opis bez zapisanego źródła).
 */
final class PriceListDescriptionSources
{
    public const SOURCE_PRICE_LIST_SITES = 'price_list_sites';

    public const SOURCE_MANUFACTURER = 'manufacturer';

    public const SOURCE_B2B = 'b2b';

    public const SOURCE_OTHER = 'other';

    public const SOURCE_NONE = 'none';

    public const SOURCES = [
        self::SOURCE_PRICE_LIST_SITES,
        self::SOURCE_MANUFACTURER,
        self::SOURCE_OTHER,
        self::SOURCE_B2B,
        self::SOURCE_NONE,
    ];

    /** primary_source_kind zapisywane przez ProductEnrichmentService::primarySource dla strony producenta. */
    public const MANUFACTURER_KINDS = ['manufacturer', 'catalog'];

    /** Opis AI zbudowany na danych konta B2B (DescribeB2bProductFromDatasheetJob, uzupełnienie opisu B2B). */
    private const B2B_KINDS = ['b2b_datasheet', 'b2b_supplement'];

    /**
     * Zapas ponad długość nazwy przy czytaniu początku opisu: Product::descriptionRepeatsName porównuje opis z nazwą
     * (reszta po nazwie < 80 znaków), więc dłuższy opis i tak jest opisem — nie trzeba go czytać w całości.
     */
    private const HEAD_MARGIN = 200;

    public function __construct(private readonly B2bDescriptionSource $b2bDescriptions) {}

    /**
     * @param  list<int>  $ids  karty cennika (PriceListCards)
     * @param  PriceListSourceSettings|null  $settings  strony cennika; null = cennik bez stron
     * @return array<int, array{
     *     source: string,
     *     host_position: int|null,
     *     kind: string|null,
     *     status: string,
     *     enriched_at: int|null,
     *     hosts_sha1: string|null
     * }> id karty => źródło opisu; host_position = pozycja hosta adresu źródła na liście cennika (karta z opisem);
     *    enriched_at = znacznik czasu Unix daty opisu
     */
    public function cards(array $ids, ?PriceListSourceSettings $settings): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $rows = Product::query()
                ->whereIntegerInRaw('id', $chunk)
                ->toBase()
                ->select([
                    'id',
                    'name',
                    'enrichment_status',
                    'enriched_at',
                    'enrichment_payload->primary_source_url as primary_source_url',
                    'enrichment_payload->primary_source_kind as primary_source_kind',
                    'enrichment_payload->price_list_sources->hosts_sha1 as list_hosts_sha1',
                ])
                // początek opisu wystarczy do miary karty (HEAD_MARGIN); długość nazwy w bajtach (MySQL) ≥ w znakach
                ->selectRaw('SUBSTR(description, 1, LENGTH(name) + ?) as description_head', [self::HEAD_MARGIN])
                ->selectRaw('LENGTH(name) + ? as description_head_limit', [self::HEAD_MARGIN])
                ->get();

            $described = [];
            foreach ($rows as $row) {
                if ($this->described($row)) {
                    $described[] = (int) $row->id;
                }
            }
            $fromB2b = $described === [] ? [] : $this->b2bDescriptions->productIds($described);
            $describedSet = array_fill_keys($described, true);

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $url = $this->jsonString($row->primary_source_url ?? null);
                $kind = $this->jsonString($row->primary_source_kind ?? null);
                $position = $url !== null && $settings !== null ? $settings->position($url) : null;
                $source = match (true) {
                    ! isset($describedSet[$id]) => self::SOURCE_NONE,
                    isset($fromB2b[$id]) || in_array($kind, self::B2B_KINDS, true) => self::SOURCE_B2B,
                    in_array($kind, self::MANUFACTURER_KINDS, true) => self::SOURCE_MANUFACTURER,
                    $position !== null => self::SOURCE_PRICE_LIST_SITES,
                    default => self::SOURCE_OTHER,
                };
                $out[$id] = [
                    'source' => $source,
                    'host_position' => $source === self::SOURCE_NONE || $source === self::SOURCE_B2B ? null : $position,
                    'kind' => $kind,
                    'status' => (string) ($row->enrichment_status ?? Product::ENRICHMENT_NONE),
                    // znacznik czasu zamiast obiektu daty — przebieg obejmuje dziesiątki tysięcy kart
                    'enriched_at' => $row->enriched_at !== null ? CarbonImmutable::parse((string) $row->enriched_at)->getTimestamp() : null,
                    // odcisk stron cennika, z którymi powstał opis (ProductEnrichmentService, price_list_sources)
                    'hosts_sha1' => $this->jsonString($row->list_hosts_sha1 ?? null),
                ];
            }
        }

        return $out;
    }

    /** Ta sama miara co Product::hasDescriptionText, na początku opisu (HEAD_MARGIN). */
    private function described(object $row): bool
    {
        $head = (string) ($row->description_head ?? '');
        if ($head === '') {
            return false;
        }
        $complete = mb_strlen($head) < (int) $row->description_head_limit;
        if (! $complete) {
            // opis dłuższy niż nazwa + zapas: nie jest samą nazwą, wystarczy miara tekstu
            return Product::isDescriptionText($head);
        }
        $probe = new Product;
        $probe->setRawAttributes(['name' => (string) ($row->name ?? ''), 'description' => $head]);

        return $probe->hasDescriptionText();
    }

    /** Wartość ścieżki JSON: MySQL json_unquote zwraca napis „null” dla JSON null. */
    private function jsonString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || $value === 'null' ? null : $value;
    }
}
