<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Sources;

use App\Models\ProductSourcePin;
use App\Services\Enrichment\DescriptionVersionStore;

/**
 * Karta przypięta przez importer cennika (product_source_pins z url, 10.10.2026): strona producenta, dostawcy albo
 * sklepu wskazana deterministycznie przez kod importera. Jedyne źródło opisu karty; linie spec to dane cennika tej
 * karty (rozmiar, kolor, EAN). Zdjęcie wskazane wprost (image_url) ustawia MappedSourceImages po zapisie opisu.
 */
final class MappedSourcePin implements SourcePin
{
    /** @param  list<string>  $spec */
    public function __construct(
        public readonly string $pageUrl,
        /** manufacturer | supplier | shop */
        public readonly string $sourceKind,
        public readonly ?string $pageTitle,
        public readonly ?string $imageUrl,
        /** exact_code | short_code | ean | model | parts_table */
        public readonly string $matchKind,
        public readonly string $matchKey,
        public readonly array $spec,
        public readonly string $importerKey,
        public readonly int $importerVersion,
        public readonly int $priceListId,
    ) {}

    /** Wiersz mapy z url (ProductSourcePin::isResolved) — wołający sprawdza to wcześniej. */
    public static function fromRow(ProductSourcePin $row): self
    {
        $image = trim((string) $row->image_url);
        $title = trim((string) $row->page_title);
        $spec = self::normalizeSpec((array) ($row->spec ?? []));

        return new self(
            pageUrl: trim((string) $row->url),
            sourceKind: in_array($row->source_kind, ProductSourcePin::KINDS, true) ? (string) $row->source_kind : ProductSourcePin::KIND_MANUFACTURER,
            pageTitle: $title !== '' ? $title : null,
            imageUrl: preg_match('#^https?://#i', $image) === 1 ? $image : null,
            matchKind: trim((string) $row->match_kind),
            matchKey: trim((string) $row->match_key),
            spec: $spec,
            importerKey: (string) $row->importer_key,
            importerVersion: (int) $row->importer_version,
            priceListId: (int) $row->price_list_id,
        );
    }

    /**
     * Linie spec bez pustych i powtórzeń — ta sama postać w payload opisu i przy porównaniu w MapPriceListSourcesJob
     * (inaczej różnica zapisu zlecałaby opis przy każdym mapowaniu).
     *
     * @param  array<mixed>  $lines
     * @return list<string>
     */
    public static function normalizeSpec(array $lines): array
    {
        $spec = [];
        foreach ($lines as $line) {
            $line = is_scalar($line) ? trim((string) $line) : '';
            if ($line !== '' && ! in_array($line, $spec, true)) {
                $spec[] = $line;
            }
        }

        return $spec;
    }

    public function url(): string
    {
        return $this->pageUrl;
    }

    public function title(): ?string
    {
        return $this->pageTitle;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /**
     * Werdykt tożsamości: decyzja importera (kod, EAN albo model ze strony) to twardy werdykt tej karty.
     *
     * @return array{verdict: 'hard', reason: string, key_type: string, key: string, where: 'text'}
     */
    public function identity(): array
    {
        $keyType = match ($this->matchKind) {
            ProductSourcePin::MATCH_EAN => 'ean',
            ProductSourcePin::MATCH_MODEL => 'model',
            default => 'manufacturer_code',
        };
        $reason = 'mapa importera '.$this->importerKey.': '.trim($this->matchKind.' '.$this->matchKey);

        return ['verdict' => 'hard', 'reason' => $reason, 'key_type' => $keyType, 'key' => $this->matchKey, 'where' => 'text'];
    }

    /** @return list<string> */
    public function specLines(): array
    {
        return $this->spec;
    }

    public function promptNote(): string
    {
        $title = trim((string) $this->pageTitle);
        $page = $this->pageUrl.($title !== '' ? ' („'.$title.'”)' : '');
        $note = $this->sourceKind === ProductSourcePin::KIND_MANUFACTURER
            ? 'Źródło opisu: wyłącznie strona producenta '.$page.', wskazana dla tej karty przez importer cennika'
            : 'Źródło opisu: wyłącznie strona dostawcy/sklepu '.$page.' wskazana przez importer cennika — opisuj tylko ten wyrób, nie inne oferty, akcesoria ani warianty ze strony';
        $note .= '.';
        if ($this->spec !== []) {
            $note .= ' Dane cennika tej karty: '.implode('; ', $this->spec).'.';
        }

        return $note.' Inne warianty wymienione na stronie (rozmiar, kolor, kod) to inne karty — nie przypisuj ich danych tej karcie.';
    }

    public function payloadKey(): string
    {
        return 'source_map';
    }

    /**
     * Do enrichment_payload.source_map.
     *
     * @return array{url: string, source_kind: string, match_kind: string, match_key: string, image_url: ?string, spec: list<string>, importer_key: string, importer_version: int}
     */
    public function payload(): array
    {
        return [
            'url' => $this->pageUrl,
            'source_kind' => $this->sourceKind,
            'match_kind' => $this->matchKind,
            'match_key' => $this->matchKey,
            'image_url' => $this->imageUrl,
            // dane cennika, z którymi powstał opis — zmiana w pliku zleca opis ponownie (MapPriceListSourcesJob)
            'spec' => $this->spec,
            'importer_key' => $this->importerKey,
            'importer_version' => $this->importerVersion,
        ];
    }

    public function groupKey(): string
    {
        return 'map:'.sha1(DescriptionVersionStore::sourceUrlKey($this->pageUrl));
    }

    public function handlesImages(): bool
    {
        return $this->imageUrl !== null;
    }

    public function logLabel(): string
    {
        return 'mapa importera '.$this->importerKey.': '.trim($this->matchKind.' '.$this->matchKey).' ('.$this->sourceKind.')';
    }

    public function publishReason(): string
    {
        return DescriptionVersionStore::MAPPED_SOURCE_REASON;
    }
}
