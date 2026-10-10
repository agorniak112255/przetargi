<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\ProductSourcePin;
use InvalidArgumentException;

/** Decyzja importera o źródle opisu karty: przypięta strona albo powód braku. */
final class SourceDecision
{
    /**
     * @param  list<string>  $spec  linie „Etykieta: wartość” z pliku (rozmiar, kolor, EAN) — dane cennika tej karty
     * @param  array<string, mixed>  $evidence  dlaczego ta strona (kod znaleziony na stronie, wiersz indeksu, kolumny pliku)
     * @param  list<array{url: string, title?: ?string, reason?: string}>  $candidates
     */
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $sourceKind,
        public readonly ?string $matchKind,
        public readonly ?string $matchKey,
        public readonly ?string $imageUrl,
        public readonly ?string $pageTitle,
        public readonly array $spec,
        public readonly array $evidence,
        public readonly ?string $unresolvedReason,
        public readonly array $candidates,
    ) {}

    /**
     * @param  list<string>  $spec
     * @param  array<string, mixed>  $evidence
     */
    public static function pinned(
        string $url,
        string $sourceKind,
        string $matchKind,
        ?string $matchKey,
        ?string $imageUrl = null,
        ?string $pageTitle = null,
        array $spec = [],
        array $evidence = [],
    ): self {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('Przypięcie wymaga pełnego adresu http(s).');
        }
        if (! in_array($sourceKind, ProductSourcePin::KINDS, true)) {
            throw new InvalidArgumentException('Nieznany rodzaj źródła: '.$sourceKind);
        }
        if (! in_array($matchKind, ProductSourcePin::MATCH_KINDS, true)) {
            throw new InvalidArgumentException('Nieznany rodzaj dopasowania: '.$matchKind);
        }

        return new self($url, $sourceKind, $matchKind, $matchKey, $imageUrl, $pageTitle, array_values($spec), $evidence, null, []);
    }

    /** @param  list<array{url: string, title?: ?string, reason?: string}>  $candidates */
    public static function unresolved(string $reason, array $candidates = []): self
    {
        $reason = trim($reason);

        return new self(null, null, null, null, null, null, [], [], $reason !== '' ? $reason : 'brak strony', array_values($candidates));
    }

    public function isPinned(): bool
    {
        return $this->url !== null;
    }

    /**
     * Pola wiersza product_source_pins (bez product_id, price_list_id, importer_*, checked_at).
     *
     * @return array<string, mixed>
     */
    public function toPinAttributes(): array
    {
        return [
            'url' => $this->url,
            'source_kind' => $this->sourceKind,
            'page_title' => $this->pageTitle !== null ? mb_substr($this->pageTitle, 0, 255) : null,
            'image_url' => $this->imageUrl,
            'match_kind' => $this->matchKind,
            'match_key' => $this->matchKey !== null ? mb_substr($this->matchKey, 0, 120) : null,
            'spec' => $this->spec !== [] ? $this->spec : null,
            'evidence' => $this->evidence !== [] ? $this->evidence : null,
            'unresolved_reason' => $this->unresolvedReason !== null ? mb_substr($this->unresolvedReason, 0, 255) : null,
            'candidates' => $this->candidates !== [] ? $this->candidates : null,
        ];
    }
}
