<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductDocument;
use App\Support\CertificateLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lista „Certyfikaty” karty: do 04.10.2026 każdy plik „certificate” dawał „Certyfikat producenta” (456 kart
 * Ansella, czeska DEKLARACE Canisa, deklaracja opakowania PPWR). Tytuły i adresy poniżej są z product_documents
 * produkcji (odczyt 04.10.2026), wpisy list — z enrichment_payload.certificates.
 */
final class CertificateLabelsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function documents(): array
    {
        return [
            'Canis DEKLARACE (czeska deklaracja)' => [
                '48699_DEKLARACE PVC-45G A PVC-45W_PL.PDF',
                'https://www.canis.cz/imgserver/eshop/CANIS/19/2000000352/48699_DEKLARACE%20PVC-45G%20A%20PVC-45W_PL.PDF',
                CertificateLabels::DECLARATION,
            ],
            'Canis deklarace EU - CAT II' => [
                '9755_DEKLARACE EU - CAT II.PDF',
                'https://www.canis.cz/imgserver/eshop/CANIS/19/2000000352/9755_DEKLARACE%20EU%20-%20CAT%20II.PDF',
                CertificateLabels::DECLARATION,
            ],
            'Ansell /doc/' => [
                'Deklaracja zgodności UE.pdf',
                'https://www.ansell.com/pl/pl/products/hyflex-11-281/doc/E83ifXErbcCV72Fuuq4hnQ',
                CertificateLabels::DECLARATION,
            ],
            'Ansell declaration-of-conformity (cas-technik)' => [
                'hyflex-11-947_hyflex-r-11-947_eu_20210608_declaration-of-conformity.pdf',
                'https://cas-technik.eu/media/b4/60/bd/1702298894/hyflex-11-947_hyflex-r-11-947_eu_20210608_declaration-of-conformity.pdf?ts=1712664766',
                CertificateLabels::DECLARATION,
            ],
            'Ansell „-conformity” bez słowa declaration' => [
                'Ansell-HyFlex-11-818-Oil-Repellent-Lightweight-Glovesconformity.pdf',
                'https://example.com/Ansell-HyFlex-11-818-Oil-Repellent-Lightweight-Glovesconformity.pdf',
                CertificateLabels::DECLARATION,
            ],
            'deklaracja UE na sklepie .co.uk to nadal UE' => [
                'edge-48-129-declaration of conformity-2024.pdf',
                'https://www.gloves.co.uk/user/edge-48-129-declaration of conformity-2024.pdf',
                CertificateLabels::DECLARATION,
            ],
            'Ardon PoS(DoC)' => ['H6136_PoS(DoC)_uni.pdf', 'https://www.ardon.pl/eshop/download/product-attachment?id=20191', CertificateLabels::DECLARATION],
            'Safety Jogger DOC_' => ['DOC_BESTRUN_PL.PDF', 'https://order.safetyjogger.com/document/DeclarationofConformity/BESTRUN/DOC_BESTRUN_PL.PDF', CertificateLabels::DECLARATION],
            'Delta Plus dceuepi-certificate' => ['ASTRAL dceuepi-certificate PL', 'https://delta-plus.ppe-analytics.com/p/pl/product/a7c80dabd97a58c0cb802e179e2414d1/pdf/dceuepi-certificate', CertificateLabels::DECLARATION],
            'BIG Konformitätserklärung' => ['1900_DE_EU_Konformitätserklärung', 'https://www.big-arbeitsschutz.de/artikeldokumente/01100001900.pdf', CertificateLabels::DECLARATION],
            'Portwest declaration_eu.php (host documents.* to nie DoC)' => ['Deklaracja zgodności UE FW95', 'https://documents.portwest.com/declaration_eu.php?style=FW95&lang=PL&itemcol=FW95BKR', CertificateLabels::DECLARATION],
            'słowacka deklaracja' => ['Vyhlásenie o zhode EU', 'https://protekt.pl/deklaracje/CZ/P50_UE_SK.pdf', CertificateLabels::DECLARATION],
            'czeskie prohlášení' => ['Prohlášení o shodě', 'https://example.cz/files/prohlaseni.pdf', CertificateLabels::DECLARATION],

            'certyfikat badania typu UE' => ['EU Type Examination Certificate 0598', 'https://example.com/files/eu-type-examination-certificate-0598.pdf', CertificateLabels::TYPE_EXAMINATION],
            'certyfikat badania typu (PL)' => ['Certyfikat badania typu UE', 'https://example.com/pliki/certyfikat-badania-typu.pdf', CertificateLabels::TYPE_EXAMINATION],
            'Baumusterprüfbescheinigung' => ['EU-Baumusterprüfbescheinigung', 'https://example.de/files/baumusterpruefbescheinigung.pdf', CertificateLabels::TYPE_EXAMINATION],
            'module B' => ['Module B certificate', 'https://example.com/files/module-b.pdf', CertificateLabels::TYPE_EXAMINATION],

            'MSA ATEX certificate' => ['EU (ATEX) Lamp XPR1 — 36_Lamp-XPR1_ATEX-Certificate_EN.pdf', 'https://pl.msasafety.com/bynder/asset_download/6DB04D78-8B38-48C4-B22FBFE5E4544B23', CertificateLabels::CERTIFICATE],
            'Ardon G3427-certificate' => ['G3427-certificate-EN.pdf', 'https://www.ardon.pl/eshop/download/product-attachment?id=45983', CertificateLabels::CERTIFICATE],
            'Zertifikat' => ['Zertifikat 123.pdf', 'https://example.de/zertifikat-123.pdf', CertificateLabels::CERTIFICATE],

            // nie wyrób ŚOI z UE / nie dokument wyrobu / nic rozpoznawalnego → bez etykiety
            'Ansell PPWR (deklaracja opakowania)' => ['ppwr_declaration_of_conformity.ashx', 'https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx', null],
            'Ansell PPWR pdf z glovex' => ['PPWR_Declaration_of_Conformity-Ansell.pdf', 'https://www.glovex.com.pl/pl/p/file/83f516051a9ed6da4412d23c7b9c9186/PPWR_Declaration_of_Conformity-Ansell.pdf', null],
            'Coba Safe Contractor' => ['SC-Certificate-safe-contractor.pdf', 'https://www.coba.com/wp-content/uploads/2025/06/SC-Certificate-safe-contractor.pdf', null],
            'Ansell deklaracja UK (/ukdoc/)' => ['Deklaracja zgodności UK.pdf', 'https://www.ansell.com/us/en/products/alphatec-5000-model-516/ukdoc/pTkiQW8oDgc2tmCmlDTIJw', null],
            'Ansell deklaracja UK (_uk_ w nazwie)' => ['alphatec-23-201_alphatec®-23-201_uk_20230720_declaration of conformity.pdf', 'https://static.thesafetysupplycompany.co.uk/alphatec-23-201_alphatec%C2%AE-23-201_uk_20230720_declaration%20of%20conformity.pdf', null],
            'deklaracja kontaktu z żywnością' => ['ansell-hyflex-11-423-food-declaration-of-conformity.pdf', 'https://www.gloves.co.uk/user/products/large/ansell-hyflex-11-423-food-declaration-of-conformity.pdf', null],
            'Coba „Conformable” to nazwa maty' => ['gripfoot-conformable-en_GB.pdf', 'https://www.coba.com/datasheets/gripfoot-conformable-en_GB.pdf', null],
            'Elten plik z folderu CE bez nazwy rodzaju' => ['Typ 514_1_22_KE2507338-01-86.pdf', 'https://elten.com/data/media/documents/CE/ELTEN/EN ISO 20345/Typ 514_1_22_KE2507338-01-86.pdf', null],
            'plik .doc to nie DoC' => ['instrukcja.doc', 'https://example.com/files/karta.doc', null],
        ];
    }

    #[DataProvider('documents')]
    public function test_label_comes_from_document_name_and_address(string $title, string $url, ?string $expected): void
    {
        $this->assertSame($expected, CertificateLabels::forDocument($title, $url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notCertificates(): array
    {
        return array_map(static fn (string $v): array => [$v], [
            'CE' => 'CE', 'PPE' => 'PPE', 'ŚOI' => 'ŚOI', 'kat. II' => 'kat. II', 'Kat. II PPE' => 'Kat. II PPE',
            'Kategoria II' => 'Kategoria II', 'Kategoria 2' => 'Kategoria 2', 'Kategoria PPE' => 'Kategoria PPE',
            'Kategoria II PPE' => 'Kategoria II PPE', 'Kategoria II (PPE)' => 'Kategoria II (PPE)',
            'CE Kategoria III' => 'CE Kategoria III', 'CE kategoria III' => 'CE kategoria III', 'Kategoria II (CE)' => 'Kategoria II (CE)',
            'CE (PPE)' => 'CE (PPE)', 'znak CE' => 'znak CE', 'oznakowanie CE' => 'oznakowanie CE',
            'Kategoria I PPE zgodnie z rozporządzeniem (UE) 2016/425' => 'Kategoria I PPE zgodnie z rozporządzeniem (UE) 2016/425',
            'Kategoria I PPE (minimalne ryzyko)' => 'Kategoria I PPE (minimalne ryzyko)',
            'Kategoria I PPE (minimalne zagrożenia)' => 'Kategoria I PPE (minimalne zagrożenia)',
            'Kategoria II – środki ochrony indywidualnej' => 'Kategoria II – środki ochrony indywidualnej',
            'Kategoria II (średnie ryzyko)' => 'Kategoria II (średnie ryzyko)',
            'Zgodność z rozporządzeniem UE 2016/425' => 'Zgodność z rozporządzeniem UE 2016/425',
            'Rozporządzenie (UE) 2016/425' => 'Rozporządzenie (UE) 2016/425',
            'CE – zgodność z rozporządzeniem UE 2016/425' => 'CE – zgodność z rozporządzeniem UE 2016/425',
            'Rozporządzenie (UE) 2016/425 – kategoria II' => 'Rozporządzenie (UE) 2016/425 – kategoria II',
            'UKCA' => 'UKCA',
        ]);
    }

    #[DataProvider('notCertificates')]
    public function test_ce_marking_and_ppe_category_are_not_certificates(string $item): void
    {
        $this->assertTrue(CertificateLabels::isNotCertificate($item));
        $this->assertSame([], CertificateLabels::filter([$item]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function certificatesKept(): array
    {
        return array_map(static fn (string $v): array => [$v], [
            // numer jednostki notyfikowanej to fakt ze źródła — wpis zostaje w brzmieniu oryginału
            'CE 0493' => 'CE 0493',
            'Kategoria 3: 0334' => 'Kategoria 3: 0334',
            'Jednostka notyfikowana: MIRTA-KONTROL d.o.o. (NB 2474)' => 'Jednostka notyfikowana: MIRTA-KONTROL d.o.o. (NB 2474)',
            'Certyfikat CTC (Notified Body 0075)' => 'Certyfikat CTC (Notified Body 0075)',
            'OEKO-TEX® STANDARD 100' => 'OEKO-TEX® STANDARD 100',
            'DGUV 112-191' => 'DGUV 112-191',
            'Deklaracja zgodności UE 2016/425' => 'Deklaracja zgodności UE 2016/425',
            'EAC (TP TC019/2011)' => 'EAC (TP TC019/2011)',
            'Zgodność z REACH' => 'Zgodność z REACH',
            'Certyfikat SGS Fimko (nr 0598)' => 'Certyfikat SGS Fimko (nr 0598)',
        ]);
    }

    #[DataProvider('certificatesKept')]
    public function test_named_certificates_and_notified_body_numbers_stay(string $item): void
    {
        $this->assertFalse(CertificateLabels::isNotCertificate($item));
        $this->assertSame([$item], CertificateLabels::filter([$item]));
    }

    public function test_relabel_drops_automatic_labels_and_recounts_them_from_documents(): void
    {
        $documents = [
            new ProductDocument([
                'kind' => ProductDocument::KIND_CERTIFICATE,
                'title' => 'ppwr_declaration_of_conformity.ashx',
                'source_url' => 'https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx',
            ]),
            new ProductDocument([
                'kind' => ProductDocument::KIND_CERTIFICATE,
                'title' => 'Deklaracja zgodności UE.pdf',
                'source_url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-281/doc/E83ifXErbcCV72Fuuq4hnQ',
            ]),
            new ProductDocument([
                'kind' => ProductDocument::KIND_CERTIFICATE,
                'title' => 'Deklaracja zgodności UE.pdf',
                'source_url' => 'https://www.ansell.com/gb/en/products/hyflex-11-281/doc/DyGljNcYKgh9yJP5hKxnQ',
            ]),
            // karta techniczna nie jest certyfikatem, choćby nazwa mówiła „certificate”
            new ProductDocument([
                'kind' => ProductDocument::KIND_DATASHEET,
                'title' => 'certificate-datasheet.pdf',
                'source_url' => 'https://example.com/certificate-datasheet.pdf',
            ]),
        ];

        $this->assertSame(
            ['Kategoria 3: 0334', CertificateLabels::DECLARATION],
            CertificateLabels::relabel(['CE', 'Certyfikat producenta', 'Kategoria 3: 0334', 'Deklaracja zgodności UE', 'Kategoria III'], $documents),
        );
        // tylko deklaracja opakowania → żadnej etykiety; dawna etykieta znika
        $this->assertSame([], CertificateLabels::relabel(['Certyfikat producenta', 'CE'], [$documents[0]]));
        // przebieg drugi raz niczego nie zmienia
        $once = CertificateLabels::relabel(['Certyfikat producenta', 'OEKO-TEX® STANDARD 100'], $documents);
        $this->assertSame(['OEKO-TEX® STANDARD 100', CertificateLabels::DECLARATION], $once);
        $this->assertSame($once, CertificateLabels::relabel($once, $documents));
    }
}
