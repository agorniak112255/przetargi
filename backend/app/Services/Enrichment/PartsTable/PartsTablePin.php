<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\Sources\SourcePin;

/**
 * Karta przypięta do wiersza tabeli części na stronie producenta: strona (kanoniczna przy duplikatach), kod z tabeli,
 * rozmiar, kolor i waga dosłownie z wiersza, zdjęcie (styl w kolorze albo zdjęcie modelu). Jedyne źródło opisu karty.
 * Kształt wspólny z mapą importera cennika — SourcePin (10.10.2026); właściwości zostają (PartsTableImages, polecenia).
 */
final class PartsTablePin implements SourcePin
{
    public function __construct(
        public readonly string $brandKey,
        /** kanoniczna strona (duplikaty: ma style > slug bez -N > krótszy > alfabetycznie) */
        public readonly string $pageUrl,
        /** slug strony */
        public readonly string $pageKey,
        public readonly ?string $pageTitle,
        /** kod z tabeli (np. AF010706), dosłownie jak w tabeli */
        public readonly string $part,
        public readonly ?string $size,
        public readonly ?string $colour,
        public readonly ?float $weightKg,
        /** styl w kolorze albo zdjęcie modelu */
        public readonly ?string $imageUrl,
        /** 'style' | 'model' */
        public readonly string $imageReason,
        /** skrót cennika rozwiązany po kolorze i rozmiarze */
        public readonly bool $viaShortCode,
        /** kod karty z cennika (SKU) — do powodu werdyktu przy skrócie */
        public readonly ?string $cardCode = null,
    ) {}

    /**
     * Werdykt tożsamości jak SourceIdentity::verdict(): wiersz tabeli części to dane producenta tej karty.
     *
     * @return array{verdict: 'hard', reason: string, key_type: 'manufacturer_code', key: string, where: 'text'}
     */
    public function identity(): array
    {
        $reason = 'tabela części '.$this->host().': '.$this->part;
        if ($this->viaShortCode && $this->cardCode !== null && trim($this->cardCode) !== '') {
            $reason .= ' (skrót cennika '.trim($this->cardCode).')';
        }

        return ['verdict' => 'hard', 'reason' => $reason, 'key_type' => 'manufacturer_code', 'key' => $this->part, 'where' => 'text'];
    }

    /**
     * Dane wiersza tabeli dosłownie (tylko niepuste): „Numer części: AF010706”, „Rozmiar: 1,2 m x 18,3 m”,
     * „Kolor: Czarny/Żółty”, „Waga: 58,55 kg”.
     *
     * @return list<string>
     */
    public function specLines(): array
    {
        $lines = ['Numer części: '.$this->part];
        foreach (['Rozmiar' => $this->size, 'Kolor' => $this->colour] as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = $label.': '.$value;
            }
        }
        if ($this->weightKg !== null) {
            $lines[] = 'Waga: '.self::polishNumber($this->weightKg).' kg';
        }

        return $lines;
    }

    /** Notatka do polecenia modelu: źródłem jest wyłącznie ta strona, a wiersz tabeli to dane producenta tej karty. */
    public function promptNote(): string
    {
        $title = trim((string) $this->pageTitle);

        return 'Źródło opisu: wyłącznie strona producenta '.$this->pageUrl.($title !== '' ? ' („'.$title.'”)' : '')
            .'. Karta jest przypięta do wiersza tabeli części tej strony ('.$this->part.') — dane tego wiersza są danymi'
            .' producenta tej karty: '.implode('; ', $this->specLines()).'. Inne wiersze tabeli to inne warianty'
            .' (rozmiar, kolor) — nie przypisuj ich danych tej karcie.';
    }

    /**
     * Do enrichment_payload.parts_table.
     *
     * @return array{page_url: string, page_key: string, part: string, size: ?string, colour: ?string, weight_kg: ?float, image_url: ?string, image_reason: string, via_short_code: bool}
     */
    public function payload(): array
    {
        return [
            'page_url' => $this->pageUrl,
            'page_key' => $this->pageKey,
            'part' => $this->part,
            'size' => $this->size,
            'colour' => $this->colour,
            'weight_kg' => $this->weightKg,
            'image_url' => $this->imageUrl,
            'image_reason' => $this->imageReason,
            'via_short_code' => $this->viaShortCode,
        ];
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

    public function payloadKey(): string
    {
        return 'parts_table';
    }

    /** Model = strona z tabeli (ModelGroupPlanner): „coba|page:orthomat-standard”. */
    public function groupKey(): string
    {
        return $this->brandKey.'|page:'.$this->pageKey;
    }

    /** Zdjęcia karty przypiętej rozstrzyga zawsze PartsTableImages (także wiersz bez zdjęcia — komunikat karty). */
    public function handlesImages(): bool
    {
        return true;
    }

    public function logLabel(): string
    {
        $code = trim((string) $this->cardCode);

        return 'tabela części producenta: '.$this->part.($this->viaShortCode && $code !== '' ? ' (skrót cennika '.$code.')' : '');
    }

    public function publishReason(): string
    {
        return DescriptionVersionStore::PARTS_TABLE_REASON;
    }

    private function host(): string
    {
        $host = mb_strtolower((string) (parse_url($this->pageUrl, PHP_URL_HOST) ?? ''), 'UTF-8');

        return (string) preg_replace('/^www\./', '', $host);
    }

    /** 58.55 → „58,55”, 5.4 → „5,4”, 12.0 → „12” (waga z tabeli do trzech miejsc). */
    private static function polishNumber(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

        return str_replace('.', ',', $text);
    }
}
