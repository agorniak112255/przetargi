<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CompanyName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'sp. z o.o.' => ['ABC Sp. z o.o.', 'abc'],
            'sp. z o. o. ze spacją' => ['ABC sp. z o. o.', 'abc'],
            'spółka z o.o.' => ['ABC Spółka z o.o.', 'abc'],
            'pełna nazwa formy' => ['ABC SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'abc'],
            'S.A.' => ['Polska Grupa BHP S.A.', 'polska grupa bhp'],
            'SA bez kropek' => ['Polska Grupa BHP SA', 'polska grupa bhp'],
            'spółka akcyjna' => ['Polska Grupa BHP spółka akcyjna', 'polska grupa bhp'],
            // nazwa z wyniku z Biuletynu (próbka 2026/BZP 00416394/01)
            'sp. k. i cudzysłów' => ['Przedsiębiorstwo Wielobranżowe "MADA" Kosiec i Wspólnicy Sp. k.', 'przedsiebiorstwo wielobranzowe mada kosiec i wspolnicy'],
            'sp.k. bez spacji' => ['MADA Kosiec i Wspólnicy sp.k.', 'mada kosiec i wspolnicy'],
            'sp. j.' => ['Kowalski i Syn Sp. J.', 'kowalski i syn'],
            's.c.' => ['Rękawice Nowak s.c.', 'rekawice nowak'],
            'polskie cudzysłowy' => ['„Ochrona” Sp. z o.o.', 'ochrona'],
            'dwie formy naraz' => ['ABC Sp. z o.o. Sp. k.', 'abc'],
            'interpunkcja i wielokrotne spacje' => ['  P.P.H.U.   Tom-Pol,  Rzeszów ', 'p p h u tom pol rzeszow'],
            'ampersand' => ['A&B Sp. z o.o.', 'a & b'],
            'ampersand ze spacjami' => ['A & B sp. z o.o.', 'a & b'],
            'skrót SC na początku nazwy zostaje' => ['SC Johnson', 'sc johnson'],
            'sama forma prawna' => ['Sp. z o.o.', 'sp z o o'],
        ];
    }

    #[DataProvider('names')]
    public function test_key(string $name, string $expected): void
    {
        $this->assertSame($expected, CompanyName::key($name));
    }

    public function test_same_company_two_spellings_give_same_key(): void
    {
        $this->assertSame(
            CompanyName::key('ROZWIĄZANIA BIUROWE ROMAN KRZYŻANEK'),
            CompanyName::key('Rozwiązania Biurowe Roman Krzyżanek'),
        );
        $this->assertSame(CompanyName::key('Supon Sp. z o.o.'), CompanyName::key('SUPON'));
    }

    public function test_empty_name(): void
    {
        $this->assertSame('', CompanyName::key(null));
        $this->assertSame('', CompanyName::key('  '));
        $this->assertSame('', CompanyName::key('„”.,'));
    }

    public function test_key_is_limited_to_column_length(): void
    {
        $this->assertSame(191, mb_strlen(CompanyName::key(str_repeat('ą', 300))));
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function nips(): array
    {
        return [
            // z próbek wyników Biuletynu
            'z etykietą i myślnikami' => ['NIP: 118-16-25-269', '1181625269'],
            'same cyfry' => ['9720249933', '9720249933'],
            'prefiks kraju' => ['PL 972-024-99-33', '9720249933'],
            'NIP z REGON-em w jednym polu' => ['NIP: 118-16-25-269, REGON: 012345678', '1181625269'],
            'zła suma kontrolna' => ['1181625260', null],
            'za krótki' => ['118162526', null],
            'same zera' => ['0000000000', null],
            'REGON zamiast NIP' => ['REGON: 012345678', null],
            'pusty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('nips')]
    public function test_nip(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, CompanyName::nip($value));
    }

    public function test_checksum_ten_is_never_valid(): void
    {
        // 1234567890: suma ważona 6+10+21+8+15+24+35+48+63 = 230, 230 % 11 = 10 → niepoprawny
        $this->assertFalse(CompanyName::validNip('1234567890'));
        $this->assertTrue(CompanyName::validNip('5262200493'));
    }
}
