<?php

declare(strict_types=1);

namespace App\Services\Norms;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\JspB2bClient;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BrandKey;
use App\Support\ProductIdentifierCode;
use App\Support\ProductSizeVariant;
use App\Support\RequirementCheck\En388Code;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Bramka tożsamości dla norm ze strony producenta (plan norm, etap 3a, pkt 3). Ostrzejsza niż przy opisie: normy
 * idą do przetargu jako fakt z pochodzeniem, więc strona musi nieść dokładny kod naszego wyrobu — SKU producenta,
 * kod producenta ze źródła ceny albo EAN karty. Nazwa i marka nie wystarczają: „Rękawice CXS Tale” to jedna strona,
 * a „Tale” z innego koloru czy wersji miałby inny kod i mógłby mieć inne poziomy EN 388.
 *
 * Nie używamy tu ProductSearchIdentity::productCodes / model_name — to luźne rdzenie rodziny („3210-012” dla wszystkich
 * rozmiarów i kolorów), dobre do szukania, złe do potwierdzania. Wyjątek: z modelAliases same kody modelu Ansella
 * („R065”, „11-800”) dla karty z cennika Ansella — ansellModelCodes.
 *
 * Niczego nie zapisuje.
 */
final class ManufacturerNormIdentity
{
    public const KIND_EAN = 'ean';

    public const KIND_MANUFACTURER_CODE = 'manufacturer_code';

    public const KIND_SKU = 'sku';

    /** Kod modelu wyprowadzony ze SKU cennika marki według jej zapisu na stronie (Ansell: „065-13” → „R065”). */
    public const KIND_MODEL_CODE = 'model_code';

    /**
     * Klasy i id bloków z innymi wyrobami. Usuwane z DOM-u razem z zawartością — regex w withoutRelatedProductHtml
     * kończy blok na pierwszym „</div>”, a blok „upsell” cxs.net.pl ma kilka poziomów zagnieżdżenia, więc kafelki
     * innych rękawic (CXS Yema, CXS Technik) zostawały w tekście. „prev-next”/„product-prev|next” — strzałki do
     * poprzedniego i następnego wyrobu nad tytułem karty (motyw Porto na cxs.net.pl: „Rękawice CXS Technik HV”).
     */
    private const FOREIGN_BLOCK = '/(?:related|upsell|up-sell|cross-?sell|also-?bought|customers-also|you-may-also|'
        .'similar|recommend|podobne|polecane|polecamy|czesto-kupowane|frequently-bought|recently-viewed|ostatnio-ogladane|'
        .'prev-next|product-prev|product-next)/i';

    /**
     * Nagłówek bloku innych wyrobów bez klasy: „Więcej rękawic”, „Zobacz także”, „Znaleźliśmy inne produkty, które
     * mogą Cię zainteresować!”. Tylko na początku nagłówka, a „Więcej” tylko z nazwą grupy wyrobów — nagłówek opisu
     * „Więcej ochrony dzięki powłoce” to treść karty.
     */
    private const FOREIGN_HEADING = '/^\s*(?:więcej\s+(?:produktów|rękawic|wyrobów|modeli|z\s+(?:tej\s+)?(?:kategorii|serii|kolekcji)|od\s+producenta)|'
        .'zobacz\s+(?:też|także|również)|'
        .'inne\s+produkty|znaleźliśmy\s+inne|mogą\s+cię\s+zainteresować|podobne\s+produkty|polecane\s+produkty|'
        .'klienci\s+kupili|często\s+kupowane|related\s+products|you\s+may\s+also|similar\s+products|customers\s+also|'
        .'more\s+from|frequently\s+bought)/iu';

    /**
     * id karty => producenci cenników (price_lists.product_ids), na których stoi. Liczone raz na obiekt: polecenie
     * przechodzi setki kart, a każdy cennik niesie tysiące id w JSON-ie.
     *
     * @var array<int, list<string>>|null
     */
    private ?array $listBrandsByProduct = null;

    public function __construct(
        private readonly ProductPageFetcher $pages,
        private readonly B2bConnectorRegistry $connectors,
        private readonly ProductSizeVariant $sizes = new ProductSizeVariant,
    ) {}

    /**
     * Kody, które na stronie producenta potwierdzają nasz wyrób. Kolejność: identyfikatory ze źródeł cen (EAN, kod
     * producenta nazwany tak przez źródło), EAN karty, SKU karty — to ostatnie tylko wtedy, gdy cena karty pochodzi
     * od samej marki (cennik producenta z pliku albo jego konto B2B). SKU karty z konta dystrybutora (Raw-Pol, P4S)
     * bywa numerem dystrybutora — na stronie producenta nic nie znaczy albo przypadkiem znaczy inny wyrób.
     *
     * @return list<array{code: string, kind: 'ean'|'manufacturer_code'|'sku'|'model_code', short: bool}>
     */
    public function codesFor(Product $product): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $code, string $kind) use (&$out, &$seen): void {
            $code = trim((string) preg_replace('/\s+/u', ' ', $code));
            $key = $kind === self::KIND_EAN ? ProductIdentifierCode::gtin($code) : ProductIdentifierCode::code($code);
            if ($code === '' || $key === null || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['code' => $code, 'kind' => $kind, 'short' => self::isShort($code, $kind)];
        };

        $brand = trim((string) $product->manufacturer);
        $brandKey = BrandKey::of($brand);
        $rows = ProductIdentifier::query()
            ->where('product_id', $product->id)
            ->whereNull('removed_at')
            ->whereIn('type', [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_MANUFACTURER_CODE])
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $value = (string) $row->value;
            if ($row->type === ProductIdentifier::TYPE_EAN) {
                // normalized null = zła suma kontrolna albo „5.9E+12” z arkusza — taki kod niczego nie potwierdzi
                if ($row->normalized !== null) {
                    $add((string) preg_replace('/[\s\-]+/u', '', $value), self::KIND_EAN);
                }

                continue;
            }
            // Kod producenta tylko w marce karty: dystrybutor wielu marek nazywa „kodem producenta” także kod
            // innej marki (karta Reis z kodem Ansell po połączeniu kart), a ten na stronie Reis nic nie znaczy.
            $rowBrand = trim((string) $row->brand_key) !== '' ? (string) $row->brand_key : BrandKey::of((string) $row->manufacturer);
            if ($brandKey !== '' && $rowBrand === $brandKey) {
                $add($value, self::KIND_MANUFACTURER_CODE);
            }
        }

        $ean = (string) preg_replace('/[\s\-]+/u', '', trim((string) $product->ean));
        if (preg_match('/^\d{8,14}$/', $ean) === 1 && ProductIdentifierCode::gtin($ean) !== null) {
            $add($ean, self::KIND_EAN);
        }

        $sku = trim((string) $product->sku);
        if ($sku !== '' && $brandKey !== '' && $this->priceSourceIsBrand($product, $brand)) {
            $add($sku, self::KIND_SKU);
            // Kod bez końcówki rozmiaru („G3175/40” → „G3175”): strona producenta ma model, nie rozmiar. Tylko
            // stripWearSizeSuffix — catalogSkuWithoutSize obcina też sklejone końcowe cyfry (skuTailStem), co
            // z kodu modelu robi inny kod.
            $stripped = $this->sizes->stripWearSizeSuffix($sku);
            if (is_string($stripped) && $stripped !== '') {
                $add($stripped, self::KIND_SKU);
            }
            foreach ($this->ansellModelCodes($product, $brandKey) as $model) {
                $add($model, self::KIND_MODEL_CODE);
            }
        }

        return $out;
    }

    /**
     * Kod modelu Ansella z SKU cennika Ansella: „065-13” (RINGERS 065, rozmiar 13) to na ansell.com „R065” / „R-065”,
     * „11-800-9” to „11-800” (plan norm, etap 3b, 23.09.2026). Z ProductSearchIdentity::modelAliases bierzemy tylko
     * zapisy w kształcie kodu — bez nazwy linii („ringers”) i gołych cyfr („065”), które na stronie nic nie potwierdzają.
     * Tylko dla karty z cennika samej marki (wołający sprawdza priceSourceIsBrand): u dystrybutora SKU to jego numer.
     *
     * @return list<string>
     */
    private function ansellModelCodes(Product $product, string $brandKey): array
    {
        if ($brandKey !== BrandKey::of('Ansell')) {
            return [];
        }

        return array_values(array_filter(
            app(ProductSearchIdentity::class)->modelAliases($product),
            static fn (string $alias): bool => preg_match('/^(?:[a-z]{1,4}-?\d{2,6}|\d{2}-\d{3}[a-z]?)$/iu', trim($alias)) === 1,
        ));
    }

    /**
     * Pierwszy kod wyrobu, który strona niesie jako osobny token: w ścieżce adresu, w tytule (og:title, <h1>,
     * <title>), w mikrodanych (itemprop sku/mpn/gtin, JSON-LD Product) albo — tylko kod długi — w treści strony bez
     * bloków innych wyrobów, menu i stopki. Kod krótki („2039”, „A110”) w treści to za mało: „2039” bywa ceną,
     * numerem normy czy kodem sąsiedniego wyrobu w tabeli.
     *
     * Karta ($product) jest w podpisie dla wołającego i przyszłych reguł witryn; o tożsamości decydują same kody.
     * `by` = rodzaj kodu (ean / manufacturer_code / sku), `value` = kod dosłownie tak, jak podał go codesFor.
     *
     * @param  list<array{code: string, kind: string, short: bool}>  $codes  codesFor
     * @return array{by: string, value: string, where: 'url'|'title'|'markup'|'text'}|null
     */
    public function confirm(Product $product, string $url, string $html, array $codes): ?array
    {
        if ($codes === []) {
            return null;
        }
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $titles = $this->pages->pageTitles($html);
        $markup = $this->markupCodes($html);
        $text = null;

        foreach ($codes as $entry) {
            $code = trim((string) ($entry['code'] ?? ''));
            $kind = (string) ($entry['kind'] ?? '');
            if ($code === '' || $kind === '') {
                continue;
            }
            $short = (bool) ($entry['short'] ?? self::isShort($code, $kind));
            $hit = static fn (string $where): array => ['by' => $kind, 'value' => $code, 'where' => $where];

            if ($path !== '' && $this->tokenIn($path, $code, $kind)) {
                return $hit('url');
            }
            foreach ($titles as $title) {
                if ($this->tokenIn($title, $code, $kind)) {
                    return $hit('title');
                }
            }
            if ($this->markupHas($markup, $code, $kind)) {
                return $hit('markup');
            }
            if ($short) {
                continue;
            }
            $text ??= $this->mainText($html);
            // Kod w treści, a mikrodane strony podają inny wyrób tego samego formatu (markupSkuNamesOtherProduct:
            // SIRIUS szaro-zielona ma itemprop sku „1010-035-708-00”, a szukany kolor to „1010-001-703-00”) —
            // wtedy kod w treści to wzmianka, nie karta naszego wyrobu.
            if ($this->tokenIn($text, $code, $kind)
                && ($kind === self::KIND_EAN || ! $this->pages->markupNamesOtherProduct($html, $code))) {
                return $hit('text');
            }
        }

        return null;
    }

    /**
     * Strona rodziny: w tekście stoją co najmniej dwa różne kody EN 388 tego samego (albo niepodanego) wydania —
     * „EN 388:2016 2122X … EN 388:2016 2132X” to dwa wyroby na jednej stronie i nie wiadomo, który jest nasz.
     * Dwa różne podane wydania („EN 388:2003 (4542)” i „EN 388:2016 (4X42C)”) to dwie prawdziwe wartości jednego
     * wyrobu, nie sprzeczność. Zapis słowny pomijamy — bywa częściowy i nie jest kodem.
     */
    public function familyConflict(string $text): bool
    {
        $codes = [];
        foreach (En388Code::allIn($text) as $code) {
            $compact = $code->compact();
            if ($code->worded || $compact === null) {
                continue;
            }
            foreach ($codes as [$otherCompact, $otherEdition]) {
                if ($otherCompact !== $compact && En388Code::comparableEditions($code->edition, $otherEdition)) {
                    return true;
                }
            }
            $codes[] = [$compact, $code->edition];
        }

        return false;
    }

    /** Kod krótki: do 4 znaków albo same cyfry, najwyżej 5 („2039”, „A110”, „11-800”). EAN nigdy. */
    private static function isShort(string $code, string $kind): bool
    {
        if ($kind === self::KIND_EAN) {
            return false;
        }
        $compact = (string) ProductIdentifierCode::code($code);

        return mb_strlen($compact) <= 4 || (ctype_digit($compact) && strlen($compact) <= 5);
    }

    /**
     * Czy cena karty pochodzi od samej marki: cennik z pliku tego producenta (price_lists.product_ids) albo konto B2B,
     * którego łącznik to witryna producenta tej marki (UVEX, Polstar…). Konto dystrybutora (Raw-Pol, P4S) — nie.
     */
    private function priceSourceIsBrand(Product $product, string $brand): bool
    {
        $id = (int) $product->id;
        if ($this->listBrandsByProduct === null) {
            $this->listBrandsByProduct = [];
            foreach (PriceList::query()->whereNotNull('product_ids')->get(['id', 'manufacturer', 'product_ids']) as $list) {
                foreach ((array) ($list->product_ids ?? []) as $listed) {
                    $this->listBrandsByProduct[(int) $listed][] = (string) $list->manufacturer;
                }
            }
        }
        foreach ($this->listBrandsByProduct[$id] ?? [] as $listBrand) {
            if (BrandKey::same($listBrand, $brand)) {
                return true;
            }
        }

        $links = B2bProductLink::query()->where('product_id', $id)->get(['b2b_account_id', 'manufacturer']);
        foreach ($links as $link) {
            // producent pozycji w brzmieniu konta — witryna producenta sprzedaje czasem cudzą markę (akcesoria)
            $linkBrand = trim((string) $link->manufacturer);
            if ($linkBrand !== '' && ! BrandKey::same($linkBrand, $brand)) {
                continue;
            }
            $account = B2bAccount::query()->find($link->b2b_account_id);
            $key = $account !== null ? $this->connectors->keyForAccount($account) : null;
            if ($key === null || ! $this->connectors->isManufacturerSite($key)) {
                continue;
            }
            foreach ($this->connectors->brandsForKey($key) as $own) {
                if (BrandKey::same($own, $brand)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kody z mikrodanych: itemprop sku/mpn i klasa Magento (markupProductCodes), itemprop gtin* oraz sku/mpn/gtin*
     * węzła Product z JSON-LD — tylko węzła głównego, bez hasVariant: warianty innych kolorów to inne wyroby.
     *
     * @return list<string>
     */
    private function markupCodes(string $html): array
    {
        $out = $this->pages->markupProductCodes($html);
        if (preg_match_all('#itemprop=["\']gtin(?:8|12|13|14)?["\'][^>]*?(?:content=["\']([^"\']{8,20})["\']|>\s*([^<]{8,20}?)\s*<)#i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $out[] = trim(($row[1] ?? '') !== '' ? $row[1] : ($row[2] ?? ''));
            }
        }
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(trim((string) $json), true);
                if (! is_array($data)) {
                    continue;
                }
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
                foreach ($nodes as $node) {
                    $type = is_array($node) ? ($node['@type'] ?? '') : '';
                    if (! in_array('Product', is_array($type) ? $type : [$type], true)) {
                        continue;
                    }
                    foreach (['sku', 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $key) {
                        if (is_string($node[$key] ?? null) || is_int($node[$key] ?? null)) {
                            $out[] = trim((string) $node[$key]);
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($out, static fn (string $code): bool => $code !== '')));
    }

    /**
     * Mikrodane to pole z samym kodem, więc porównanie całej wartości po sprowadzeniu (ProductIdentifierCode):
     * „3210-012-000-00” = „3210 012 000 00”; EAN jako GTIN-14 („5901234567890” = „05901234567890”).
     *
     * @param  list<string>  $markup
     */
    private function markupHas(array $markup, string $code, string $kind): bool
    {
        $ours = $kind === self::KIND_EAN ? ProductIdentifierCode::gtin($code) : ProductIdentifierCode::code($code);
        if ($ours === null) {
            return false;
        }
        foreach ($markup as $value) {
            $theirs = $kind === self::KIND_EAN ? ProductIdentifierCode::gtin($value) : ProductIdentifierCode::code($value);
            if ($theirs === $ours) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod jako osobny token. Separatory kodu („-”, „.”, „/”, spacja) są wymienne, ale tylko tam, gdzie kod je ma —
     * bez dopasowania sklejonego („3210012000” w innym numerze) i bez podciągu. Kod nie może być początkiem ani końcem
     * dłuższego kodu: „3210-012-000-00” nie potwierdza się w „3210-012-000-00-09”, a „2039” w „12-2039” — cyfra po
     * separatorze to dalszy ciąg numeru. Słowo przed kodem w adresie („/rekawice-2039.html”) nie przeszkadza.
     * EAN — tylko cały token z cyfr, także w zapisie EAN-13 dla GTIN-14 z zerem na początku.
     */
    private function tokenIn(string $hay, string $code, string $kind): bool
    {
        if ($hay === '') {
            return false;
        }
        if ($kind === self::KIND_EAN) {
            $gtin = ProductIdentifierCode::gtin($code);
            if ($gtin === null) {
                return false;
            }
            $variants = [];
            foreach ([14, 13, 12, 8] as $length) {
                $digits = substr($gtin, 14 - $length);
                if (ltrim(substr($gtin, 0, 14 - $length), '0') === '') {
                    $variants[] = preg_quote($digits, '/');
                }
            }

            return preg_match('/(?<![\p{L}\d])(?:'.implode('|', $variants).')(?![\p{L}\d])/u', $hay) === 1;
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return false;
        }
        $body = implode('(?:\h*[\-.\/]\h*|\h+)', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts));
        $before = '(?<![\p{L}\p{N}])';
        if (preg_match('/^\p{N}/u', $parts[0]) === 1) {
            $before .= '(?<!\p{N}[\-.\/])';
        }
        $after = '(?![\p{L}\p{N}])';
        if (preg_match('/\p{N}$/u', $parts[array_key_last($parts)]) === 1) {
            $after .= '(?![\-.\/]\p{N})';
        }

        return preg_match('/'.$before.$body.$after.'/iu', $hay) === 1;
    }

    /**
     * Treść strony do szukania kodu: z DOM-u usunięte całe bloki innych wyrobów (po klasie/id i po nagłówku
     * „Więcej…”, „Zobacz także”), potem to samo czyszczenie co przy opisie (menu, stopka, formularze).
     */
    private function mainText(string $html): string
    {
        $xpath = JspB2bClient::dom($html);
        $doc = $xpath->document;
        $remove = [];
        foreach ($xpath->query('//*[@class or @id]') ?: [] as $node) {
            // Blok z tytułem karty (<h1>) to obszar naszego wyrobu, nie kafelek innego — nawet gdy jego klasa
            // przypadkiem zawiera „similar” czy „recommend”.
            if ($node instanceof DOMElement && ! in_array(mb_strtolower($node->tagName), ['html', 'body'], true)
                && preg_match(self::FOREIGN_BLOCK, $node->getAttribute('class').' '.$node->getAttribute('id')) === 1
                && ! self::holdsTitle($xpath, $node)) {
                $remove[] = $node;
            }
        }
        foreach ($xpath->query('//h2|//h3|//h4|//legend|//*[@role="heading"]') ?: [] as $heading) {
            if ($heading instanceof DOMElement && preg_match(self::FOREIGN_HEADING, $heading->textContent) === 1) {
                foreach ($this->foreignSectionNodes($xpath, $heading) as $node) {
                    $remove[] = $node;
                }
            }
        }
        foreach ($remove as $node) {
            // węzeł mógł już wypaść z drzewa razem z przodkiem
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
        $body = $xpath->query('//body')->item(0);
        $clean = '';
        foreach (($body ?? $doc)->childNodes as $child) {
            $clean .= (string) $doc->saveHTML($child);
        }

        return $this->pages->mainTextFromHtml($clean);
    }

    /**
     * Blok za nagłówkiem innych wyrobów. Idziemy w górę, dopóki nagłówek otwiera tekst przodka (cxs.net.pl:
     * <strong role="heading"> w div.block-title w div.block.upsell) — wtedy znika cały ten przodek, ale nigdy
     * przodek z tytułem karty (<h1>). Gdy nagłówek stoi wśród rodzeństwa (<h2>Więcej rękawic</h2><div>kafelek</div>…),
     * znika on i rodzeństwo za nim do następnego nagłówka h1–h3 — jak cięcie w withoutRelatedProductHtml.
     *
     * @return list<DOMNode>
     */
    private function foreignSectionNodes(DOMXPath $xpath, DOMElement $heading): array
    {
        $headingText = trim((string) preg_replace('/\s+/u', ' ', $heading->textContent));
        $node = $heading;
        while ($node->parentNode instanceof DOMElement
            && ! in_array(mb_strtolower($node->parentNode->tagName), ['body', 'html', 'main', 'article'], true)
            && ! self::holdsTitle($xpath, $node->parentNode)
            && str_starts_with(trim((string) preg_replace('/\s+/u', ' ', $node->parentNode->textContent)), $headingText)) {
            $node = $node->parentNode;
        }
        if ($node !== $heading) {
            return [$node];
        }
        $out = [$heading];
        for ($next = $heading->nextSibling; $next !== null; $next = $next->nextSibling) {
            if ($next instanceof DOMElement && preg_match('/^h[1-3]$/i', $next->tagName) === 1) {
                break;
            }
            $out[] = $next;
        }

        return $out;
    }

    private static function holdsTitle(DOMXPath $xpath, DOMElement $node): bool
    {
        $titles = $xpath->query('.//h1', $node);

        return $titles !== false && $titles->length > 0;
    }
}
