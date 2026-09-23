<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\SourceClaimGuard;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Raport źródeł i opisów zapisanych przed poprawkami bramki (tylko odczyt). Audyt ręcznych cenników 22.09.2026:
 * Canis ROXY 3420-007-157-07 opisana ze strony CXS MERU 3420-115, 91 kart ze źródłem z naszego sklepu
 * (supon.rzeszow.pl — eksport wysyła tam nasze opisy), 104 opisy będące surowym tekstem strony (CAPTCHA, baner
 * cookies, „Cena netto”), „brak danych” w opisie i 52 karty „done” bez opisu. Poprawki działają na nowe
 * wzbogacenia; ta komenda znajduje karty zapisane wcześniej. Kategorie (karta może mieć kilka):
 *
 * 1. źródło odrzucane przez obecną bramkę — ProductSearchIdentity::isConfirmedProductCard na samym adresie
 *    (tekstu strony nie zapisujemy); liczy się karta, w której odpada główne (pierwsze) albo każde źródło;
 * 2. źródło z naszego sklepu (host prestashop.shop_url i enrichment.blocked_source_hosts, z subdomenami);
 * 3. opis nazywa inny model — kod grupowy Canis (textNamesAnotherGroupedCode); osobno, tylko do przejrzenia,
 *    opis, którego obecne sito opisu (descriptionMentionsProduct) nie przypisuje do karty — kalibracja 22.09.2026:
 *    angielskie nazwy Canis („Terry towel 400g/m2”) i nazwy Coba z wymiarami nie mają wspólnego słowa z polskim
 *    opisem, więc sito odrzuca też poprawne opisy (ok. połowa z 28 trafień poza kodem grupowym);
 * 4. opis wygląda na surowy tekst strony — confidence 0 w payloadzie przy zapisanym opisie albo wzorce B1;
 * 5. „brak danych” w opisie albo w parametrach (specs);
 * 6. status „done” bez opisu.
 *
 * Adres wpisany ręcznie (shop_source_url) to decyzja człowieka — nie oceniamy go ani w 1, ani w 2. Adres karty
 * u dostawcy B2B, wpisany przez synchronizację (Product::trustedShopUrl), oceniamy jak każdy inny.
 *
 * Do --out (format products:recheck-skus --file=) idą karty z kategorii 1, 3 i 4 oraz z 2, gdy nasz sklep jest
 * jedynym albo głównym źródłem. 5 i 6 są wypisane osobno z poleceniem: opis z jednym zdaniem „brak danych” bywa poza
 * nim poprawny, a „done” bez opisu nie ma czego kasować. Karty powiązane z kontem łącznika B2bDatasheetOnlyDescription
 * (ARTRA) albo z opisem z PDF B2B (enrichment_payload.b2b_sources) trafiają do osobnej kategorii i NIE do --out:
 * recheck-skus kasuje opis i zleca wzbogacanie z internetu, a ich opis naprawia b2b:redescribe-from-datasheets.
 */
final class AuditSourceIdentityCommand extends Command
{
    public const CATEGORY_GATE = 'źródło odrzucane przez bramkę';

    public const CATEGORY_OWN_SHOP = 'źródło z naszego sklepu';

    public const CATEGORY_FOREIGN_MODEL = 'opis nazywa inny model';

    public const CATEGORY_UNCONFIRMED_DESCRIPTION = 'opis nieprzypisany przez sito opisu — do przejrzenia, poza --out';

    public const CATEGORY_RAW_PAGE = 'opis wygląda na surowy tekst strony';

    public const CATEGORY_MISSING_DATA = '„brak danych” w opisie lub parametrach';

    public const CATEGORY_DONE_EMPTY = 'status done bez opisu';

    public const CATEGORY_DATASHEET_ONLY = 'opis z PDF B2B (konto łącznika / b2b_sources) — poza --out';

    /** gateRejection(): bramka odrzuca adres, ale w samym adresie nie ma ani dowodu, ani kodu i nazwy. */
    private const GATE_UNJUDGED = '';

    /** Kategorie, których karty idą do --out (2 tylko przy głównym źródle — patrz audit()). */
    private const OUT_CATEGORIES = [self::CATEGORY_GATE, self::CATEGORY_OWN_SHOP, self::CATEGORY_FOREIGN_MODEL, self::CATEGORY_RAW_PAGE];

    /**
     * Ślady surowego tekstu strony w opisie (audyt B1, 22.09.2026: CAPTCHA czeskiego sklepu, hiszpańska tabela
     * „Solicitar precio”, cena sklepu, szablon {{ }}, markdown, sama lista rozmiarów). Opis modelu tego nie zawiera.
     *
     * @var array<string, string>
     */
    private const RAW_PAGE_PATTERNS = [
        'CAPTCHA' => '/CAPTCHA|Kontrola, zda je|připojení bezpečné|Checking your browser|Just a moment\.\.\./u',
        'baner cookies' => '/\bcookies?\b|Soubory cookie|Do Not Sell|liczbę odwiedzających naszą stronę/iu',
        'cena ze sklepu' => '/Cena\s+(?:netto|brutto)|\bzł\s*\/\s*(?:szt|mb|op|par)\b|Solicitar precio|\bQty:/iu',
        'szablon {{ }}' => '/\{\{|\}\}/u',
        'markdown' => '/^\s*#{2,}\s|\*\*[^*\n]+\*\*/mu',
        'lista rozmiarów zamiast opisu' => '/^\s*Dostępne rozmiary:/u',
        'tekst sklepu' => '/Kup w sklepie|Dodaj do koszyka|Wiederholungstäter|Objednejte|dodávkami|Kód výrobcu|Pronářadí|Numero de parte/u',
    ];

    protected $signature = 'products:audit-source-identity
                            {--price-list= : Numer cennika — karty z tego importu}
                            {--manufacturer= : Albo wszystkie karty producenta}
                            {--out= : Plik z kodami kart do products:recheck-skus --file= (kategorie 1, 3, 4 i 2 przy głównym źródle)}
                            {--limit=20 : Ile przykładów pokazać na kategorię (0 = wszystkie)}';

    protected $description = 'Raport: źródła odrzucane przez obecną bramkę, nasz sklep jako źródło, opis innego modelu, surowy tekst strony, „brak danych”, done bez opisu (niczego nie zmienia)';

    /** @var list<string>|null */
    private ?array $ownShopHosts = null;

    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly ProductEnrichmentService $enrichment,
        private readonly B2bConnectorRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $priceListId = (int) $this->option('price-list');
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($priceListId <= 0 && $manufacturer === '') {
            $this->error('Podaj --price-list=<numer> albo --manufacturer=<nazwa>.');

            return self::FAILURE;
        }
        $listIds = [];
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika numer {$priceListId}.");

                return self::FAILURE;
            }
            $listIds = array_values(array_unique(array_map('intval', $priceList->product_ids ?? [])));
            if ($listIds === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import) — użyj --manufacturer=.');

                return self::FAILURE;
            }
        }

        $findings = array_fill_keys([
            self::CATEGORY_GATE, self::CATEGORY_OWN_SHOP, self::CATEGORY_FOREIGN_MODEL, self::CATEGORY_RAW_PAGE,
            self::CATEGORY_UNCONFIRMED_DESCRIPTION, self::CATEGORY_MISSING_DATA, self::CATEGORY_DONE_EMPTY,
            self::CATEGORY_DATASHEET_ONLY,
        ], []);
        /** @var array<int, array{id: int, sku: string, reason: string}> $outRows */
        $outRows = [];
        $datasheetOnlyCards = $this->datasheetOnlyCards();
        $checked = 0;
        Product::query()
            ->when($listIds !== [], static fn ($q) => $q->whereIn('id', $listIds))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$findings, &$outRows, &$checked, $datasheetOnlyCards): void {
                foreach ($products as $product) {
                    $checked++;
                    $result = $this->audit($product, $datasheetOnlyCards[(int) $product->id] ?? null);
                    foreach ($result['findings'] as $category => $reason) {
                        $findings[$category][] = [
                            'id' => (int) $product->id,
                            'sku' => (string) $product->sku,
                            'name' => (string) $product->name,
                            'reason' => $reason,
                        ];
                    }
                    if ($result['out'] !== []) {
                        $outRows[(int) $product->id] = [
                            'id' => (int) $product->id,
                            'sku' => (string) $product->sku,
                            'reason' => implode('; ', $result['out']),
                        ];
                    }
                }
            });

        $this->info("Sprawdzone karty: {$checked}.");
        $this->table(
            ['Kategoria', 'Kart'],
            array_map(static fn (string $category, array $rows): array => [$category, count($rows)], array_keys($findings), $findings),
        );
        $this->line('Do ponownego wzbogacenia (--out): '.count($outRows).' kart.');
        $limit = max(0, (int) $this->option('limit'));
        foreach ($findings as $category => $rows) {
            if ($rows === []) {
                continue;
            }
            $this->line('');
            $this->info(sprintf('%s: %d', $category, count($rows)));
            $shown = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
            $this->table(
                ['ID', 'SKU', 'Nazwa', 'Powód'],
                array_map(static fn (array $r): array => [
                    $r['id'], mb_substr($r['sku'], 0, 30), mb_substr($r['name'], 0, 40), mb_substr($r['reason'], 0, 140),
                ], $shown),
            );
            if (count($rows) > count($shown)) {
                $this->line(sprintf('… i jeszcze %d (--limit=0 pokaże wszystkie).', count($rows) - count($shown)));
            }
        }

        $this->line('');
        if ($findings[self::CATEGORY_UNCONFIRMED_DESCRIPTION] !== []) {
            $this->line('„'.self::CATEGORY_UNCONFIRMED_DESCRIPTION.'”: sito opisu odrzuca też poprawne opisy kart z nazwą '
                .'angielską albo z samymi wymiarami — przejrzyj; opis cudzego wyrobu: artisan products:recheck-skus --sku=KOD.');
        }
        if ($findings[self::CATEGORY_MISSING_DATA] !== []) {
            $this->line('„'.self::CATEGORY_MISSING_DATA.'”: nie trafiają do --out — opis bywa poprawny poza jednym zdaniem albo '
                .'pozycją parametrów. Przejrzyj; całą kartę od nowa: artisan products:recheck-skus --sku=KOD (podgląd), potem z --apply.');
        }
        if ($findings[self::CATEGORY_DONE_EMPTY] !== []) {
            $this->line('„'.self::CATEGORY_DONE_EMPTY.'”: nie ma czego kasować — ponowne pobranie opisu: '
                .'artisan products:queue-enrichment --done-without-description'
                .($this->option('manufacturer') ? ' --manufacturer="'.trim((string) $this->option('manufacturer')).'"' : '')
                .' (podgląd), potem z --apply.');
        }
        if ($findings[self::CATEGORY_DATASHEET_ONLY] !== []) {
            $this->warn('„'.self::CATEGORY_DATASHEET_ONLY.'”: tych kart NIE przepuszczaj przez products:recheck-skus —');
            $this->warn('kasuje opis i zleca wzbogacanie z internetu, a ich opis pisze model z karty katalogowej PDF B2B.');
            $this->warn('Naprawa: artisan b2b:redescribe-from-datasheets --account=NUMER_KONTA (podgląd), potem z --apply.');
        }

        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            return $this->writeOut($out, array_values($outRows));
        }

        return self::SUCCESS;
    }

    /**
     * Ocena jednej karty (bez bazy — wystarczy obiekt Product z payloadem). $datasheetAccount = opis konta łącznika
     * B2bDatasheetOnlyDescription, z którym karta jest powiązana (null = brak).
     *
     * @return array{findings: array<string, string>, out: list<string>}
     */
    public function audit(Product $product, ?string $datasheetAccount = null): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $description = trim((string) $product->description);
        $findings = [];

        $sourceUrls = [];
        foreach ((array) ($payload['source_urls'] ?? []) as $url) {
            if (is_string($url) && trim($url) !== '') {
                $sourceUrls[] = trim($url);
            }
        }
        $ownShopMain = false;

        // 1 i 2. Źródła — pierwsze na liście to główne (z niego model pisał opis)
        $evaluated = 0;
        $unjudged = 0;
        /** @var list<string> $rejected adres + powód */
        $rejected = [];
        $mainRejected = false;
        $ownShop = [];
        foreach ($sourceUrls as $i => $url) {
            if ($product->isTrustedShopUrl($url)) {
                continue;
            }
            if ($this->isOwnShopUrl($url)) {
                $ownShop[] = $url;
                if ($i === 0) {
                    $ownShopMain = true;
                }

                continue;
            }
            $evaluated++;
            $verdict = $this->gateRejection($url, $product);
            if ($verdict === self::GATE_UNJUDGED) {
                $unjudged++;
            } elseif ($verdict !== null) {
                $rejected[] = $verdict.': '.$this->shortUrl($url);
                if ($i === 0) {
                    $mainRejected = true;
                }
            }
        }
        // „wszystkie”: każdy adres odpada, choć część tylko z braku kodu i nazwy — wtedy wystarczy jeden z dowodem
        if ($rejected !== [] && ($mainRejected || count($rejected) + $unjudged === $evaluated)) {
            $findings[self::CATEGORY_GATE] = ($mainRejected ? 'główne' : 'wszystkie').' ('.count($rejected).'/'.$evaluated.') '
                .implode(' | ', array_slice($rejected, 0, 2));
        }
        $ownShopOnly = $ownShop !== [] && count($ownShop) === count($sourceUrls);
        if ($ownShop !== []) {
            $findings[self::CATEGORY_OWN_SHOP] = ($ownShopOnly ? 'jedyne' : ($ownShopMain ? 'główne' : 'dodatkowe')).': '
                .$this->shortUrl($ownShop[0]);
        }

        if ($description !== '') {
            // 3. Opis innego modelu. Adres wpisany ręcznie zwalnia opis ze sprawdzenia (jak isUsableProductDescription);
            // link z synchronizacji B2B zwalnia tylko opis zapisany przez sam łącznik (bez źródeł z sieci).
            $descriptionExempt = $product->trustedShopUrl() !== null
                || ($product->hintedShopUrl() !== null && $sourceUrls === []);
            if ($this->identity->textNamesAnotherGroupedCode($description, $product)) {
                $findings[self::CATEGORY_FOREIGN_MODEL] = 'opis ma kod grupowy innego modelu (nasz '
                    .$this->identity->groupedNumericModelCode($product).')';
            } elseif (! $descriptionExempt
                && ! $this->enrichment->descriptionMentionsProduct($description, $product, $sourceUrls)) {
                $findings[self::CATEGORY_UNCONFIRMED_DESCRIPTION] = 'opis nie nazywa karty kodem ani nazwą z marką';
            }

            // 4. Surowy tekst strony
            // Pewność liczy się tylko w payloadzie wzbogacania z sieci: payload z samymi atrybutami albo scalonymi
            // rozmiarami (opis z importu, Bubblemat, rękawice Canis) nie ma ani źródeł, ani confidence.
            $raw = [];
            if (array_key_exists('confidence', $payload) && (float) $payload['confidence'] <= 0.0) {
                $raw[] = 'confidence 0';
            } elseif (! array_key_exists('confidence', $payload) && $sourceUrls !== []) {
                $raw[] = 'brak confidence';
            }
            foreach (self::RAW_PAGE_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $description, $m) === 1) {
                    $raw[] = $label.' („'.mb_substr(trim($m[0]), 0, 30).'”)';
                }
            }
            if ($raw !== []) {
                $findings[self::CATEGORY_RAW_PAGE] = implode(', ', $raw);
            }
        }

        // 5. „Brak danych” w opisie albo w pozycji parametrów
        $missing = [];
        if ($description !== '' && ($this->enrichment->looksLikeMissingCardMeta($description)
            || SourceClaimGuard::statesMissingData($description, false))) {
            $missing[] = 'opis';
        }
        $specs = array_values(array_filter(
            (array) ($payload['specs'] ?? []),
            static fn ($item): bool => is_string($item) && trim($item) !== '' && SourceClaimGuard::statesMissingData($item),
        ));
        if ($specs !== []) {
            $missing[] = count($specs).' w parametrach („'.mb_substr(trim((string) $specs[0]), 0, 50).'”)';
        }
        if ($missing !== []) {
            $findings[self::CATEGORY_MISSING_DATA] = implode(', ', $missing);
        }

        // 6. „done” bez opisu — nie wraca do kolejki. Pusty jak w products:queue-enrichment --done-without-description,
        // żeby polecenie naprawy wzięło dokładnie te karty.
        if ($product->enrichment_status === Product::ENRICHMENT_DONE && $description === '') {
            $findings[self::CATEGORY_DONE_EMPTY] = 'status done, opis pusty';
        }

        $out = [];
        foreach (self::OUT_CATEGORIES as $category) {
            if (! isset($findings[$category])) {
                continue;
            }
            if ($category === self::CATEGORY_OWN_SHOP && ! $ownShopOnly && ! $ownShopMain) {
                continue;
            }
            $out[] = $category.': '.$findings[$category];
        }

        // Opis z PDF B2B: kategorie 1–4 razem do osobnej grupy, poza --out
        $b2bTrace = is_array($payload['b2b_sources'] ?? null) && $payload['b2b_sources'] !== [];
        if (($datasheetAccount !== null || $b2bTrace) && $out !== []) {
            foreach (self::OUT_CATEGORIES as $category) {
                unset($findings[$category]);
            }
            $findings[self::CATEGORY_DATASHEET_ONLY] = ($datasheetAccount ?? 'b2b_sources w payloadzie').': '.implode('; ', $out);
            $out = [];
        }

        return ['findings' => $findings, 'out' => $out];
    }

    /**
     * Powód, dla którego obecna bramka (isConfirmedProductCard) odrzuca zapisany adres; null = adres przechodzi.
     * Oceniamy sam adres, bo tekstu strony nie zapisujemy. Liczy się tylko dowód w adresie (cudzy kod, inna klasa,
     * inny rodzaj, niezwiązana domena). Bez treści bramka odrzuca też adres bez kodu i nazwy („3m.com/…/p/d/v000153444/”)
     * i adres z naszym kodem, któremu brakuje rodzaju wyrobu albo marki („signproject.pl/pl/products/ra033-telefon-50295”)
     * — przy pobieraniu potwierdzała je treść strony. Kalibracja 22.09.2026: 107 takich adresów w 2320 kartach innych
     * producentów, prawie wszystkie właściwe. Taki adres jest GATE_UNJUDGED i sam karty nie zgłasza.
     */
    private function gateRejection(string $url, Product $product): ?string
    {
        if ($this->identity->isConfirmedProductCard($url, '', '', $product)) {
            return null;
        }
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return match (true) {
            ProductSearchIdentity::isJunkSearchHost($url) || $this->identity->looksLikeUnrelatedRetailHost($url, $product) => 'niezwiązana domena',
            $this->identity->looksLikeNonProductCardUrl($url) => 'nie karta produktu',
            $this->identity->accessoryCardDisagrees($url, '', $product) => 'akcesorium zamiast wyrobu (lub odwrotnie)',
            $this->identity->cardNamesAnotherSubtype($url, '', $product) => 'inny rodzaj wyrobu',
            $this->identity->pageNamesAnotherFootwearVariant($url, '', '', $product) => 'inna klasa obuwia',
            $this->identity->pageNamesAnotherRespiratoryClass($url, '', $product) => 'inna klasa ochrony',
            $this->identity->textNamesAnotherGroupedCode($path, $product) => 'kod grupowy innego modelu',
            $this->identity->pageClaimsAnotherCode($url, '', $product) => 'inny kod w adresie',
            default => self::GATE_UNJUDGED,
        };
    }

    private function isOwnShopUrl(string $url): bool
    {
        if ($this->ownShopHosts === null) {
            $shopHost = parse_url(trim((string) config('prestashop.shop_url', '')), PHP_URL_HOST);
            $this->ownShopHosts = array_values(array_unique(array_filter(array_map(
                static fn ($host): string => is_string($host) ? (string) preg_replace('/^www\./', '', mb_strtolower(trim($host))) : '',
                [...(array) config('enrichment.blocked_source_hosts', []), is_string($shopHost) ? $shopHost : ''],
            ))));
        }
        $host = (string) preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        if ($host === '') {
            return false;
        }
        foreach ($this->ownShopHosts as $needle) {
            if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Karty powiązane z kontem, którego łącznik pisze opis wyłącznie z karty katalogowej PDF: id karty => „konto B2B
     * #7 ARTRA” (jak w products:audit-footwear-identity).
     *
     * @return array<int, string>
     */
    private function datasheetOnlyCards(): array
    {
        $accounts = [];
        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            try {
                if ($this->registry->make($account, 0) instanceof B2bDatasheetOnlyDescription) {
                    $accounts[(int) $account->id] = 'konto B2B #'.$account->id.' '.$account->username;
                }
            } catch (RuntimeException) {
                continue;
            }
        }
        if ($accounts === []) {
            return [];
        }
        $cards = [];
        B2bProductLink::query()
            ->whereIn('b2b_account_id', array_keys($accounts))
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->each(static function (B2bProductLink $link) use (&$cards, $accounts): void {
                $cards[(int) $link->product_id] ??= $accounts[(int) $link->b2b_account_id];
            });

        return $cards;
    }

    private function shortUrl(string $url): string
    {
        $url = (string) preg_replace('/\?.*$/u', '', $url);

        return mb_strlen($url) > 90 ? mb_substr($url, 0, 87).'…' : $url;
    }

    /**
     * Kody kart do `products:recheck-skus --file=`: jeden kod w wierszu, komentarz po „#”. Kod z przecinkiem,
     * średnikiem, tabulatorem albo „#” rozbiłby się przy czytaniu — takie pomijamy i wypisujemy.
     *
     * @param  list<array{id: int, sku: string, reason: string}>  $rows
     */
    private function writeOut(string $path, array $rows): int
    {
        $this->warn('UWAGA: products:recheck-skus --apply na kartach z tej listy kasuje opis, normy, zdjęcia, dokumenty');
        $this->warn('i akcesoria pobrane z sieci oraz przypięty adres sklepu (kopia zapasowa powstaje, przywrócenie: --restore=PLIK_KOPII).');
        $this->warn('Karta bez opisu wypada z dopasowania przetargowego do czasu ponownego wzbogacenia.');
        $this->warn('recheck-skus szuka kodów w całym katalogu — dołóż do niego ten sam --price-list= albo --manufacturer=,');
        $this->warn('żeby ten sam kod u innego producenta nie stracił opisu.');
        $lines = [
            '# products:audit-source-identity '.now()->toDateTimeString(),
            '# użycie: artisan products:recheck-skus --file=TEN_PLIK'
                .($this->option('price-list') ? ' --price-list='.(int) $this->option('price-list') : '')
                .($this->option('manufacturer') ? ' --manufacturer="'.trim((string) $this->option('manufacturer')).'"' : '')
                .' (podgląd), potem z --apply',
        ];
        $skipped = [];
        $written = 0;
        foreach ($rows as $row) {
            $sku = trim($row['sku']);
            if ($sku === '' || preg_match('/[;,\t#]/u', $sku) === 1) {
                $skipped[] = $row['id'].($sku !== '' ? ' ('.$sku.')' : '');

                continue;
            }
            $lines[] = $sku.'   # id '.$row['id'].': '.str_replace(["\n", "\r", '#'], [' ', ' ', ''], mb_substr($row['reason'], 0, 200));
            $written++;
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Nie można utworzyć katalogu {$dir}.");

            return self::FAILURE;
        }
        if (file_put_contents($path, implode("\n", $lines)."\n") === false) {
            $this->error("Zapis pliku {$path} nie powiódł się.");

            return self::FAILURE;
        }
        $this->info("Zapisano {$written} kodów do {$path}.");
        if ($skipped !== []) {
            $this->warn('Pominięte (kod nie przejdzie przez --file): id '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
