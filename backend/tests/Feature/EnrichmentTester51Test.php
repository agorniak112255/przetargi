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

    /**
     * Ile pozycji mających ≥2 karty składa opis z JEDNEJ z nich (a nie z akapitów kilku
     * naraz). To druga, niezależna miara: bramka może wpuścić obcą kartę, ale opis i tak
     * nie ma prawa opisywać dwóch wyrobów jednocześnie.
     *
     * Pomiar przed poprawką „jedna karta = jeden opis”: 10 z 18. Osiem pozycji miało opis
     * sklejony z kilku kart, w tym BUTOFLEX 650 i SOLO 990 (po 3 karty) oraz pierścień
     * zaczepowy S56212-50. Po poprawce: 18 z 18. Podnosić po każdej kolejnej zmianie.
     */
    private const MIN_SINGLE_CARD_ITEMS = 18;

    /**
     * Miara końcowa: z jakiej karty naprawdę powstaje opis, po przejściu przez bramkę.
     * Pomiary: przed etapem 1 — 3 opisy z obcej karty; po etapie 1 — 4 (opis przestał być
     * sklejanką, więc dało się w ogóle wskazać jego źródło); po etapie 2 — 0 z obcej karty
     * i 11 z karty ręcznie potwierdzonej jako właściwa.
     */
    private const MIN_DESC_FROM_EXPECTED_CARD = 11;

    /** Opisy napisane z karty obcego wyrobu — ta liczba ma tylko spadać. */
    private const MAX_DESC_FROM_WRONG_CARD = 0;

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

    public function test_opis_pochodzi_z_wlasciwej_karty(): void
    {
        $service = app(ProductEnrichmentService::class);
        $compose = new ReflectionMethod($service, 'descriptionFromConfirmedCards');
        $compose->setAccessible(true);

        $fromExpected = 0;
        $fromWrong = [];

        foreach (Tester51Fixture::items() as $item) {
            $sku = (string) $item['sku'];
            $verdicts = [];
            foreach ($item['sources'] as $source) {
                $verdicts[mb_strtolower((string) $source['url'])] = (string) $source['verdict'];
            }
            $pages = Tester51Fixture::pagesFor($sku);
            if (count($pages) < 2) {
                continue;
            }
            $product = Tester51Fixture::makeProduct($sku);
            $snippets = array_map(static fn (array $page): array => [
                'url' => (string) $page['url'],
                'title' => (string) $page['title'],
                'text' => (string) $page['text'],
            ], $pages);

            // najpierw bramka tożsamości, tak jak w prawdziwym przebiegu, dopiero potem opis
            $keep = new ReflectionMethod($service, 'keepConfirmedCardPages');
            $keep->setAccessible(true);
            $snippets = $keep->invoke($service, $product, $snippets);
            if (count($snippets) < 1) {
                continue;
            }

            $joint = (string) $compose->invoke($service, $snippets, $product);
            if ($joint === '') {
                continue;
            }
            foreach ($snippets as $snippet) {
                if ((string) $compose->invoke($service, [$snippet], $product) !== $joint) {
                    continue;
                }
                $verdict = $verdicts[mb_strtolower($snippet['url'])] ?? 'unknown';
                if ($verdict === 'expected') {
                    $fromExpected++;
                } elseif ($verdict === 'wrong') {
                    $fromWrong[] = $sku.' -> '.$snippet['url'];
                }
                break;
            }
        }

        $this->assertLessThanOrEqual(
            self::MAX_DESC_FROM_WRONG_CARD,
            count($fromWrong),
            $this->report(
                'Opis powstaje z karty innego wyrobu — regresja.',
                count($fromWrong),
                self::MAX_DESC_FROM_WRONG_CARD,
                'Pozycje z opisem z obcej karty',
                $fromWrong,
                0
            )
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_DESC_FROM_EXPECTED_CARD,
            $fromExpected,
            'Opis rzadziej pochodzi z właściwej karty niż dotąd: '.$fromExpected
                .', próg: '.self::MIN_DESC_FROM_EXPECTED_CARD.'.'
        );
    }

    public function test_opis_sklada_sie_z_jednej_karty(): void
    {
        $service = app(ProductEnrichmentService::class);
        $compose = new ReflectionMethod($service, 'descriptionFromConfirmedCards');
        $compose->setAccessible(true);

        $singleCard = 0;
        $withManyCards = 0;
        $mixedList = [];

        foreach (Tester51Fixture::items() as $item) {
            $sku = (string) $item['sku'];
            $pages = Tester51Fixture::pagesFor($sku);
            if (count($pages) < 2) {
                continue;
            }
            $withManyCards++;
            $product = Tester51Fixture::makeProduct($sku);
            $snippets = array_map(static fn (array $page): array => [
                'url' => (string) $page['url'],
                'title' => (string) $page['title'],
                'text' => (string) $page['text'],
            ], $pages);

            $joint = (string) $compose->invoke($service, $snippets, $product);
            $perCard = [];
            foreach ($snippets as $snippet) {
                $perCard[] = (string) $compose->invoke($service, [$snippet], $product);
            }
            // Opis złożony ze wszystkich kart musi być identyczny z opisem z którejś
            // pojedynczej karty — inaczej powstał z akapitów kilku różnych wyrobów.
            if ($joint === '' || in_array($joint, $perCard, true)) {
                $singleCard++;

                continue;
            }
            $mixedList[] = $sku.' ('.count($snippets).' kart)';
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_SINGLE_CARD_ITEMS,
            $singleCard,
            $this->report(
                'Opisy sklejone z kilku kart — regresja.',
                $singleCard,
                self::MIN_SINGLE_CARD_ITEMS,
                'Pozycje z opisem z więcej niż jednej karty (na '.$withManyCards.' z ≥2 kartami)',
                $mixedList,
                0
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
