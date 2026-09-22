<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audyt ręcznych cenników 22.09.2026 (C10): ogólne dokumenty firmowe wisiały na setkach kart jako
 * „certyfikat”. Adresy i tytuły poniżej są dosłownie z bazy (manual.json).
 */
final class CompanyDocumentsAreNotCertificatesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function companyDocuments(): array
    {
        return [
            // Ansell: deklaracja opakowaniowa PPWR przy 387 kartach — „declaration of conformity”, ale opakowania
            'Ansell PPWR .ashx' => ['https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx ppwr_declaration_of_conformity.ashx'],
            'Ansell PPWR pdf' => ['https://www.ansell.com/files/PPWR_Declaration_of_Conformity-Ansell.pdf'],
            'deklaracja opakowaniowa' => ['https://producent.pl/pliki/deklaracja-opakowaniowa-2025.pdf'],
            'packaging declaration' => ['https://producent.com/files/packaging-declaration.pdf'],
            // Coba: certyfikat wykonawcy i polityka ESG firmy
            'Coba safe contractor' => ['https://www.coba.com/wp-content/uploads/2025/06/SC-Certificate-safe-contractor.pdf'],
            'Coba ESG' => ['https://www.coba.com/wp-content/uploads/2026/02/COBA-ESG-Policy.pdf'],
            // Canis: ulotka (czeski „leták”) i materiały reklamowe
            'Canis letak' => ['http://canissafety.cz/download/Letak/letak-cz.pdf letak-cz.pdf'],
            'Canis publicita' => ['https://www.canissafety.cz/wp-content/uploads/2024/07/Canis-publicita.pdf'],
            'ulotka reklamowa' => ['https://sklep.pl/files/ulotka-promocja-2025.pdf'],
            'marketing leaflet' => ['https://producent.com/files/spring-promo-leaflet.pdf'],
            'marketing leaflet w etykiecie' => ['https://producent.com/download/551 Marketing leaflet 2025'],
        ];
    }

    #[DataProvider('companyDocuments')]
    public function test_company_documents_are_junk(string $hay): void
    {
        $this->assertTrue(ProductDocumentDownloader::looksLikeJunkDocument($hay), $hay);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function productDocuments(): array
    {
        return [
            // „karta katalogowa” to dokument wyrobu — gołego „katalog” nie ma na liście
            'karta katalogowa' => ['https://www.secura.pl/1620289367-karta-katalogowa-polmaska-secura-3000.pdf'],
            'Canis deklarace eu' => ['https://www.canis.cz/imgserver/eshop/canis/19/2000000352/100661-deklarace%20eu.pdf'],
            'Ansell DoC' => ['https://www.ansell.com/pl/pl/doc/ringers-r259'],
            // „esd” w nazwie rękawicy to nie ESG
            'ESD w nazwie' => ['https://sklep.pl/rekawice-antyprzecieciowe-cxs-cut-defend-armex-esd-karta-katalogowa.pdf'],
            // ulotka informacyjna = instrukcja ŚOI, dokument przetargowy
            'information leaflet' => ['https://producent.com/files/user-information-leaflet-60549.pdf'],
            'ulotka informacyjna' => ['https://producent.pl/pliki/ulotka-informacyjna-polmaska.pdf'],
            // JSP nazywa kartę techniczną wyrobu „product leaflet” — gołe „leaflet”/„ulotka” to nie reklama
            'JSP product leaflet' => ['https://www.jspsafety.com/files/leaflets/AJF170-160-000-Product-Leaflet-EN.pdf Product leaflet'],
            'ulotka produktu' => ['https://sklep.pl/pliki/ulotka-polmaska-secura-3000.pdf'],
        ];
    }

    #[DataProvider('productDocuments')]
    public function test_product_documents_stay(string $hay): void
    {
        $this->assertFalse(ProductDocumentDownloader::looksLikeJunkDocument($hay), $hay);
    }

    public function test_packaging_declaration_is_not_a_certificate_kind(): void
    {
        $this->assertNotSame('certificate', $this->guessKind(
            'https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx'
        ));
        $this->assertNotSame('certificate', $this->guessKind('https://sklep.pl/download/file/id/9', 'Deklaracja opakowaniowa'));
        // deklaracja zgodności wyrobu zostaje certyfikatem
        $this->assertSame('certificate', $this->guessKind('https://sklep.pl/download/file/id/10', 'Deklaracja zgodności UE'));
        $this->assertSame('certificate', $this->guessKind('https://www.canis.cz/imgserver/eshop/canis/19/2000000352/100661-deklarace%20eu.pdf'));
    }

    public function test_page_fetcher_does_not_take_unbound_packaging_declaration(): void
    {
        $method = new ReflectionMethod(ProductPageFetcher::class, 'looksLikeCertificateDocument');
        $method->setAccessible(true);
        $fetcher = app(ProductPageFetcher::class);

        $this->assertFalse($method->invoke($fetcher, 'https://www.ansell.com/files/ppwr_declaration_of_conformity.ashx'));
        $this->assertTrue($method->invoke($fetcher, 'https://www.ansell.com/files/eu_declaration_of_conformity.pdf'));
        $this->assertTrue($method->invoke($fetcher, 'https://cdn.example.com/docs/60549_doc_en.pdf'));
    }

    private function guessKind(string $url, string $label = ''): string
    {
        $method = new ReflectionMethod(ProductDocumentDownloader::class, 'guessKind');
        $method->setAccessible(true);

        /** @var string $kind */
        $kind = $method->invoke(new ProductDocumentDownloader, $url, $label);

        return $kind;
    }
}
