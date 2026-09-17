<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductDocumentDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Zakładka „Pliki PDF”: polityka prywatności sklepu i obce deklaracje zgodności.
 * Filtr haseł działa na adresie i etykiecie, a treść pobranego pliku rozstrzyga resztę.
 */
final class ProductDocumentContentFilterTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function junkDocuments(): array
    {
        return [
            ['https://sklep.pl/upload/polityka-prywatnosci.pdf'],
            ['https://sklep.pl/upload/polityka_prywatności.pdf'],
            ['https://sklep.pl/doc/Polityka%20prywatnosci%20sklepu.pdf'],
            ['https://sklep.pl/files/regulamin-sklepu.pdf'],
            ['https://sklep.pl/files/klauzula-informacyjna-rodo.pdf'],
            ['https://sklep.pl/files/RODO.pdf'],
            ['https://sklep.pl/files/ochrona-danych-osobowych.pdf'],
            ['https://sklep.pl/files/cookies.pdf'],
            ['https://sklep.pl/files/formularz-reklamacyjny.pdf'],
            ['https://sklep.pl/files/zgloszenie-reklamacji.pdf'],
            ['https://sklep.pl/files/odstapienie-od-umowy.pdf'],
            ['https://sklep.pl/files/zwroty-i-wymiany.pdf'],
            ['https://sklep.pl/files/warunki-sprzedazy.pdf'],
            ['https://sklep.pl/files/warunki-wspolpracy.pdf'],
            ['https://sklep.pl/files/koszty-dostawy.pdf'],
            ['https://sklep.pl/files/dostawa.pdf'],
            ['https://sklep.pl/files/platnosc.pdf'],
            ['https://sklep.pl/files/sposoby-platnosci.pdf'],
            ['https://producent.de/media/datenschutz.pdf'],
            ['https://producent.com/media/sustainability-report-2024.pdf'],
        ];
    }

    #[DataProvider('junkDocuments')]
    public function test_rejects_junk_document_urls(string $url): void
    {
        $this->assertTrue(ProductDocumentDownloader::looksLikeJunkDocument($url), $url);
    }

    public function test_rejects_junk_by_link_label_when_url_says_nothing(): void
    {
        $this->assertTrue(ProductDocumentDownloader::looksLikeJunkDocument(
            'https://sklep.pl/download/file/id/238/ Polityka prywatności'
        ));
        $this->assertTrue(ProductDocumentDownloader::looksLikeJunkDocument(
            'https://sklep.pl/download/file/id/240/ Regulamin sklepu internetowego'
        ));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function productDocuments(): array
    {
        return [
            ['https://cdn.example.com/DATASHEET/60549_PDB_EN.pdf'],
            ['https://producent.pl/pliki/deklaracja-zgodnosci-60549.pdf'],
            // „środowisko” zawiera w sobie „rodo” — hasło musi być całym słowem
            ['https://producent.pl/pliki/deklaracja-srodowiskowa-60549.pdf'],
            ['https://producent.pl/pliki/karta-techniczna-c300-dry.pdf'],
            ['https://www.ansell.com/pl/pl/doc/ringers-r259'],
        ];
    }

    #[DataProvider('productDocuments')]
    public function test_keeps_real_product_documents(string $url): void
    {
        $this->assertFalse(ProductDocumentDownloader::looksLikeJunkDocument($url), $url);
    }

    public function test_rejects_pdf_whose_text_is_a_privacy_policy(): void
    {
        $text = "POLITYKA PRYWATNOŚCI\n\nAdministratorem danych osobowych jest sklep ABC sp. z o.o. "
            .str_repeat('Dane przetwarzamy zgodnie z obowiązującymi przepisami. ', 40);

        $this->assertSame(
            'treść to polityka prywatności / regulamin',
            $this->textRejects($text, 'https://sklep.pl/download/file/id/238', $this->uvexGlove())
        );
    }

    public function test_rejects_pdf_whose_text_is_about_another_product(): void
    {
        $text = 'DEKLARACJA ZGODNOŚCI UE nr 12/2024. Rękawice ochronne ALPHATEC 58-735 spełniają wymagania '
            .'rozporządzenia (UE) 2016/425. '
            .str_repeat('Jednostka notyfikowana SATRA nr 2777 przeprowadziła badanie typu. ', 10);

        $this->assertSame(
            'treść nie wspomina o tym wyrobie',
            $this->textRejects($text, 'https://sklep.pl/pliki/deklaracja.pdf', $this->uvexGlove())
        );
    }

    public function test_keeps_pdf_whose_text_names_the_product(): void
    {
        $text = 'DEKLARACJA ZGODNOŚCI UE. Rękawice uvex C300 dry, nr artykułu 60549, spełniają wymagania '
            .'rozporządzenia (UE) 2016/425. '
            .str_repeat('Jednostka notyfikowana przeprowadziła badanie typu EU. ', 10);

        $this->assertNull(
            $this->textRejects($text, 'https://sklep.pl/pliki/deklaracja.pdf', $this->uvexGlove())
        );
    }

    public function test_keeps_scanned_pdf_without_text_layer(): void
    {
        // skan certyfikatu: pdftotext oddaje pustkę albo samą metryczkę — o przyjęciu decyduje adres,
        // bo wyrzucenie prawdziwego certyfikatu jest gorsze niż zostawienie niepewnego pliku
        $product = $this->uvexGlove();

        $this->assertNull($this->textRejects('', 'https://producent.pl/pliki/doc.pdf', $product));
        $this->assertNull($this->textRejects(
            "Scanned by CamScanner\nDEKLARACJA",
            'https://producent.pl/pliki/doc.pdf',
            $product
        ));
    }

    public function test_keeps_pdf_when_url_already_carries_the_product_code(): void
    {
        // kod w nazwie pliku wiąże dokument z wyrobem; tekst bywa po niemiecku albo w tabeli,
        // której pdftotext nie składa w rozpoznawalny ciąg
        $text = str_repeat('Konformitätserklärung gemäß Verordnung (EU) 2016/425 für Schutzhandschuhe. ', 20);

        $this->assertNull(
            $this->textRejects($text, 'https://cdn.example.com/DATASHEET/60549_PDB_DE.pdf', $this->uvexGlove())
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> etykieta, adres, oczekiwany rodzaj
     */
    public static function documentKinds(): array
    {
        return [
            // etykieta rozstrzyga, bo adres to sam numer pliku — tak wyglądają karty sklepów
            'etykieta: instrukcja' => ['Instrukcja obsługi', 'https://sklep.pl/download/file/id/238', 'manual'],
            'etykieta: instrukcja użytkownika' => ['Instrukcja użytkownika - PL', 'https://sklep.pl/download/file/id/239', 'manual'],
            'etykieta: gwarancja' => ['Karta gwarancyjna', 'https://sklep.pl/download/file/id/240', 'warranty'],
            'etykieta: tabela rozmiarów' => ['Tabela rozmiarów', 'https://sklep.pl/download/file/id/241', 'size_chart'],
            // „karta produktowa” nie może zostać przeciągnięta do instrukcji ani do gwarancji
            'etykieta: karta produktowa' => ['Karta produktowa', 'https://sklep.pl/download/file/id/242', 'datasheet'],
            'etykieta: deklaracja' => ['Deklaracje zgodności - PL', 'https://sklep.pl/download/file/id/243', 'certificate'],
            // adres, gdy etykiety nie ma
            'adres: instrukcja' => ['', 'https://producent.pl/pliki/instrukcja-obslugi-60549.pdf', 'manual'],
            'adres: user guide' => ['', 'https://producent.com/files/c300-user-guide.pdf', 'manual'],
            'adres: ifu' => ['', 'https://www.ansell.com/-/media/ifu/59-lite.ashx', 'manual'],
            'adres: gwarancja' => ['', 'https://producent.pl/pliki/karta-gwarancyjna.pdf', 'warranty'],
            'adres: size chart' => ['', 'https://producent.com/files/size-chart-gloves.pdf', 'size_chart'],
            // adres protokołowo względny — tak linkują niektóre sklepy
            'adres bez protokołu: instrukcja' => ['', '//cdn.producent.pl/pliki/instrukcja.pdf', 'manual'],
            'adres bez protokołu: rozmiary' => ['', '//cdn.producent.pl/pliki/tabela-rozmiarów.pdf', 'size_chart'],
            // polskie znaki w etykiecie i w adresie (także zakodowane w %)
            'polskie znaki w adresie' => ['', 'https://producent.pl/pliki/tabela%20rozmiar%C3%B3w.pdf', 'size_chart'],
            // dotychczasowe rozpoznanie zostaje nienaruszone
            'adres: deklaracja' => ['', 'https://www.ansell.com/pl/pl/doc/ringers-r259', 'certificate'],
            'adres: karta produktu' => ['', 'https://www.ansell.com/pl/pl/pds/ringers-r259', 'datasheet'],
            'adres: nic nie mówi' => ['', 'https://sklep.pl/files/12345.pdf', 'other'],
        ];
    }

    #[DataProvider('documentKinds')]
    public function test_recognises_document_kind(string $label, string $url, string $expected): void
    {
        $this->assertSame($expected, $this->guessKind($url, $label), $label.' | '.$url);
    }

    public function test_label_wins_over_address_for_new_kinds(): void
    {
        // plik leży w katalogu „karty”, ale link mówi wprost, że to instrukcja
        $this->assertSame(
            'manual',
            $this->guessKind('https://sklep.pl/karta/12345.pdf', 'Instrukcja obsługi')
        );
    }

    public function test_new_kinds_get_polish_titles(): void
    {
        $this->assertSame(
            'Instrukcja obsługi.pdf',
            $this->guessTitle('https://sklep.pl/download/file/id/238', 'manual', 'Instrukcja obsługi')
        );
        $this->assertSame(
            'Karta gwarancyjna.pdf',
            $this->guessTitle('https://sklep.pl/download/file/id/240', 'warranty', 'Karta gwarancyjna')
        );
        $this->assertSame(
            'Tabela rozmiarów.pdf',
            $this->guessTitle('https://sklep.pl/download/file/id/241', 'size_chart', 'Tabela rozmiarów')
        );
        // nazwy dotychczasowych rodzajów zostają bez zmian — nazwa pliku niesie więcej niż etykieta
        $this->assertSame(
            'deklaracja-zgodnosci-60549.pdf',
            $this->guessTitle('https://producent.pl/pliki/deklaracja-zgodnosci-60549.pdf', 'certificate')
        );
    }

    private function guessKind(string $url, string $label = ''): string
    {
        $method = new ReflectionMethod(ProductDocumentDownloader::class, 'guessKind');
        $method->setAccessible(true);

        /** @var string $kind */
        $kind = $method->invoke(new ProductDocumentDownloader, $url, $label);

        return $kind;
    }

    private function guessTitle(string $url, string $kind, string $label = ''): string
    {
        $method = new ReflectionMethod(ProductDocumentDownloader::class, 'guessTitle');
        $method->setAccessible(true);

        /** @var string $title */
        $title = $method->invoke(new ProductDocumentDownloader, $url, $kind, $label);

        return $title;
    }

    private function uvexGlove(): Product
    {
        return new Product([
            'sku' => '60549',
            'name' => 'C300 Dry',
            'manufacturer' => 'uvex',
        ]);
    }

    private function textRejects(string $text, string $url, Product $product): ?string
    {
        $method = new ReflectionMethod(ProductDocumentDownloader::class, 'textRejects');
        $method->setAccessible(true);

        /** @var string|null $reason */
        $reason = $method->invoke(new ProductDocumentDownloader, $text, $url, $product);

        return $reason;
    }
}
