<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NoticeNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoticeNumberTest extends TestCase
{
    public function test_bzp_number_with_version(): void
    {
        $this->assertSame([
            'source' => 'bzp',
            'normalized' => '2026/BZP 00431178/01',
            'bzp_number' => '2026/BZP 00431178',
        ], NoticeNumber::parse('2026/BZP 00431178/01'));
    }

    public function test_bzp_number_without_version_keeps_no_version(): void
    {
        $this->assertSame([
            'source' => 'bzp',
            'normalized' => '2026/BZP 00431178',
            'bzp_number' => '2026/BZP 00431178',
        ], NoticeNumber::parse('2026/BZP 00431178'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bzpSpellings(): array
    {
        return [
            'małe litery' => ['2026/bzp 00431178/01'],
            'bez spacji' => ['2026/BZP00431178/01'],
            'kilka spacji i spacje wokół ukośników' => ['  2026 / BZP   00431178 / 01 '],
            'twarda spacja z kopiowania ze strony' => ["2026/BZP\u{00A0}00431178/01"],
        ];
    }

    #[DataProvider('bzpSpellings')]
    public function test_bzp_spacing_and_case_are_normalized(string $value): void
    {
        $parsed = NoticeNumber::parse($value);
        $this->assertNotNull($parsed);
        $this->assertSame('2026/BZP 00431178/01', $parsed['normalized']);
        $this->assertSame('2026/BZP 00431178', $parsed['bzp_number']);
    }

    public function test_ted_numbers(): void
    {
        $this->assertSame(['source' => 'ted', 'normalized' => '606345-2026', 'bzp_number' => null], NoticeNumber::parse('606345-2026'));
        // stary zapis Dziennika Urzędowego UE
        $this->assertSame(['source' => 'ted', 'normalized' => '606345-2026', 'bzp_number' => null], NoticeNumber::parse('2026/S 187-606345'));
        $this->assertSame('606345-2026', NoticeNumber::parse('2026/s 187-00606345')['normalized'] ?? null);
        $this->assertSame('606345-2026', NoticeNumber::parse('00606345-2026')['normalized'] ?? null);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'pusty' => [''],
            'same spacje' => ['   '],
            'za mało cyfr' => ['2026/BZP 431178/01'],
            'za dużo cyfr wersji' => ['2026/BZP 00431178/001'],
            'numer wewnętrzny przetargu' => ['PRZ/2026/0001'],
            'tekst z numerem w środku' => ['Ogłoszenie nr 2026/BZP 00431178/01'],
            'same zera TED' => ['000-2026'],
            'rok TED niepełny' => ['606345-26'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_other_spellings_are_rejected_without_guessing(?string $value): void
    {
        $this->assertNull(NoticeNumber::parse($value));
        $this->assertNull(NoticeNumber::source($value));
    }

    public function test_source(): void
    {
        $this->assertSame('bzp', NoticeNumber::source('2026/BZP 00431178/01'));
        $this->assertSame('ted', NoticeNumber::source('606345-2026'));
    }
}
