<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Strona Ansell HyFlex 11-202 (53 tys. znaków) ma sekcję „Normy i certyfikaty” od ok. 23 tys. znaku, a model dostaje 8000 znaków
 * z każdej strony. Karta dostała tylko EN 407 i ANSI ze wstępu, bez EN 388:2016+A1:2018 1X42C, EN ISO 21420 i kategorii II.
 */
final class EnrichmentPageBudgetNormsTest extends TestCase
{
    public function test_norms_beyond_page_budget_are_moved_verbatim_to_the_start_of_the_text(): void
    {
        $intro = str_repeat('Rękaw ochronny HyFlex 11-202 o wysokiej widoczności zapewnia komfort i swobodę ruchów podczas pracy. ', 110);
        $norms = "### Normy i certyfikaty\n\n"
            ."![Image 10: EN 388:2016 +A1:2018](https://www.ansell.com/-/media/projects/ansell/website/pim/taxonomy/standards/en-388/en388_2016.ashx?mh=50) 1X42C\n\n"
            ."![Image 11: EN ISO 21420:2020](https://www.ansell.com/-/media/en-iso-21420.ashx)\n\n"
            ."![Image 12: EN 407:2020](https://www.ansell.com/-/media/en-407.ashx) X1XXXX\n\n"
            ."Kategoria II\n\nZgodność z REACH\n";
        $page = ['url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-202', 'text' => $intro."\n".$norms."\nStopka strony."];
        $this->assertGreaterThan(8000, mb_strpos($page['text'], 'EN 388'), 'normy stoją za granicą przycięcia');

        $out = $this->fit([$page]);

        $text = $out[0]['text'];
        $this->assertLessThanOrEqual(8000, mb_strlen($text), 'budżet strony bez zmian');
        $this->assertStringStartsWith('Normy i oznaczenia z dalszej części strony:', $text);
        $this->assertStringContainsString('EN 388:2016 +A1:2018 1X42C', $text);
        $this->assertStringContainsString('EN ISO 21420:2020', $text);
        $this->assertStringContainsString('EN 407:2020 X1XXXX', $text);
        $this->assertStringContainsString('Kategoria II', $text);
        $this->assertStringNotContainsString('ansell.com/-/media', $text, 'bez adresów obrazków');
        $this->assertStringNotContainsString('REACH', $text, 'tylko normy, poziomy i kategoria');
        $this->assertStringContainsString('Rękaw ochronny HyFlex 11-202', $text, 'początek strony zostaje');
    }

    public function test_short_page_and_norms_inside_budget_are_left_alone(): void
    {
        $short = ['url' => 'https://shop.example/p', 'text' => 'Rękawice X. Norma EN 388:2016 4X42C.'];
        $this->assertSame($short['text'], $this->fit([$short])[0]['text']);

        $inside = ['url' => 'https://shop.example/q', 'text' => "Normy: EN 388:2016 4X42C\n".str_repeat('Opis produktu. ', 700)."\nEN 388:2016 4X42C"];
        $text = $this->fit([$inside])[0]['text'];
        $this->assertStringStartsWith('Normy: EN 388:2016 4X42C', $text, 'norma widoczna w limicie nie jest powtarzana');
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function fit(array $pages): array
    {
        $service = app(ProductEnrichmentService::class);

        return (new ReflectionMethod($service, 'fitPagesToBudget'))->invoke($service, $pages, 4, 8000, 20000);
    }
}
