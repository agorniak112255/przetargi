<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Support\BhpAttributeNormalizer;
use App\Support\ProductModelFuzzy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Przywraca opisy kart zastąpione tekstem wspólnym dla wielu modeli jednego konta B2B. Powód: artra.pl ma na każdej
 * karcie ten sam slogan „Konstrukcja obuwia ARELAX® zapewnia przestrzeń…”, a synchronizacja (witryna producenta
 * zastępuje opis karty) nadpisała nim opisy AI w 171 kartach; stare opisy zostały w
 * enrichment_payload.replaced_description (jeden poziom historii). Od 22.09.2026 łącznik ARTRY opisu nie oddaje
 * (B2bDatasheetOnlyDescription), więc slogan już nie wraca — ta komenda naprawia to, co zostało zapisane wcześniej.
 *
 * Karta bierze udział, gdy:
 * 1. powiązanie tego konta ma description_hash = sha1 obecnego opisu — opis zapisała synchronizacja i nikt go od tamtej
 *    pory nie zmienił;
 * 2. ten sam tekst mają karty co najmniej MIN_MODELS różnych MODELI tego konta — to slogan, a nie opis wyrobu. Liczymy
 *    modele (para słowo + liczba z nazwy: „ARMEN 900”, patrz modelKey), nie karty: warianty rozmiaru, koloru i klasy
 *    jednego modelu (Anro, Ardon, UVEX) dzielą prawdziwy opis i liczone kartami łatwo przekraczają próg;
 * 3. replaced_description jest opisem (Product::isDescriptionText), nie zawiera obecnego tekstu wspólnego ani tekstu
 *    karty technicznej doklejonego przez synchronizację (B2bCatalogSync::DATASHEET_MARK — 16 kart ARTRY miało tam
 *    slogan + „Z karty technicznej (Karta produktu): …”) i nie podaje klasy obuwia o innej bazie niż klasa z nazwy
 *    karty — opisy AI bywały pisane ze stron wariantu o innej klasie tego samego modelu (natare, empik).
 *
 * Domyślnie tylko konta łącznika B2bDatasheetOnlyDescription (ARTRA), dla których powstała; inne po --any-connector.
 *
 * Wyjątek: łącznik, którego opis kart pisze model z karty katalogowej PDF (B2bDatasheetOnlyDescription — ARTRA,
 * decyzja użytkownika 22.09.2026: opis wyłącznie z PDF i tabeli producenta). Karta takiego konta, którą job opisu
 * z PDF podejmie (DescribeB2bProductFromDatasheetJob::sources — odczytana karta katalogowa, opis z synchronizacji,
 * brak odrzucenia dla tych samych źródeł), nie wraca do opisu AI ze sklepów internetowych (tam bywały cudze warianty i starsze wydania modelu), tylko
 * dostaje przy --apply zlecenie opisu z PDF (DescribeB2bProductFromDatasheetJob). Slogan zostaje na karcie do zapisu
 * joba, a job podmienia też listy payloadu (specs, features, normy, źródła) — więc znikają i dane z cudzych stron.
 *
 * Reszta kart z punktów 1–2 idzie na listę „do opisu z PDF / ponownego wzbogacenia” — komenda jej nie zmienia.
 *
 * Zapis przez save(): haki modelu przeliczają search_blob i zlecają reindeks embeddingu. Odcisk powiązania zostaje
 * sha1(sloganu), więc po przywróceniu przestaje zgadzać się z opisem — synchronizacja ani job opisu z PDF nie uznają
 * przywróconego opisu za swój i go nie nadpiszą. Slogan przechodzi do replaced_description (odwrotna zamiana).
 * Przed pierwszą zmianą powstaje kopia (--backup), którą --restore cofa kartę po karcie.
 */
final class B2bRestoreReplacedDescriptionsCommand extends Command
{
    /** Tyle różnych modeli konta z tym samym opisem czyni z niego tekst wspólny sklepu, a nie opis wyrobu. */
    public const MIN_MODELS = 5;

    /** Oznaczenia obuwia przed numerem artykułu („SRC 68308” w nazwach UVEX) — to nie para model + numer. */
    private const MARKING_WORDS = ['src', 'sra', 'srb', 'esd', 'hro', 'wru', 'wr', 'ci', 'hi', 'fo'];

    private const VERDICT_RESTORE = 'restore';

    private const VERDICT_NO_REPLACED = 'no_replaced';

    private const VERDICT_CLASS_CONFLICT = 'class_conflict';

    private const VERDICT_DATASHEET = 'datasheet';

    protected $signature = 'b2b:restore-replaced-descriptions
        {--account= : Konto B2B (id), którego tekst wspólny zastąpił opisy kart}
        {--apply : Przywróć opisy (bez tej flagi tylko podgląd)}
        {--backup= : Plik kopii przed zmianą (domyślnie storage/app/repair-backups/restore-replaced-<konto>-<data>.json)}
        {--restore= : Cofnij zmiany z podanego pliku kopii}
        {--show= : Szczegóły jednej karty (id produktu)}
        {--any-connector : Także konto, którego łącznik nie pisze opisów wyłącznie z karty katalogowej PDF}';

    protected $description = 'Przywraca opisy kart zastąpione tekstem wspólnym dla wielu modeli konta B2B (slogan ARTRY; podgląd bez --apply)';

    public function handle(BhpAttributeNormalizer $normalizer, B2bConnectorRegistry $registry): int
    {
        $restoreFile = trim((string) $this->option('restore'));
        if ($restoreFile !== '') {
            return $this->restoreBackup($restoreFile);
        }

        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        $datasheetOnly = $this->describesOnlyFromDatasheet($account, $registry);
        if (! $datasheetOnly && ! $this->option('any-connector')) {
            $this->error('Konto #'.$account->id.' nie ma łącznika z opisem wyłącznie z karty katalogowej PDF (ARTRA), '
                .'dla którego powstała ta komenda. Inne konto: dodaj --any-connector.');

            return self::FAILURE;
        }

        $rows = $this->collect((int) $account->id, $normalizer, $datasheetOnly);

        $show = trim((string) $this->option('show'));
        if ($show !== '') {
            return $this->showCard($rows, (int) $show);
        }

        if ($rows === []) {
            $this->info('Brak kart z opisem wspólnym dla co najmniej '.self::MIN_MODELS.' różnych modeli tego konta.');

            return self::SUCCESS;
        }

        $restore = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] === self::VERDICT_RESTORE));
        $datasheet = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] === self::VERDICT_DATASHEET));
        $rest = array_values(array_filter($rows, static fn (array $r): bool => ! in_array($r['verdict'], [self::VERDICT_RESTORE, self::VERDICT_DATASHEET], true)));
        $texts = count(array_unique(array_map(static fn (array $r): string => $r['text_sha1'], $rows)));

        $this->table(['', 'Kart'], [
            ['Opis wspólny dla ≥ '.self::MIN_MODELS.' modeli (tekstów: '.$texts.')', count($rows)],
            ['Do opisu z karty katalogowej PDF (zlecenie przy --apply)', count($datasheet)],
            ['Do przywrócenia z replaced_description', count($restore)],
            ['Bez poprzedniego opisu', count(array_filter($rest, static fn (array $r): bool => $r['verdict'] === self::VERDICT_NO_REPLACED))],
            ['Poprzedni opis z klasą obuwia innej bazy', count(array_filter($rest, static fn (array $r): bool => $r['verdict'] === self::VERDICT_CLASS_CONFLICT))],
        ]);
        if ($restore !== []) {
            $this->line('Do przywrócenia:');
            $this->table(['ID', 'SKU', 'Klasa z nazwy'], array_map(
                static fn (array $r): array => [$r['id'], $r['sku'], $r['card_class'] ?? '—'],
                $restore,
            ));
        }
        if ($rest !== []) {
            $this->line('Do opisu z PDF / ponownego wzbogacenia (komenda ich nie zmienia):');
            $this->table(['ID', 'SKU', 'Powód'], array_map(
                static fn (array $r): array => [$r['id'], $r['sku'], $r['reason']],
                $rest,
            ));
        }

        if (! $this->option('apply')) {
            $this->line('Podgląd — uruchom z --apply, żeby przywrócić opisy.');

            return self::SUCCESS;
        }
        if ($datasheet !== []) {
            foreach ($datasheet as $row) {
                // job sam sprawdza stan karty przy starcie i przy zapisie (slogan z odciskiem synchronizacji)
                DescribeB2bProductFromDatasheetJob::dispatch($row['id'], (int) $account->id, true);
            }
            $this->info(sprintf('Zlecono opis z karty katalogowej PDF: %d kart (kolejka %s).', count($datasheet), DescribeB2bProductFromDatasheetJob::QUEUE));
        }
        if ($restore === []) {
            $this->info('Nic do przywrócenia.');

            return self::SUCCESS;
        }

        return $this->apply($restore, (int) $account->id);
    }

    /** Łącznik konta pisze opisy wyłącznie z karty katalogowej PDF (B2bDatasheetOnlyDescription). */
    private function describesOnlyFromDatasheet(B2bAccount $account, B2bConnectorRegistry $registry): bool
    {
        try {
            return $registry->make($account, 0) instanceof B2bDatasheetOnlyDescription;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Karty konta z opisem wspólnym dla co najmniej MIN_MODELS kart i werdykt dla każdej.
     *
     * @return list<array{id: int, sku: string, name: string, description: string, text_sha1: string, replaced: string, card_class: string|null, verdict: string, reason: string}>
     */
    private function collect(int $accountId, BhpAttributeNormalizer $normalizer, bool $datasheetOnly): array
    {
        /** @var array<int, list<string>> $hashes id karty => odciski jej powiązań z tym kontem */
        $hashes = [];
        B2bProductLink::query()
            ->where('b2b_account_id', $accountId)
            ->whereNotNull('description_hash')
            ->where('description_hash', '!=', '')
            ->orderBy('id')
            ->chunkById(1000, function (Collection $links) use (&$hashes): void {
                foreach ($links as $link) {
                    $hashes[(int) $link->product_id][] = (string) $link->description_hash;
                }
            });

        /** @var array<string, list<Product>> $byText sha1 opisu => karty */
        $byText = [];
        foreach (array_chunk(array_keys($hashes), 1000) as $chunk) {
            $products = Product::query()->whereIn('id', $chunk)->get(['id', 'sku', 'name', 'description', 'enrichment_payload', 'shop_fields_summary']);
            foreach ($products as $product) {
                $description = (string) $product->description;
                if (trim($description) === '') {
                    continue;
                }
                $sha1 = sha1($description);
                if (in_array($sha1, $hashes[(int) $product->id], true)) {
                    $byText[$sha1][] = $product;
                }
            }
        }

        $fuzzy = new ProductModelFuzzy;
        $rows = [];
        foreach ($byText as $sha1 => $products) {
            $models = array_unique(array_map(fn (Product $p): string => $this->modelKey($p, $fuzzy), $products));
            if (count($models) < self::MIN_MODELS) {
                continue;
            }
            foreach ($products as $product) {
                $rows[] = $datasheetOnly && $this->jobWillDescribe($product, $accountId)
                    ? [...$this->verdict($product, $sha1, $normalizer), 'verdict' => self::VERDICT_DATASHEET, 'reason' => 'opis z karty katalogowej PDF']
                    : $this->verdict($product, $sha1, $normalizer);
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $rows;
    }

    /**
     * Job opisu z PDF podejmie kartę — ten sam warunek, którym job sprawdza stan przy starcie (odczytana karta
     * katalogowa, opis zapisany przez synchronizację, brak odrzucenia dla tych samych źródeł). Samo istnienie PDF-u
     * nie wystarcza: karta z odrzuconym opisem dostałaby zlecenie, które nic nie zmieni.
     */
    private function jobWillDescribe(Product $product, int $accountId): bool
    {
        $link = DescribeB2bProductFromDatasheetJob::link((int) $product->id, $accountId);

        return $link !== null && DescribeB2bProductFromDatasheetJob::sources(
            $product,
            $link,
            DescribeB2bProductFromDatasheetJob::datasheet((int) $product->id, $accountId),
            true,
        ) !== null;
    }

    /**
     * Model karty: pierwsza para słowo + liczba z nazwy („ARMEN 900 6060 O1 FO” i „ARMEN 900 6060 S1 P” → armen 900),
     * bez par z oznaczeniem obuwia przed numerem artykułu („SRC 68308”). Nazwa bez takiej pary: jej słowa bez tokenów
     * z cyframi i bez rozmiarów — warianty jednego modelu zlewają się w jeden, więc próg raczej nie zostanie
     * przekroczony, niż zostanie przekroczony na wyrost.
     */
    private function modelKey(Product $product, ProductModelFuzzy $fuzzy): string
    {
        $name = (string) $product->name;
        foreach ($fuzzy->catalogModelWordDigitPairs($name) as [$word, $number]) {
            if (! in_array($word, self::MARKING_WORDS, true)) {
                return $word.' '.$number;
            }
        }
        $words = array_filter(
            preg_split('/[\s,;:\/|()#]+/u', mb_strtolower($name)) ?: [],
            static fn (string $w): bool => $w !== '' && preg_match('/\d/u', $w) !== 1
                && preg_match('/^(?:x{0,3}s|m|x{0,3}l|rozm\p{L}*\.?)$/u', $w) !== 1,
        );

        return implode(' ', $words);
    }

    /**
     * @return array{id: int, sku: string, name: string, description: string, text_sha1: string, replaced: string, card_class: string|null, verdict: string, reason: string}
     */
    private function verdict(Product $product, string $sha1, BhpAttributeNormalizer $normalizer): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $replaced = is_string($payload['replaced_description'] ?? null) ? trim($payload['replaced_description']) : '';
        $cardClass = $normalizer->footwearClass((string) $product->name) ?? $normalizer->footwearClass((string) $product->sku);
        $row = [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'text_sha1' => $sha1,
            'replaced' => $replaced,
            'card_class' => $cardClass,
            'verdict' => self::VERDICT_RESTORE,
            'reason' => '',
        ];

        if (! Product::isDescriptionText($replaced)) {
            return [...$row, 'verdict' => self::VERDICT_NO_REPLACED, 'reason' => 'brak poprzedniego opisu'];
        }
        // Slogan z doklejonym tekstem PDF to też tekst sklepu, nie opis wyrobu. Po przywróceniu odcisk powiązania
        // przestałby pasować do opisu i ani synchronizacja, ani job opisu z PDF nie ruszyłyby już karty.
        $shared = trim((string) $product->description);
        if (($shared !== '' && str_contains($replaced, $shared)) || str_contains($replaced, B2bCatalogSync::DATASHEET_MARK)) {
            return [...$row, 'verdict' => self::VERDICT_NO_REPLACED,
                'reason' => 'brak poprzedniego opisu (w replaced_description tekst wspólny sklepu albo tekst karty technicznej)'];
        }
        $foreign = $cardClass === null ? [] : $this->foreignClasses($replaced, $cardClass, $normalizer);
        if ($foreign !== []) {
            return [...$row, 'verdict' => self::VERDICT_CLASS_CONFLICT,
                'reason' => 'poprzedni opis podaje klasę '.implode(', ', $foreign).' (karta: '.$cardClass.')'];
        }

        return $row;
    }

    /**
     * Wszystkie klasy obuwia w tekście, których baza różni się od bazy klasy karty (S1P = S1PL, S3 = S3L; S1 ≠ S1P).
     * Wzorzec ten sam co w BhpAttributeNormalizer, ale szukamy każdego wystąpienia, nie tylko pierwszego — opis może
     * podawać właściwą klasę w nazwie, a cudzą w zdaniu o normie.
     *
     * @return list<string>
     */
    private function foreignClasses(string $text, string $cardClass, BhpAttributeNormalizer $normalizer): array
    {
        $base = $normalizer->footwearClassBase($cardClass);
        preg_match_all('/(?<![\p{L}\d])('.BhpAttributeNormalizer::FOOTWEAR_CLASS.')(?![\p{L}\d])/iu', $text, $m);
        $foreign = [];
        foreach ($m[1] as $hit) {
            $class = $normalizer->footwearClass((string) $hit);
            if ($class !== null && $normalizer->footwearClassBase($class) !== $base) {
                $foreign[$class] = true;
            }
        }

        return array_keys($foreign);
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, description: string, text_sha1: string, replaced: string, card_class: string|null, verdict: string, reason: string}>  $rows
     */
    private function apply(array $rows, int $accountId): int
    {
        $ids = array_map(static fn (array $r): int => $r['id'], $rows);
        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/restore-replaced-'.$accountId.'-'.now()->format('Ymd-His').'.json');
        }
        $entries = [];
        foreach ($rows as $row) {
            $product = $products->get($row['id']);
            if (! $product instanceof Product) {
                continue;
            }
            $entries[] = [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'description' => $product->description,
                'enrichment_payload' => $product->enrichment_payload,
                // po tym poznamy przy --restore, że od przywrócenia nikt opisu nie zmienił
                'written_sha1' => sha1($row['replaced']),
            ];
        }
        $error = $this->writeBackup($backup, $accountId, $entries);
        if ($error !== null) {
            $this->error('Kopia zapasowa nie powstała ('.$error.') — nic nie zmieniono.');

            return self::FAILURE;
        }
        $this->line('Kopia zapasowa: '.$backup);

        $restored = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $done = DB::transaction(function () use ($row): bool {
                $product = Product::query()->lockForUpdate()->find($row['id']);
                // karta zmieniona od podglądu (import, job, człowiek) — zostaje, jak jest
                if ($product === null || sha1((string) $product->description) !== $row['text_sha1']) {
                    return false;
                }
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $payload['replaced_description'] = mb_substr((string) $product->description, 0, 10000);
                $payload['replaced_description_at'] = now()->toIso8601String();
                $payload['replaced_description_hash'] = sha1($row['replaced']);
                $payload['replaced_description_by'] = 'b2b:restore-replaced-descriptions';
                $product->description = $row['replaced'];
                $product->enrichment_payload = $payload;
                // haki modelu przeliczą search_blob i zlecą reindeks embeddingu
                $product->save();

                return true;
            });
            if ($done) {
                $restored++;
            } else {
                $skipped[] = $row['sku'];
            }
        }

        $this->info(sprintf('Przywrócono opisy: %d kart.', $restored));
        if ($skipped !== []) {
            $this->warn('Pominięte (karta zmieniła się od podglądu): '.implode('; ', $skipped));
        }
        $this->line('Cofnięcie: php artisan b2b:restore-replaced-descriptions --restore='.$backup);

        return self::SUCCESS;
    }

    /**
     * Kopia przed zmianą — jak ProductEnrichmentResetter::writeBackup, ale tylko pola, które komenda zmienia.
     * Plik czytamy z powrotem przed pierwszą zmianą: kopia musi dać się odczytać w całości.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function writeBackup(string $path, int $accountId, array $entries): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['label' => 'b2b:restore-replaced-descriptions', 'b2b_account_id' => $accountId, 'created_at' => now()->toIso8601String(), 'products' => $entries],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return count($read['products'] ?? []) === count($entries) ? null : 'kopia jest niekompletna';
    }

    /** Cofa zmiany z kopii — tylko karty, których opisu nikt nie zmienił od przywrócenia. */
    private function restoreBackup(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Brak pliku kopii: {$path}");

            return self::FAILURE;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Kopia jest uszkodzona: {$e->getMessage()}");

            return self::FAILURE;
        }

        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $done = $id > 0 && DB::transaction(function () use ($id, $entry): bool {
                $product = Product::query()->lockForUpdate()->find($id);
                if ($product === null || sha1((string) $product->description) !== (string) ($entry['written_sha1'] ?? '')) {
                    return false;
                }
                $product->description = $entry['description'] ?? null;
                $product->enrichment_payload = is_array($entry['enrichment_payload'] ?? null) ? $entry['enrichment_payload'] : null;
                $product->save();

                return true;
            });
            if ($done) {
                $restored++;
            } else {
                $skipped[] = (string) ($entry['sku'] ?? $id);
            }
        }

        $this->info(sprintf('Cofnięto zmiany: %d kart.', $restored));
        if ($skipped !== []) {
            $this->warn('Pominięte (karta zmieniła się od przywrócenia albo nie istnieje): '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, description: string, text_sha1: string, replaced: string, card_class: string|null, verdict: string, reason: string}>  $rows
     */
    private function showCard(array $rows, int $id): int
    {
        foreach ($rows as $row) {
            if ($row['id'] !== $id) {
                continue;
            }
            $this->line('Karta #'.$row['id'].' '.$row['sku'].' — '.$row['name']);
            $this->line('Klasa z nazwy: '.($row['card_class'] ?? '—'));
            $this->line('Werdykt: '.match ($row['verdict']) {
                self::VERDICT_RESTORE => 'do przywrócenia',
                self::VERDICT_DATASHEET => 'zlecenie opisu z karty katalogowej PDF przy --apply',
                default => 'do opisu z PDF / ponownego wzbogacenia — '.$row['reason'],
            });
            $this->line('Obecny opis (wspólny dla ≥ '.self::MIN_MODELS.' modeli):');
            $this->line($row['description']);
            $this->line('replaced_description:');
            $this->line($row['replaced'] !== '' ? $row['replaced'] : '—');

            return self::SUCCESS;
        }
        $this->warn("Karta #{$id} nie ma opisu wspólnego dla ≥ ".self::MIN_MODELS.' modeli tego konta — komenda jej nie dotyczy.');

        return self::SUCCESS;
    }

    private function resolveAccount(): ?B2bAccount
    {
        $option = trim((string) $this->option('account'));
        if ($option === '' || ! ctype_digit($option)) {
            $this->error('Podaj --account=<id konta B2B>.');

            return null;
        }
        $account = B2bAccount::query()->find((int) $option);
        if ($account === null) {
            $this->error("Nie ma konta B2B #{$option}.");
        }

        return $account;
    }
}
