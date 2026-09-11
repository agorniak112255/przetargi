<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Powód, dla którego kandydat na kartę produktu odpadł — w przebiegu wyszukiwania
 * i przy sprawdzaniu mapowania widać, na którym filtrze zginęła właściwa strona.
 */
final class CandidateRejection
{
    public const NOISE_URL = 'noise_url';

    public const MANUFACTURER_CONFLICT = 'manufacturer_conflict';

    public const TYPE_MISSING = 'type_missing';

    public const CLAIMS_OTHER_CODE = 'claims_other_code';

    public const WEAK_MATCH = 'weak_match';

    public const THREEM_SHORT = 'threem_short';

    public const LISTING = 'listing';

    public const UNRELATED_HOST = 'unrelated_host';

    public const NOT_PRODUCT_CARD = 'not_product_card';

    public const CHEMICAL = 'chemical';

    public const NO_IDENTITY = 'no_identity';

    public const FETCH_FAILED = 'fetch_failed';

    public const BOT_WALL = 'bot_wall';

    public const LONGER_VARIANT = 'longer_variant';

    public const UNCONFIRMED = 'unconfirmed';

    public const UNCONFIRMED_STRICT = 'unconfirmed_strict';

    private const LABELS = [
        self::NOISE_URL => 'adres kontaktu/kuponu, nie karta',
        self::MANUFACTURER_CONFLICT => 'strona innego producenta',
        self::TYPE_MISSING => 'brak rodzaju wyrobu w adresie',
        self::CLAIMS_OTHER_CODE => 'adres lub tytuł z innym kodem',
        self::WEAK_MATCH => 'sam krótki numer bez marki',
        self::THREEM_SHORT => 'krótki kod 3M bez numeru w adresie',
        self::LISTING => 'lista/kategoria, nie karta',
        self::UNRELATED_HOST => 'niezwiązana domena',
        self::NOT_PRODUCT_CARD => 'blog/kontakt/kupon, nie karta',
        self::CHEMICAL => 'katalog odczynników',
        self::NO_IDENTITY => 'brak kodu i nazwy w adresie',
        self::FETCH_FAILED => 'strona nie odpowiedziała',
        self::BOT_WALL => 'blokada WAF, reader też nie przeszedł',
        self::LONGER_VARIANT => 'dłuższy wariant SKU na stronie',
        self::UNCONFIRMED => 'treść nie potwierdza produktu',
        self::UNCONFIRMED_STRICT => 'brak dokładnego SKU albo pełnej nazwy',
    ];

    public static function label(string $reason): string
    {
        return self::LABELS[$reason] ?? $reason;
    }

    /**
     * „odrzucono 5 — strona innego producenta 3, brak rodzaju wyrobu w adresie 2”.
     *
     * @param  list<array{url: string, reason: string}>  $rejections
     */
    public static function summary(array $rejections): string
    {
        $counts = [];
        foreach ($rejections as $row) {
            $label = self::label((string) $row['reason']);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $label.' '.$n;
        }

        return 'odrzucono '.count($rejections).' — '.implode(', ', $parts);
    }

    /**
     * Jeden adres raz — pierwszy powód wygrywa.
     *
     * @param  list<array{url: string, reason: string}>  $rejections
     * @return list<array{url: string, reason: string}>
     */
    public static function unique(array $rejections): array
    {
        $seen = [];
        $out = [];
        foreach ($rejections as $row) {
            $key = mb_strtolower((string) $row['url']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['url' => (string) $row['url'], 'reason' => (string) $row['reason']];
        }

        return $out;
    }
}
