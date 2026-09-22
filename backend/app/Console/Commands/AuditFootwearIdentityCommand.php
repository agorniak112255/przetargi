<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Raport tożsamości kart obuwia (tylko odczyt). Wzbogacanie brało strony sklepów dla wariantu
 * o innej klasie tego samego modelu (ARTRA 22.09.2026: „ARCASIO 732 616560 S1 P ESD” opisana ze
 * strony wariantu O1 FO ESD, „ARYEL 320 Air … S1 PL ESD” ze strony S3L), a artra.pl ma zamienione
 * tabele parametrów dwóch wariantów ARMEN 900 6060. Polecenie porównuje bazę klasy z nazwy karty
 * (BhpAttributeNormalizer::footwearClassBase: S1P = S1PL = „S1 P”, S3 = S3L = S3S; S ≠ O, S1 ≠ S1P,
 * SB ≠ S1) z trzema źródłami i dzieli karty na:
 *
 * - „payload z innego wariantu” — adres źródła albo treść wzbogacania podaje klasę i żadna nie ma
 *   bazy karty; lista trafia do --out w formacie `products:recheck-skus --file=` (kody, # komentarz);
 * - „tabela dostawcy sprzeczna z nazwą” — linie „norma: …” z tabeli sklepu B2B mają inną bazę albo
 *   rodzinę normy (20345 = S, 20347 = O). To błąd u dostawcy, nie fakt: karty nie ruszamy, sprawdza człowiek;
 * - „brak klasy w atrybutach mimo tabeli” — zapisane atrybuty nie mają klasy, a tabela dostawcy ją podaje.
 *
 * Karta „payload z innego wariantu” powiązana z kontem łącznika B2bDatasheetOnlyDescription (ARTRA; decyzja użytkownika
 * 22.09.2026: opis wyłącznie z karty katalogowej PDF) idzie do osobnej kategorii i NIE trafia do --out:
 * products:recheck-skus --apply kasuje opis i zleca wzbogacanie z internetu, czyli dokładnie to, czego dla tych kart
 * nie robimy. Ich opis pisze DescribeB2bProductFromDatasheetJob (synchronizacja konta).
 *
 * Sprawdzane są tylko karty obuwia (ppe_family) z klasą w nazwie albo kodzie — tokeny „sb”, „s2”,
 * „o2” trafiają się w losowych identyfikatorach innych wyrobów.
 */
final class AuditFootwearIdentityCommand extends Command
{
    public const CATEGORY_FOREIGN_PAYLOAD = 'payload z innego wariantu';

    public const CATEGORY_SUPPLIER_TABLE = 'tabela dostawcy sprzeczna z nazwą';

    public const CATEGORY_MISSING_CLASS = 'brak klasy w atrybutach mimo tabeli';

    public const CATEGORY_DATASHEET_ONLY = 'payload z innego wariantu — opis z PDF (konto B2B)';

    /** Oznaczenia, których obecność w adresie przy braku w nazwie karty wskazuje na inny wyrób (asymetrycznie). */
    private const EXTRA_MARKINGS = ['ESD', 'CI', 'HI'];

    private const SAMPLE_ROWS = 40;

    protected $signature = 'products:audit-footwear-identity
                            {--price-list= : Numer cennika — karty z tego importu}
                            {--manufacturer= : Albo wszystkie karty producenta}
                            {--out= : Plik z kodami kart „payload z innego wariantu” dla products:recheck-skus --file=}
                            {--limit=40 : Ile wierszy pokazać na kategorię (0 = wszystkie)}';

    protected $description = 'Raport: klasa obuwia z nazwy vs źródła wzbogacania, klasa w payloadzie i tabela dostawcy (niczego nie zmienia)';

    public function __construct(
        private readonly BhpAttributeNormalizer $normalizer,
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

        $findings = [
            self::CATEGORY_FOREIGN_PAYLOAD => [],
            self::CATEGORY_SUPPLIER_TABLE => [],
            self::CATEGORY_MISSING_CLASS => [],
            self::CATEGORY_DATASHEET_ONLY => [],
        ];
        $datasheetOnlyCards = $this->datasheetOnlyCards();
        $checked = 0;
        $skippedAmbiguous = 0;
        $yearNotes = 0;
        Product::query()
            ->where('ppe_family', 'footwear')
            ->when($listIds !== [], static fn ($q) => $q->whereIn('id', $listIds))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$findings, &$checked, &$skippedAmbiguous, &$yearNotes, $datasheetOnlyCards): void {
                foreach ($products as $product) {
                    $result = $this->audit($product);
                    if ($result === null) {
                        continue;
                    }
                    if ($result['ambiguous']) {
                        $skippedAmbiguous++;

                        continue;
                    }
                    $checked++;
                    if ($result['year_note'] !== null) {
                        $yearNotes++;
                    }
                    foreach ($result['findings'] as $category => $reason) {
                        $account = $datasheetOnlyCards[(int) $product->id] ?? null;
                        if ($category === self::CATEGORY_FOREIGN_PAYLOAD && $account !== null) {
                            $category = self::CATEGORY_DATASHEET_ONLY;
                            $reason = $account.': '.$reason;
                        }
                        $findings[$category][] = [
                            'id' => (int) $product->id,
                            'sku' => (string) $product->sku,
                            'name' => (string) $product->name,
                            'class' => $result['class'],
                            'reason' => $reason,
                        ];
                    }
                }
            });

        $this->info("Sprawdzone karty obuwia z klasą w nazwie/kodzie: {$checked}"
            .($skippedAmbiguous > 0 ? "; pominięte (kilka różnych klas w nazwie): {$skippedAmbiguous}" : '').'.');
        $limit = max(0, (int) $this->option('limit'));
        foreach ($findings as $category => $rows) {
            $this->line('');
            $this->info(sprintf('%s: %d', $category, count($rows)));
            if ($rows === []) {
                continue;
            }
            $shown = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
            $this->table(
                ['ID', 'SKU', 'Klasa z nazwy', 'Powód'],
                array_map(static fn (array $r): array => [$r['id'], mb_substr($r['sku'], 0, 40), $r['class'], mb_substr($r['reason'], 0, 140)], $shown),
            );
            if (count($rows) > count($shown)) {
                $this->line(sprintf('… i jeszcze %d (--limit=0 pokaże wszystkie).', count($rows) - count($shown)));
            }
        }
        if ($yearNotes > 0) {
            $this->line('');
            $this->line("Informacyjnie: {$yearNotes} kart ma w payloadzie inny rok normy niż tabela dostawcy (sam rok to nie konflikt — S3 z 2011 = S3S z 2022).");
        }
        $this->line('');
        $this->line('„Tabela dostawcy sprzeczna z nazwą” to sprawa do weryfikacji przez człowieka — polecenie niczego nie zmienia.');
        if ($findings[self::CATEGORY_DATASHEET_ONLY] !== []) {
            $this->warn('„'.self::CATEGORY_DATASHEET_ONLY.'”: tych kart NIE przepuszczaj przez products:recheck-skus — '
                .'kasuje opis i zleca wzbogacanie z internetu, a opis kart tego konta pisze model wyłącznie z karty '
                .'katalogowej PDF (zlecenie przy synchronizacji konta). Nie trafiają do --out.');
        }

        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            return $this->writeOut($out, $findings[self::CATEGORY_FOREIGN_PAYLOAD]);
        }

        return self::SUCCESS;
    }

    /**
     * Karty powiązane z kontem, którego łącznik pisze opis wyłącznie z karty katalogowej PDF: id karty => „konto B2B
     * #7 ARTRA”. Konto bez działającego łącznika (brak klasy dla witryny) po prostu nie należy do tej grupy.
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

    /**
     * Ocena jednej karty. null = karta poza zakresem (brak klasy w nazwie i kodzie).
     *
     * @return array{class: string, ambiguous: bool, findings: array<string, string>, year_note: ?string}|null
     */
    public function audit(Product $product): ?array
    {
        $nameBases = $this->bases($this->classes((string) $product->name));
        if ($nameBases === []) {
            $nameBases = $this->bases($this->classes((string) $product->sku));
        }
        if ($nameBases === []) {
            return null;
        }
        $base = $nameBases[0];
        if (count($nameBases) > 1) {
            return ['class' => implode('/', $nameBases), 'ambiguous' => true, 'findings' => [], 'year_note' => null];
        }
        $family = $base[0];
        $nameText = (string) $product->name.' '.(string) $product->sku;
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $tableLines = $this->tableNormLines((string) $product->shop_fields_summary);

        $findings = [];

        // 1. Payload: adres źródła, potem treść
        $reasons = [];
        foreach ((array) ($payload['source_urls'] ?? []) as $url) {
            $path = $this->urlText((string) $url);
            $urlBases = $this->bases($this->classes($path));
            if ($urlBases !== [] && ! in_array($base, $urlBases, true)) {
                $reasons[] = 'adres źródła ma klasę '.implode('/', $urlBases).': '.$this->shortUrl((string) $url);

                continue;
            }
            foreach (self::EXTRA_MARKINGS as $marking) {
                if ($this->hasToken($path, $marking) && ! $this->hasToken($nameText, $marking)) {
                    $reasons[] = "adres źródła ma {$marking}, nazwa nie: ".$this->shortUrl((string) $url);

                    continue 2;
                }
            }
        }
        $payloadTexts = $this->payloadClassTexts($payload, $attributes);
        $payloadBases = $this->bases($this->classes(implode("\n", $payloadTexts)));
        if ($payloadBases !== [] && ! in_array($base, $payloadBases, true)) {
            $reasons[] = 'klasa w payloadzie: '.implode('/', $payloadBases);
        } elseif ($payloadBases === []) {
            $families = $this->normFamilies($this->payloadNormTexts($payload, $attributes));
            if ($families !== [] && ! in_array($family, $families, true)) {
                $reasons[] = 'norma w payloadzie tylko dla rodziny '.implode('/', $families);
            }
        }
        if ($reasons !== []) {
            $findings[self::CATEGORY_FOREIGN_PAYLOAD] = implode('; ', $reasons);
        }

        // 2. Tabela dostawcy („norma: …”) — klasa, a gdy jej brak, rodzina normy
        $tableBases = $this->bases($this->classes(implode("\n", $tableLines)));
        if ($tableBases !== [] && ! in_array($base, $tableBases, true)) {
            $findings[self::CATEGORY_SUPPLIER_TABLE] = 'tabela: '.implode(' | ', $tableLines);
        } elseif ($tableBases === []) {
            $families = $this->normFamilies($tableLines);
            if ($families !== [] && ! in_array($family, $families, true)) {
                $findings[self::CATEGORY_SUPPLIER_TABLE] = 'norma w tabeli dla rodziny '.implode('/', $families).': '.implode(' | ', $tableLines);
            }
        }

        // 3. Zapisane atrybuty bez klasy, choć tabela ją podaje
        if ($tableBases !== [] && trim((string) ($attributes['klasa_ochrony'] ?? '')) === '') {
            $findings[self::CATEGORY_MISSING_CLASS] = ($payload === [] ? 'karta bez payloadu; ' : '').'tabela: '.implode(' | ', $tableLines);
        }

        $tableYears = $this->normYears($tableLines);
        $payloadYears = $this->normYears($this->payloadNormTexts($payload, $attributes));
        $yearNote = $tableYears !== [] && $payloadYears !== [] && array_intersect($tableYears, $payloadYears) === []
            ? 'tabela '.implode('/', $tableYears).', payload '.implode('/', $payloadYears)
            : null;

        return ['class' => $base, 'ambiguous' => false, 'findings' => $findings, 'year_note' => $yearNote];
    }

    /**
     * Wszystkie klasy w tekście (nie tylko pierwsza). Łącznik „-”/„_” zamieniany na spację, bo wzorzec
     * klasy dopuszcza tylko odstęp: „S1-P” (Polstar) i „s1-p” w adresie to S1P.
     *
     * @return list<string>
     */
    private function classes(string $text): array
    {
        $text = str_replace(['-', '_'], ' ', $text);
        if (preg_match_all('/(?<![\p{L}\d])('.BhpAttributeNormalizer::FOOTWEAR_CLASS.')(?![\p{L}\d])/iu', $text, $m) === false) {
            return [];
        }

        return $m[1];
    }

    /**
     * @param  list<string>  $classes
     * @return list<string> bazy klas bez powtórzeń, w kolejności wystąpienia
     */
    private function bases(array $classes): array
    {
        $out = [];
        foreach ($classes as $class) {
            $base = $this->normalizer->footwearClassBase($class);
            if (! in_array($base, $out, true)) {
                $out[] = $base;
            }
        }

        return $out;
    }

    /**
     * Teksty payloadu, w których klasa jest twierdzeniem o wyrobie: klasa w atrybutach, zapisy norm
     * („EN ISO 20345:2011 S2 CI SRC”) i wiersze/cechy z „klasa …”. Wiersze kodu i nazwy („Kod producenta:
     * ARMEN 900 6060 O1 FO”) powtarzają nazwę karty, więc nie są dowodem.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function payloadClassTexts(array $payload, array $attributes): array
    {
        $texts = [];
        $klasa = trim((string) ($attributes['klasa_ochrony'] ?? ''));
        if ($klasa !== '') {
            $texts[] = $klasa;
        }
        foreach ($this->payloadStrings($payload, $attributes) as $line) {
            if (preg_match('/^\s*(kod|nazwa|model|symbol|sku|indeks)\b/iu', $line) === 1) {
                continue;
            }
            if (preg_match('/2034[57]/u', $line) === 1) {
                // tylko klasy po numerze normy — reszta wiersza bywa opisem („zamiast S1P wybierz…”)
                if (preg_match_all('/2034[57](?::\s*\d{4})?[^;,\n]*/u', $line, $m) !== false) {
                    foreach ($m[0] as $record) {
                        $texts[] = $record;
                    }
                }

                continue;
            }
            if (preg_match('/\bklas\p{L}*\b(.*)$/iu', $line, $m) === 1) {
                $texts[] = $m[1];
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function payloadNormTexts(array $payload, array $attributes): array
    {
        return array_values(array_filter(
            $this->payloadStrings($payload, $attributes),
            static fn (string $line): bool => preg_match('/2034[57]/u', $line) === 1,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function payloadStrings(array $payload, array $attributes): array
    {
        $out = [];
        foreach ([$payload['norms'] ?? [], $attributes['normy_en'] ?? [], $payload['specs'] ?? [], $payload['features'] ?? []] as $list) {
            foreach ((array) $list as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Linie „norma: …” z tabeli dostawcy (shop_fields_summary), bez normy ESD i innych bez 2034x.
     *
     * @return list<string>
     */
    private function tableNormLines(string $summary): array
    {
        if (preg_match_all('/^\s*norm[ay]\s*:\s*(.+)$/imu', $summary, $m) === false) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $m[1]),
            static fn (string $line): bool => preg_match('/2034[57]/u', $line) === 1,
        ));
    }

    /**
     * Rodzina z numeru normy: 20345 = obuwie bezpieczne (S), 20347 = zawodowe (O).
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function normFamilies(array $lines): array
    {
        $families = [];
        foreach ($lines as $line) {
            if (preg_match_all('/2034([57])/u', $line, $m) !== false) {
                foreach ($m[1] as $digit) {
                    $family = $digit === '5' ? 'S' : 'O';
                    if (! in_array($family, $families, true)) {
                        $families[] = $family;
                    }
                }
            }
        }

        return $families;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function normYears(array $lines): array
    {
        $years = [];
        foreach ($lines as $line) {
            if (preg_match_all('/2034[57]\s*:\s*(\d{4})/u', $line, $m) !== false) {
                foreach ($m[1] as $year) {
                    if (! in_array($year, $years, true)) {
                        $years[] = $year;
                    }
                }
            }
        }
        sort($years);

        return $years;
    }

    /** Ścieżka adresu bez domeny i query stringa, zdekodowana — tam sklepy piszą nazwę wariantu. */
    private function urlText(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return rawurldecode(is_string($path) ? $path : '');
    }

    private function shortUrl(string $url): string
    {
        $url = (string) preg_replace('/\?.*$/u', '', $url);

        return mb_strlen($url) > 110 ? mb_substr($url, 0, 107).'…' : $url;
    }

    private function hasToken(string $text, string $token): bool
    {
        $text = str_replace(['-', '_'], ' ', $text);

        return preg_match('/(?<![\p{L}\d])'.preg_quote($token, '/').'(?![\p{L}\d])/iu', $text) === 1;
    }

    /**
     * Kody kart do `products:recheck-skus --file=`: jeden kod w wierszu, komentarz po „#”. Kod
     * z przecinkiem, średnikiem albo tabulatorem rozbiłby się na części przy czytaniu — takie pomijamy.
     *
     * @param  list<array{id: int, sku: string, name: string, class: string, reason: string}>  $rows
     */
    private function writeOut(string $path, array $rows): int
    {
        $lines = [
            '# products:audit-footwear-identity '.now()->toDateTimeString().' — '.self::CATEGORY_FOREIGN_PAYLOAD,
            '# użycie: artisan products:recheck-skus --file=<ten plik> (podgląd), potem z --apply',
        ];
        $skipped = [];
        foreach ($rows as $row) {
            $sku = trim($row['sku']);
            if ($sku === '' || preg_match('/[;,\t#]/u', $sku) === 1) {
                $skipped[] = $row['id'];

                continue;
            }
            $lines[] = $sku.'   # id '.$row['id'].': '.str_replace(["\n", '#'], [' ', ''], mb_substr($row['reason'], 0, 160));
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
        $this->info(sprintf('Zapisano %d kodów do %s.', count($rows) - count($skipped), $path));
        if ($skipped !== []) {
            $this->warn('Pominięte (kod nie przejdzie przez --file): id '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
