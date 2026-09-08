<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductAccessoryExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductAccessoryExtractorTest extends TestCase
{
    #[Test]
    public function reads_presta_accessories_block(): void
    {
        $html = <<<'HTML'
        <h2>Warianty produktu i akcesoria</h2>
        <div class="product-accessories">
          <article class="product-miniature">
            <h3 class="product-title"><a href="/289-pochlaniacz.html">Pochłaniacz wielogazowy Secura 3025 ABEK1</a></h3>
            <span class="product-manufacturer">SECURA</span>
            <span class="product-reference">3025</span>
          </article>
          <article class="product-miniature">
            <h3 class="product-title"><a href="/297-polmaska.html">Półmaska ochronna Secura 3000</a></h3>
            <span itemprop="sku">SEC-3000</span>
          </article>
        </div>
        <h2>Klienci kupili również</h2>
        <div class="related-products"><a>Obca kurtka</a></div>
        HTML;

        $rows = (new ProductAccessoryExtractor)->fromHtml($html);
        $names = array_column($rows, 'name');

        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($names)->contains(fn (string $n): bool => str_contains($n, '3025')));
        $this->assertFalse(collect($names)->contains(fn (string $n): bool => str_contains(mb_strtolower($n), 'kurtka')));
    }

    #[Test]
    public function reads_json_ld_accessories(): void
    {
        $html = <<<'HTML'
        <script type="application/ld+json">
        {"@type":"Product","name":"Maska","isAccessoryOrSparePartFor":[{"name":"Pochłaniacz 3025","sku":"3025"}]}
        </script>
        HTML;

        $rows = (new ProductAccessoryExtractor)->fromHtml($html);

        $this->assertCount(1, $rows);
        $this->assertSame('3025', $rows[0]['sku']);
        $this->assertSame('Pochłaniacz 3025', $rows[0]['name']);
    }

    #[Test]
    public function ignores_faq_colors_and_shop_chrome(): void
    {
        $html = <<<'HTML'
        <h2>Czy polar HONEY pasuje do innych elementów odzieży roboczej?</h2>
        <p>Tak, łatwo łączy się z inną odzieżą roboczą.</p>
        <select><option>JASNONIEBIESKI</option><option>Czerwony</option></select>
        <p>ART. BHP</p>
        <p>SAFETY JOGGER</p>
        <p>SEMPERGUARD</p>
        <p>Odzież Art.Mas</p>
        <p>PRINTER RED</p>
        HTML;

        $this->assertSame([], (new ProductAccessoryExtractor)->fromHtml($html));
        $this->assertSame([], (new ProductAccessoryExtractor)->fromText(strip_tags($html)));
    }
}
