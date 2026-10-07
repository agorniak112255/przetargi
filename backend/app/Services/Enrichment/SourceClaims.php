<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Kody norm i poziomów ochrony w tekście — kopia 1:1 reguł z ProductEnrichmentService (sourceClaims,
 * normDesignations, normDesignationSupported, claimKey), wspólna dla sita norm w opisie i dowodów (EvidenceExtractor).
 * Zgodność z oryginałem pilnuje SourceClaimsParityTest — zmiana tu bez zmiany tam (albo odwrotnie) go wywróci.
 */
final class SourceClaims
{
    /**
     * Fakty, które muszą wystąpić w źródłach, w postaci do porównania (key):
     * - kody poziomów ochrony: EN 388 („4131A”, „4544C” — przecięcie coup test ma poziomy do 5), EN 407 („X1XXXX”),
     *   bez lat („2016”, „2020”);
     * - oznaczenia norm z wydaniem i zmianą („EN 388:2016+A1:2018”, „EN ISO 21420:2020”).
     *   Przedrostek „PN-” zostaje poza dopasowaniem (ta sama norma).
     *
     * @return list<string>
     */
    public static function claims(string $text): array
    {
        $upper = mb_strtoupper($text);
        preg_match_all('/(?<![\p{L}\p{N}])(?:[0-5X]{6}|[0-5X]{4}[A-FX]?)(?![\p{L}\p{N}])/u', $upper, $levels);
        // rok wydania tylko 19xx/20xx: w „EN 388: 4131A” po dwukropku stoją poziomy, nie rok (07.10.2026)
        preg_match_all('/(?<![\p{L}\p{N}])(?:EN|ISO)(?:\s*ISO)?\s*\d{3,5}(?:-\d+)*(?:\s*:\s*(?:19|20)\d\d(?!\d))?(?:\s*\+\s*A\d+(?:\s*:\s*(?:19|20)\d\d(?!\d))?)?/u', $upper, $norms);

        $claims = array_filter($levels[0], static fn (string $code): bool => preg_match('/^(?:19|20)\d\d$/', $code) !== 1);
        foreach ($norms[0] as $norm) {
            $claims[] = self::key($norm);
        }

        return array_values(array_unique($claims));
    }

    /**
     * Normy wymienione w tekście źródeł: numer z częścią („13997”, „374-1”) => wydania podane przy nim. Przedrostki
     * EN / ISO / IEC / PN- i myślnik („EN-388”) nie mają znaczenia; wydanie bywa w nawiasie („EN 388 (2016)”).
     *
     * @return array<string, list<string>>
     */
    public static function designations(string $text): array
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}])(?:PN[\s-]*)?(?:EN|ISO|IEC)(?:[\s-]*(?:ISO|IEC))?[\s-]*(\d{3,5}(?:-\d+)*)(?:\s*[:(]\s*((?:19|20)\d\d)(?!\d))?/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        );
        $out = [];
        foreach ($matches as $hit) {
            $out[$hit[1]] ??= [];
            if (($hit[2] ?? '') !== '') {
                $out[$hit[1]][] = $hit[2];
            }
        }

        return $out;
    }

    /**
     * Oznaczenie normy (klucz z claims, np. „ENISO374-1:2016+A1:2018”) ma pokrycie, gdy źródło wymienia tę normę
     * (numer i część; „EN 374” pokrywa „EN ISO 374-1”), a wydanie — tylko gdy źródło podaje jakiekolwiek wydanie
     * tej normy: strona z samym „EN ISO 20345” nie przeczy „EN ISO 20345:2022”, strona z „:2011” — tak.
     *
     * @param  array<string, list<string>>  $sourceNorms
     */
    public static function designationSupported(string $claim, array $sourceNorms): bool
    {
        if (preg_match('/(\d{3,5}(?:-\d+)*)(?::((?:19|20)\d\d))?/', $claim, $m) !== 1) {
            return true;
        }
        $core = $m[1];
        $edition = $m[2] ?? '';
        $editions = $sourceNorms[$core] ?? null;
        if ($editions === null) {
            foreach ($sourceNorms as $sourceCore => $sourceEditions) {
                if (str_starts_with((string) $sourceCore, $core.'-')) {
                    $editions = [...($editions ?? []), ...$sourceEditions];
                }
            }
        }
        if ($editions === null) {
            return false;
        }

        return $edition === '' || $editions === [] || in_array($edition, $editions, true);
    }

    /** Tekst do porównania faktów ze źródłem: wielkie litery, bez białych znaków (PDF łamie „4131 A”, „EN 388 :2016”). */
    public static function key(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtoupper($text));
    }
}
