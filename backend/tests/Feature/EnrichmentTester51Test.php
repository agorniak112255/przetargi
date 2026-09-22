<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionMethod;
use Tests\Support\Tester51Fixture;
use Tests\TestCase;

/**
 * Zapadka (ratchet) na bramce tożsamości stron z zestawu odniesienia „tester 51”.
 *
 * Dla każdej pary (produkt, migawka strony) uruchamiamy prywatną metodę
 * `ProductEnrichmentService::keepConfirmedCardPages()` i liczymy dwie rzeczy:
 *
 * - ile źródeł ręcznie oznaczonych jako `wrong` (strona NA PEWNO opisuje inny wyrób)
 *   bramka ODRZUCIŁA — to chcemy podnosić,
 * - ile źródeł oznaczonych jako `expected` (strona NA PEWNO opisuje ten wyrób)
 *   bramka ZACHOWAŁA — to nie może spaść.
 *
 * Progi `MIN_WRONG_REJECTED` i `MIN_EXPECTED_KEPT` są wartościami ZMIERZONYMI na
 * kodzie z dnia założenia zestawu. Po każdej poprawce bramki podnosimy je do nowego
 * pomiaru — dzięki temu raz naprawiony przypadek nie wraca. Spadek którejkolwiek
 * liczby to REGRESJA: albo zaczęliśmy wpuszczać karty obcych produktów, albo
 * zaczęliśmy odrzucać karty własne. Progów nigdy nie obniżamy, żeby test zzieleniał.
 *
 * Źródła `unknown` są policzone tylko informacyjnie i nie biorą udziału w asercjach.
 *
 * Od 22.09.2026 opis pisze wyłącznie model — dawne miary „opis z jednej karty” i „opis
 * z właściwej karty” mierzyły opis zapasowy składany z akapitów stron; usunięto je razem
 * z tym mechanizmem. Zostaje bramka tożsamości: to ona decyduje, które strony widzi model.
 *
 * Test nie dotyka sieci ani modelu językowego — czyta wyłącznie migawki z fixture,
 * a produkty buduje jako niezapisane modele, więc nie potrzebuje bazy.
 */
final class EnrichmentTester51Test extends TestCase
{
    /**
     * Ile źródeł `wrong` bramka odrzuca. Pomiar z 2026-09-16: ZERO na 14 obcych kart.
     * Po etapie 2 (części zamienne i rodzaj wyrobu): 12 na 14. Zostają dwa przypadki —
     * karta płatka do SECURA 2000 (rozstrzyga dopiero kod z treści strony) i pokrywa
     * zaworu z ręcznie przypiętym złym adresem, którego bramka celowo nie ocenia.
     * Podnosić po każdej poprawce; lista przepuszczonych adresów jest w komunikacie błędu.
     */
    private const MIN_WRONG_REJECTED = 12;

    /** Ile źródeł `expected` bramka zachowuje dziś (39 z 39). Nigdy nie może spaść. */
    private const MIN_EXPECTED_KEPT = 39;

    public function test_bramka_tozsamosci_nie_cofa_sie_na_zestawie_testerki(): void
    {
        $service = app(ProductEnrichmentService::class);
        $keep = new ReflectionMethod($service, 'keepConfirmedCardPages');
        $keep->setAccessible(true);

        $wrongRejected = 0;
        $expectedKept = 0;
        $unknownKept = 0;
        $wrongKeptList = [];
        $expectedDroppedList = [];

        foreach (Tester51Fixture::items() as $item) {
            $sku = (string) $item['sku'];
            if ($item['sources'] === []) {
                continue;
            }
            $product = Tester51Fixture::makeProduct($sku);

            foreach ($item['sources'] as $source) {
                $url = (string) $source['url'];
                $page = Tester51Fixture::page($url);
                $this->assertNotNull($page, "Brak migawki strony {$url} (pozycja {$sku}).");

                $kept = $keep->invoke($service, $product, [[
                    'url' => $url,
                    'title' => (string) $page['title'],
                    'text' => (string) $page['text'],
                ]]);
                $wasKept = $kept !== [];

                match ((string) $source['verdict']) {
                    'wrong' => $wasKept ? $wrongKeptList[] = "{$sku} -> {$url}" : $wrongRejected++,
                    'expected' => $wasKept ? $expectedKept++ : $expectedDroppedList[] = "{$sku} -> {$url}",
                    default => $wasKept ? $unknownKept++ : null,
                };
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_WRONG_REJECTED,
            $wrongRejected,
            $this->report(
                'Bramka odrzuca mniej obcych kart niż dotąd (regresja).',
                $wrongRejected,
                self::MIN_WRONG_REJECTED,
                'Obce karty, które przeszły przez bramkę',
                $wrongKeptList,
                $unknownKept
            )
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_KEPT,
            $expectedKept,
            $this->report(
                'Bramka odrzuca własne karty produktów (regresja).',
                $expectedKept,
                self::MIN_EXPECTED_KEPT,
                'Własne karty, które bramka wyrzuciła',
                $expectedDroppedList,
                $unknownKept
            )
        );
    }

    /**
     * @param  list<string>  $offenders
     */
    private function report(
        string $headline,
        int $measured,
        int $threshold,
        string $listLabel,
        array $offenders,
        int $unknownKept
    ): string {
        $lines = [
            $headline,
            "Zmierzono: {$measured}, próg: {$threshold}.",
            "Zachowanych źródeł `unknown` (tylko informacyjnie): {$unknownKept}.",
            $listLabel.' ('.count($offenders).'):',
        ];
        foreach ($offenders as $offender) {
            $lines[] = '  - '.$offender;
        }

        return implode("\n", $lines);
    }
}
