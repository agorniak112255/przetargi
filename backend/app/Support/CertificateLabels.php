<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductDocument;
use App\Services\Enrichment\ProductDocumentDownloader;

/**
 * Lista „Certyfikaty” karty (enrichment_payload.certificates): etykiety plików certyfikatów pobranych przy
 * wzbogacaniu i przesiew wpisów, które certyfikatami nie są.
 *
 * Do 04.10.2026 każdy pobrany plik rodzaju „certificate” dokładał „Certyfikat producenta” — także deklaracja
 * opakowaniowa Ansella (PPWR, 341 kart), czeska „DEKLARACE EU” Canisa i certyfikat wykonawcy Safe Contractor
 * Coby. Deklaracja zgodności to nie certyfikat, a karta nie może twierdzić, że certyfikat ma. Etykieta mówi
 * teraz tylko to, co widać w nazwie pliku i adresie; nierozpoznany plik zostaje w plikach karty bez etykiety.
 */
final class CertificateLabels
{
    public const DECLARATION = 'Deklaracja zgodności UE';

    public const TYPE_EXAMINATION = 'Certyfikat badania typu UE';

    public const CERTIFICATE = 'Certyfikat (dokument PDF)';

    /** Etykieta sprzed 04.10.2026 — doklejana do każdego pliku „certificate”, bez względu na treść. */
    public const LEGACY_MANUFACTURER = 'Certyfikat producenta';

    /**
     * Wpisy, które wstawia automat, a nie model ze źródeł: przeliczane z plików karty przy każdym przebiegu.
     *
     * @var list<string>
     */
    public const AUTOMATIC = [self::DECLARATION, self::TYPE_EXAMINATION, self::CERTIFICATE, self::LEGACY_MANUFACTURER];

    /**
     * Deklaracja zgodności: polska, angielska, francuska, hiszpańska, włoska, niemiecka, czeska i słowacka
     * („prohlášení”, „vyhlásenie”), skrót DoC (Ansell „/doc/”, Ardon „PoS(DoC)”, Safety Jogger „DOC_BESTRUN”),
     * „dceuepi” Delta Plus (déclaration de conformité UE EPI) i samo „conformity” w nazwie pliku Ansella
     * („…-Gloves-conformity.pdf”). Przed generycznym certyfikatem: „dceuepi-certificate” to deklaracja.
     */
    private const DECLARATION_PATTERN = '#(deklara(?:c|t)|d[eé]clara(?:tion|ci[oó]n)|dichiarazion|[kc]onformit|prohl[aá][sš]en|vyhl[aá]sen|(?<![a-z])doc(?![a-z])|dceu)#u';

    /** Certyfikat badania typu UE (moduł B rozporządzenia 2016/425) — w nazwie wprost, bez zgadywania z numerów. */
    private const TYPE_EXAMINATION_PATTERN = '#(type ?examination|badani[ae] typu|\beu ?type\b|\bec ?type\b|\bmodu(?:le|ł|l) b\b|baumusterpr[uü]f)#u';

    private const CERTIFICATE_PATTERN = '#(certific|certyfik|zertifik|certifik)#u';

    /**
     * Deklaracja zgodności, ale nie wyrobu ŚOI z UE: brytyjska (UKCA — „/ukdoc/”, „_uk_”, „Deklaracja zgodności
     * UK”) i deklaracja kontaktu z żywnością („food declaration of conformity”, „kontakt z żywnością”).
     */
    private const NOT_EU_PPE_DECLARATION_PATTERN = '#(/ukdoc/|(?<![a-z])uk(?![a-z])|ukca|food|żywno|zywno|lebensmittel)#u';

    /**
     * Słowa, z których składa się oznakowanie CE, kategoria ŚOI i przywołanie rozporządzenia — wpis złożony
     * wyłącznie z nich nie jest certyfikatem („CE”, „Kategoria II PPE”, „Kat. III (CE)”, „Kategoria I PPE
     * (minimalne ryzyko)”, „Zgodność z rozporządzeniem UE 2016/425”). Wpis z czymkolwiek ponad to zostaje bez
     * zmian: numer jednostki notyfikowanej („CE 0493”, „Kategoria 3: 0334”), nazwa certyfikatu, norma.
     *
     * @var list<string>
     */
    private const NON_CERTIFICATE_WORDS = [
        'ce', 'ukca', 'ppe', 'soi', 'kat', 'kategoria', 'kategorii', 'kategorie', 'category', 'cat',
        'i', 'ii', 'iii', '1', '2', '3',
        'znak', 'znakiem', 'oznakowanie', 'oznakowaniem', 'oznaczenie', 'oznaczeniem', 'oznakowany', 'oznakowana',
        'oznakowane', 'marking', 'marked', 'mark',
        'zgodnie', 'zgodnosc', 'zgodnoscia', 'zgodny', 'zgodna', 'zgodne', 'z', 'ze', 'with', 'compliant',
        'compliance', 'conformity', 'according', 'to', 'in', 'wg', 'wedlug', 'dla', 'of', 'the', 'and', 'oraz',
        'rozporzadzenie', 'rozporzadzeniem', 'rozporzadzenia', 'regulation', 'dyrektywa', 'dyrektywy',
        'directive', 'ue', 'eu', 'we', 'ec', 'eec', 'ewg', 'nr', 'no',
        'srodki', 'srodek', 'srodkow', 'ochrony', 'indywidualnej', 'personal', 'protective', 'equipment',
        'minimalne', 'minimalnego', 'minimalnych', 'srednie', 'sredniego', 'wysokie', 'wysokiego', 'ryzyko',
        'ryzyka', 'zagrozenia', 'zagrozenie', 'zagrozen', 'risk', 'risks', 'minimal', 'intermediate', 'high',
        'simple', 'complex', 'design',
    ];

    /**
     * Etykieta pliku certyfikatu z jego tytułu i adresu (z nazwą pliku); null, gdy nic jej nie potwierdza —
     * plik zostaje w plikach karty, lista „Certyfikaty” go nie wymienia.
     */
    public static function forDocument(string $title, string $sourceUrl): ?string
    {
        $raw = mb_strtolower(trim($title).' '.self::urlWithoutHost($sourceUrl));
        if (trim($raw) === '') {
            return null;
        }
        // dokument firmy albo opakowania (PPWR, Safe Contractor, polityka ESG) — nie mówi nic o wyrobie
        if (ProductDocumentDownloader::looksLikeJunkDocument($raw) || ProductDocumentDownloader::looksLikePackagingDeclaration($raw)) {
            return null;
        }
        // „/doc/” sprawdzamy przed spłaszczeniem ukośników, resztę na tekście bez rozszerzeń plików („x.doc” to
        // nie DoC) i ze spacjami zamiast _-+./()
        $flat = (string) preg_replace('#\.(?:pdf|docx?|ashx|aspx?|php|jsp|jpe?g|png)\b#u', ' ', $raw);
        $flat = ' '.(string) preg_replace('#[\s_\-+.,;:()\[\]/\\\\?=&]+#u', ' ', $flat).' ';
        if (str_contains($raw, '/doc/') || preg_match(self::DECLARATION_PATTERN, $flat) === 1) {
            if (preg_match(self::NOT_EU_PPE_DECLARATION_PATTERN, $raw) === 1 || preg_match(self::NOT_EU_PPE_DECLARATION_PATTERN, $flat) === 1) {
                return null;
            }

            return self::DECLARATION;
        }
        if (preg_match(self::TYPE_EXAMINATION_PATTERN, $flat) === 1) {
            return self::TYPE_EXAMINATION;
        }
        if (preg_match(self::CERTIFICATE_PATTERN, $flat) === 1) {
            return self::CERTIFICATE;
        }

        return null;
    }

    /**
     * Ścieżka i zapytanie adresu, bez schematu i hosta: „gloves.co.uk” nie może czytać się jako deklaracja
     * brytyjska, a „documents.portwest.com” jako „doc”.
     */
    private static function urlWithoutHost(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($path) && ! is_string($query)) {
            return rawurldecode($url);
        }

        return rawurldecode((is_string($path) ? $path : '').(is_string($query) ? '?'.$query : ''));
    }

    /**
     * Etykiety plików rodzaju „certificate” (inne rodzaje pomijane), bez powtórzeń, w kolejności plików.
     *
     * @param  iterable<ProductDocument>  $documents
     * @return list<string>
     */
    public static function forDocuments(iterable $documents): array
    {
        $labels = [];
        foreach ($documents as $document) {
            if ($document->kind !== ProductDocument::KIND_CERTIFICATE) {
                continue;
            }
            $label = self::forDocument((string) $document->title, (string) $document->source_url);
            if ($label !== null && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Lista karty: wpisy ze źródeł (bez automatycznych etykiet — te liczymy od nowa z plików) i etykiety plików,
     * całość przez filter().
     *
     * @param  list<string>  $certificates
     * @param  iterable<ProductDocument>  $documents
     * @return list<string>
     */
    public static function relabel(array $certificates, iterable $documents): array
    {
        $fromSources = array_values(array_filter(
            $certificates,
            static fn (string $item): bool => ! in_array(trim($item), self::AUTOMATIC, true)
        ));

        return self::filter([...$fromSources, ...self::forDocuments($documents)]);
    }

    /**
     * Bez wpisów, które nie są certyfikatami (oznakowanie CE, kategoria ŚOI, samo rozporządzenie), bez pustych
     * i powtórzeń. Pozostałe wpisy zostają w brzmieniu ze źródła — nic nie jest dopisywane ani przeredagowane.
     *
     * @param  list<string>  $certificates
     * @return list<string>
     */
    public static function filter(array $certificates): array
    {
        $out = [];
        foreach ($certificates as $item) {
            $item = trim($item);
            if ($item === '' || self::isNotCertificate($item) || in_array($item, $out, true)) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    public static function isNotCertificate(string $item): bool
    {
        $norm = mb_strtolower(trim($item));
        $norm = strtr($norm, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        // przywołanie rozporządzenia ŚOI (2016/425, dawna dyrektywa 89/686/EWG) — przed rozbiciem na słowa,
        // bo inaczej „2016” wyglądałoby jak numer jednostki notyfikowanej
        $norm = (string) preg_replace('#\b(?:2016\s*/\s*425|89\s*/\s*686(?:\s*/\s*(?:ewg|eec|ec|we))?)\b#u', ' ', $norm);
        $words = preg_split('#[^\p{L}\p{N}]+#u', $norm, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return true;
        }
        foreach ($words as $word) {
            if (! in_array($word, self::NON_CERTIFICATE_WORDS, true)) {
                return false;
            }
        }

        return true;
    }
}
