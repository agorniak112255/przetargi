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
