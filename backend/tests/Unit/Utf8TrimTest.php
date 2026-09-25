<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Utf8Trim;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Przycinanie listą ze znakami wielobajtowymi. Zestaw zdejmowanych znaków jest
 * dokładnie taki jak w trim() — zmienia się tylko to, że znak wielobajtowy
 * z listy jest całością, a nie kilkoma osobnymi bajtami.
 */
final class Utf8TrimTest extends TestCase
{
    /** Lista z InquiryQueryText::forCatalog(). */
    private const LIST = " \t\n\r\0\x0B,;:.-–—?!";

    /**
     * Znaki, których bajty są też w pauzie (E2 80 94) albo półpauzie (E2 80 93).
     *
     * @return list<array{0: string}>
     */
    public static function charactersSharingBytesWithDashes(): array
    {
        return [
            ['„Rękawice antystatyczne”'],
            ['Rękawice Ó'],
            ['Rękawice Ô'],
            ['Респіратор'],
            ['… cena w €'],
        ];
    }

    #[DataProvider('charactersSharingBytesWithDashes')]
    public function test_keeps_characters_sharing_bytes_with_listed_ones(string $text): void
    {
        $this->assertSame($text, Utf8Trim::trim($text, self::LIST));
        $this->assertSame($text, Utf8Trim::trim(' – '.$text.' — ', self::LIST));
    }

    public function test_trims_whole_multibyte_characters_only_at_the_ends(): void
    {
        $this->assertSame('Rękawice nitrylowe', Utf8Trim::trim('–— Rękawice nitrylowe —–', self::LIST));
        $this->assertSame('Buty – S3', Utf8Trim::trim('Buty – S3', self::LIST));
        $this->assertSame('Listwa', Utf8Trim::trim('• Listwa •', " \t-*•"));
        $this->assertSame('', Utf8Trim::trim('–—–', self::LIST));
        $this->assertSame('', Utf8Trim::trim('', self::LIST));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function sameAsTrim(): array
    {
        return [
            ["\0\x0B\t\n\r ,;:.-Rękawice?!\0\x0B", self::LIST],
            ['(*[Rękawice]*)', '()[]*'],
            // „+-/” to trzy znaki, nie zakres od „+” do „/” jak w klasie znaków wyrażenia
            [',Rękawice.', '+-/'],
            // spacja nierozdzielająca i \f nie stoją na liście — zostają, choć \s z /u je zdejmuje
            ["\u{00A0}Rękawice\f", " \t"],
            // trim() zatrzymuje się na \n spoza listy, a $ w wyrażeniu przeskakuje końcowe \n
            ["Rękawice –\n", ' –'],
        ];
    }

    #[DataProvider('sameAsTrim')]
    public function test_trims_the_same_set_as_trim(string $text, string $characters): void
    {
        $this->assertSame(trim($text, $characters), Utf8Trim::trim($text, $characters));
    }

    public function test_invalid_utf8_keeps_its_bytes(): void
    {
        // tekst w ISO-8859-2 („ę” = EA, „ó” = F3) — zdejmujemy tylko znaki z listy
        $this->assertSame("R\xEAkawice \xF3", Utf8Trim::trim("– R\xEAkawice \xF3 —", self::LIST));
    }
}
