<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Przegląd wzorców 25.09.2026 (search:eval na produkcji): nazwany model przepuszczał inne modele tej samej linii
 * z 90–99%, a automat przetargu mógł zapisać zły wyrób. Trzy przyczyny:
 * - „HYCRON 27-600” — kod Ansella „NN-NNN” brany za zakres rozmiarów, igłą zostawała sama linia „hycron”
 *   (27-805, 27-602, 27-905 też 94%);
 * - „SOLUS S1201SGAF” — tolerancja literówki przepuszczała inną cyfrę (S1202SGAF szare, S1101SGAF niebieska oprawa);
 * - „Araukan 940” — igła „araukan940” jest początkiem „araukan9403” w tekście sklejonym bez spacji.
 * Nazwy kart jak na produkcji (sonda z 25.09).
 */
final class ProductModelFuzzyModelNumberTest extends TestCase
{
    use RefreshDatabase;

    private const HYCRON = 'Rękawice powlekane nitrylem Ansell HYCRON 27-600 · EN ISO 21420 EN 388';

    private const SOLUS = 'Okulary ochronne 3M SOLUS S1201SGAF - EU';

    private const ARAUKAN = 'Trzewiki Araukan 940 6060 S3 · EN ISO 20345';

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: bool}> */
    public static function cards(): iterable
    {
        yield 'hycron 27600' => [self::HYCRON, '27600110', 'ActivArmr Hycron 27600', true];
        yield 'hycron 27-805' => [self::HYCRON, '27805110', 'ActivArmr Hycron 27805', false];
        yield 'hycron 27602' => [self::HYCRON, '27602100', 'ActivArmr Hycron 27602', false];
        yield 'hycron 27-805 z myślnikiem' => [self::HYCRON, 'RAHYCRON27-805', 'Antystatyczne rękawice Hycron® 27-805.', false];
        yield 'solus S1201SGAF' => [self::SOLUS, '7100078882', '3M™ Solus™ 1000 Okulary ochronne, zielono/czarne oprawki, przezroczyste soczewki, S1201SGAF-EU, 20 szt./opakowanie', true];
        yield 'solus zestaw S1201SGAFKT' => [self::SOLUS, 'S1201SGAFKT-EU', 'Okulary 3M Solus 1000 bezbarwne z powłoką Scotchgard(R), zestaw zausznik zielono-czarny', true];
        yield 'solus szare S1202SGAF' => [self::SOLUS, '7100078883', '3M™ Solus™ 1000 Okulary ochronne, zielono/czarne oprawki, szare soczewki, S1202SGAF-EU, 20 szt./opakowanie', false];
        yield 'solus niebieska S1101SGAF' => [self::SOLUS, '7100080258', '3M™ Solus™ 1000 Okulary ochronne, niebiesko/czarne oprawki, przezroczyste soczewki, S1101SGAF-EU, 20 szt./opakowanie', false];
        yield 'araukan 940' => [self::ARAUKAN, 'ARAUKAN 940 6060 S3', 'ARAUKAN 940 6060 S3', true];
        yield 'araukan 940 CI' => [self::ARAUKAN, 'ARAUKAN 940 6060 S3 CI', 'ARAUKAN 940 6060 S3 CI', true];
        yield 'araukan 9403' => [self::ARAUKAN, 'ARAUKAN 9403 6060 S3L', 'ARAUKAN 9403 6060 S3L', false];
        // Sonda na produkcji: tolerancja literówki robiła z „r3000” (SECAIR 3000.02) jedną zmianę od „g3000”.
        yield 'pasek G3000' => [self::G3000, '3MGH4', 'Pasek podbródkowy 3-punktowy do hełmów ochronnych 3M G3000', true];
        yield 'filtr SECAIR 3000.02' => [self::G3000, 'S56322S2', 'Filtr dwustronny SECAIR 3000.02 klasy P2', false];
        yield 'filtr SECAIR 3000' => [self::G3000, 'S56320', 'Filtr SECAIR 3000', false];
    }

    private const G3000 = 'Pasek podbródkowy 3 punktowy do hełmu G3000';

    #[DataProvider('cards')]
    public function test_named_model_number_must_match_exactly(string $query, string $sku, string $name, bool $expected): void
    {
        $product = new Product;
        $product->forceFill(['sku' => $sku, 'name' => $name, 'manufacturer' => 'X']);

        $this->assertSame($expected, $this->fuzzy()->matches($query, $product), $name);
    }

    public function test_ansell_code_is_part_of_named_model_needle(): void
    {
        $this->assertContains('hycron27600', $this->fuzzy()->needles(self::HYCRON));
    }

    /**
     * Karta dystrybutora, na której numer modelu jest całym SKU, a linia stoi w nazwie (produkcja #55081:
     * „27-600 | Rękawice ACTIVEARMR … (dawniej HYCRON)”), to ten sam wyrób — nie może odpaść razem z innymi modelami.
     */
    public function test_card_with_model_number_as_sku_and_line_in_name_matches(): void
    {
        $product = new Product;
        $product->forceFill(['sku' => '27-600', 'name' => 'Rękawice ACTIVEARMR powlekane niebieskim nitrylem, powleczenie 3/4, ściągacz (dawniej HYCRON)', 'manufacturer' => 'ANSELL']);
        $other = new Product;
        $other->forceFill(['sku' => '27-602', 'name' => 'Rękawice ACTIVEARMR powlekane niebieskim nitrylem, całość powleczona, ściągacz (dawniej HYCRON)', 'manufacturer' => 'ANSELL']);

        $this->assertTrue($this->fuzzy()->matches(self::HYCRON, $product));
        $this->assertFalse($this->fuzzy()->matches(self::HYCRON, $other));
    }

    /** Prawdziwe zakresy rozmiarów dalej nie tworzą igieł modelu. */
    public function test_size_ranges_are_still_not_model_numbers(): void
    {
        foreach ([
            'Trzewiki robocze S3 rozmiary 36-48',
            'Kurtka ostrzegawcza rozmiar 46-64',
            'Spodnie ogrodniczki wzrost 164-176',
            'Kurtka ocieplana obwód klatki 84-140',
            'Spodnie robocze obwód pasa 70-130',
            'Ubranie robocze wzrost 152-200',
        ] as $query) {
            $sizeNeedles = array_filter(
                $this->fuzzy()->needles($query),
                static fn (string $needle): bool => preg_match('/(3648|4664|164176|84140|70130|152200)/', $needle) === 1,
            );
            $this->assertSame([], array_values($sizeNeedles), $query);
        }
    }

    /** Kody Ansella „NN-NNN” to numer modelu, nie zakres rozmiarów. */
    public function test_ansell_codes_are_model_numbers(): void
    {
        $this->assertContains('alphatec23202', $this->fuzzy()->needles('rękawice chemiczne Ansell AlphaTec 23-202'));
        $this->assertContains('hyflex11100', $this->fuzzy()->needles('Rękawice Ansell HyFlex 11-100 EN 388'));
    }

    /** Literówka w literach nazwy modelu dalej jest wybaczana — tylko cyfry muszą się zgadzać. */
    public function test_letter_typo_in_model_name_is_still_tolerated(): void
    {
        $product = new Product;
        $product->forceFill(['sku' => 'TEMP-ICE-700', 'name' => 'Rękawice MAPA TEMP-ICE 700', 'manufacturer' => 'MAPA']);

        $this->assertTrue($this->fuzzy()->matches('Rękawice MAPA TEPM-ICE 700 EN 388 EN 511', $product));
    }

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }
}
