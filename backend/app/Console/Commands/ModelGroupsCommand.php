<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfile;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ModelKey;
use App\Services\Enrichment\ProductModelKey;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\SourceIdentity;
use App\Services\PriceListCards;
use App\Support\ColourWords;
use ErrorException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

/**
 * Grupy modeli kart (etap 2 opisów z cenników, 08.10.2026) — tylko odczyt. Klucz modelu liczony w locie
 * (ProductModelKey: marka | rodzina SKU | rdzeń nazwy bez wymiarów i kolorów) dla marki z profilem grupowania; karta
 * bez klucza = model z jedną kartą. Tabela grup, podsumowanie (karty, modele, pojedyncze, największa grupa, szacunek
 * wywołań modelu językowego) i lista podejrzanych sklejeń do przejrzenia przed pełnym pobraniem (plan §4 krok 2).
 *
 * --suspicious: ten sam rdzeń w kilku rodzinach SKU, grupy > BIG_GROUP kart, różne strony źródła opisu członków (po
 * zrównaniu wersji językowych witryny — localeInsensitiveUrlKey), mieszanka końcówek SKU (postać sprzedaży „C”,
 * przyrostek „-N”), zdjęte z nazwy słowa spoza słownika wymiarów, rozmiarów i kolorów. --judge: najczęstsza strona
 * źródła grupy (pamięć stron 24 h, wzorzec SourceIdentityProbeCommand::judge) i werdykt każdego członka na niej — ile
 * kart modelu dostałoby twardy werdykt, a ile poszłoby do przeglądu. --images: adresy zdjęć tej strony z rozpoznanym
 * kolorem (czy galeria ma kolory kart). --out: plik CSV, brakujący katalog powstaje. W pamięci tylko id, SKU, nazwa,
 * klucz i adres źródła kart (chunkById 200).
 */
final class ModelGroupsCommand extends Command
{
    private const CHUNK = 200;

    /** Grupa większa niż tyle kart jest podejrzana o sklejenie różnych wyrobów. */
    public const BIG_GROUP = 20;

    private const PREVIEW_ROWS = 40;

    /** Sekundy odstępu między stronami tej samej witryny przy --judge (cederroth.com po kilku zapytaniach odpowiada 429). */
    private const HOST_DELAY_SECONDS = 3.0;

    /** Słowa wyrażeń wymiarowych, które rdzeń zdejmuje razem z liczbami („0.9m x 18.3m”, „- maks. 10m”, „x mb.”). */
    private const DIMENSION_WORDS = ['x', '×', 'mm', 'cm', 'm', 'mb', 'mb.', 'maks', 'maks.', 'max', 'max.', 'do', '-', '–', '—'];

    protected $signature = 'products:model-groups
                            {--price-list= : Karty tego cennika (numer)}
                            {--manufacturer= : Karty tego producenta (bez rozróżniania wielkości liter)}
                            {--min-size=1 : Pokaż tylko grupy o co najmniej tylu kartach}
                            {--suspicious : Tylko grupy podejrzane o sklejenie różnych wyrobów}
                            {--judge : Pobierz najczęstszą stronę źródła grupy i policz werdykt każdego członka (pamięć stron 24 h)}
                            {--images : Przy --judge: adresy zdjęć tej strony z rozpoznanym kolorem}
                            {--limit=0 : Najwyżej tyle grup w tabeli i przy --judge (0 = wszystkie)}
                            {--out= : Plik CSV z grupami}';

    protected $description = 'Grupy modeli kart (klucz modelu w locie): tabela, podsumowanie, podejrzane sklejenia, werdykt członków na stronie modelu — niczego nie zmienia';

    /** @var array<string, float> host => czas ostatniego pobrania (microtime) */
    private array $lastFetchAt = [];

    public function __construct(
        private readonly ProductModelKey $modelKeys,
        private readonly ManufacturerProfiles $profiles,
        private readonly PriceListCards $cards,
        private readonly SourceIdentity $identity,
        private readonly ProductPageFetcher $pages,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = $this->scopedQuery();
        if ($query === null) {
            return self::FAILURE;
        }
        $minSize = max(1, (int) $this->option('min-size'));
        $limit = max(0, (int) $this->option('limit'));
        $onlySuspicious = (bool) $this->option('suspicious');
        $judge = (bool) $this->option('judge');
        $images = (bool) $this->option('images');

        /** @var array<string, array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array{id: int, sku: string, name: string, url: ?string, verdict: ?string}>}> $groups */
        $groups = [];
        $cardsTotal = 0;
        $withoutKey = 0;
        $query->select([
            'id', 'sku', 'name', 'manufacturer',
            'enrichment_payload->primary_source_url as primary_source_url',
            'enrichment_payload->identity->verdict as identity_verdict',
        ])->chunkById(self::CHUNK, function ($products) use (&$groups, &$cardsTotal, &$withoutKey): void {
            foreach ($products as $product) {
                /** @var Product $product */
                $cardsTotal++;
                $profile = $this->profiles->for($product);
                $key = $this->modelKeys->for($product, $profile);
                if ($key === null) {
                    $withoutKey++;

                    continue;
                }
                $groups[$key->key] ??= ['key' => $key, 'profile' => $profile, 'cards' => []];
                $groups[$key->key]['cards'][] = [
                    'id' => (int) $product->id,
                    'sku' => (string) $product->sku,
                    'name' => (string) $product->name,
                    'url' => $this->jsonString($product->getAttribute('primary_source_url')),
                    'verdict' => $this->jsonString($product->getAttribute('identity_verdict')),
                ];
            }
        });

        if ($cardsTotal === 0) {
            $this->info('Brak kart dla tego filtra.');

            return self::SUCCESS;
        }

        uasort($groups, static fn (array $a, array $b): int => [count($b['cards']), $a['key']->key] <=> [count($a['cards']), $b['key']->key]);
        $familiesByStem = $this->familiesByStem($groups);
        $rows = [];
        foreach ($groups as $group) {
            $reasons = $this->suspicions($group, $familiesByStem);
            if (count($group['cards']) < $minSize || ($onlySuspicious && $reasons === [])) {
                continue;
            }
            $rows[] = ['group' => $group, 'reasons' => $reasons, 'judge' => null];
        }
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        if ($judge) {
            foreach ($rows as $i => $row) {
                $rows[$i]['judge'] = $this->judgeGroup($row['group'], $images);
                $j = $rows[$i]['judge'];
                $this->line(sprintf(
                    '[%d/%d] %s (%d kart) → %s: hard %d · soft %d · none %d%s',
                    $i + 1, count($rows), $row['group']['key']->stem, count($row['group']['cards']), $j['url'] ?? 'bez strony',
                    $j['hard'], $j['soft'], $j['none'],
                    $images ? ' · zdjęć z kolorem '.count($j['images']) : '',
                ));
            }
        }

        $this->printTable($rows, $judge);
        $this->printSummary($groups, $rows, $cardsTotal, $withoutKey, $judge);

        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            if (! $this->writeOut($out, $rows, $judge)) {
                return self::FAILURE;
            }
            $this->info('Zapisano: '.$out);
        }

        return self::SUCCESS;
    }

    /** @return Builder<Product>|null */
    private function scopedQuery(): ?Builder
    {
        $priceListId = (int) $this->option('price-list');
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($priceListId <= 0 && $manufacturer === '') {
            $this->error('Podaj --price-list=<numer> albo --manufacturer=<nazwa> — bez filtra polecenie objęłoby cały katalog.');

            return null;
        }
        $query = Product::query();
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika {$priceListId}.");

                return null;
            }
            $ids = $this->cards->ids($priceList);
            $query->whereIntegerInRaw('id', $ids === [] ? [0] : $ids);
        }
        if ($manufacturer !== '') {
            $query->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]);
        }

        return $query;
    }

    /**
     * Rdzeń (w obrębie marki) => rodziny SKU, w których występuje — ten sam rdzeń w kilku rodzinach to osobne grupy
     * (klucz zawiera rodzinę), ale sygnał, że nazwa nie rozróżnia wyrobów albo rodzina jest przypadkowa.
     *
     * @param  array<string, array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array<string, mixed>>}>  $groups
     * @return array<string, list<string>>
     */
    private function familiesByStem(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $stemKey = $group['key']->brandKey.'|'.$group['key']->stem;
            $out[$stemKey] ??= [];
            if (! in_array($group['key']->family, $out[$stemKey], true)) {
                $out[$stemKey][] = $group['key']->family;
            }
        }

        return $out;
    }

    /**
     * @param  array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array{id: int, sku: string, name: string, url: ?string, verdict: ?string}>}  $group
     * @param  array<string, list<string>>  $familiesByStem
     * @return list<string>
     */
    private function suspicions(array $group, array $familiesByStem): array
    {
        $reasons = [];
        $families = $familiesByStem[$group['key']->brandKey.'|'.$group['key']->stem] ?? [];
        if (count($families) > 1) {
            $reasons[] = 'rdzeń w kilku rodzinach SKU: '.implode(', ', $families);
        }
        if (count($group['cards']) > self::BIG_GROUP) {
            $reasons[] = 'grupa > '.self::BIG_GROUP.' kart ('.count($group['cards']).')';
        }
        $urls = [];
        foreach ($group['cards'] as $card) {
            if ($card['url'] !== null) {
                $urls[self::localeInsensitiveUrlKey($card['url'])] = true;
            }
        }
        if (count($urls) > 1) {
            $reasons[] = 'różne strony źródła członków ('.count($urls).')';
        }
        if ($this->mixedSuffixes($group)) {
            $reasons[] = 'mieszanka końcówek SKU (C / -N)';
        }
        $outside = [];
        foreach ($group['cards'] as $card) {
            foreach ($this->strippedWordsOutsideDictionary($card['name'], $group['key']->stem) as $word) {
                $outside[$word] = true;
            }
        }
        if ($outside !== []) {
            $reasons[] = 'zdjęte słowa spoza słownika: '.implode(', ', array_slice(array_keys($outside), 0, 6));
        }

        return $reasons;
    }

    /**
     * Adres strony źródła po zrównaniu wersji językowych witryny producenta: „/product/orthomat”, „/pl/produkt/orthomat”
     * i „/de/produkt/orthomat” to ta sama strona modelu (coba.com: 83 ze 131 grup flagowanych jako „różne strony” różniło
     * się tylko językiem, po zrównaniu 46). Znika pierwszy segment języka („/pl/”, „/de/”, „/za/”, „/en-gb/”) i słowo
     * „produkt” w kilku językach zrównuje się z „product”; inne różnice ścieżki zostają (to naprawdę inne strony).
     * ProductSearchIdentity::preferredLocaleUrl nie pomaga — zna tylko końcówkę „--xx” i karty Ansella.
     */
    private static function localeInsensitiveUrlKey(string $url): string
    {
        $key = Product::normalizeShopUrl($url);
        $slash = strpos($key, '/');
        if ($slash === false) {
            return $key;
        }
        $path = substr($key, $slash);
        $path = preg_replace('#^/[a-z]{2}(?:-[a-z]{2,4})?(?=/|$)#', '', $path) ?? $path;
        $path = preg_replace('#^/(?:produkt|produkte|produit|producto|prodotto|produto)(?=/|$)#', '/product', $path) ?? $path;

        return substr($key, 0, $slash).$path;
    }

    /**
     * Część kart grupy ma końcówkę postaci sprzedaży (profil: variant_suffixes, Coba „C” = na metry) albo przyrostek
     * „-N” po myślniku (dash_suffix_pad), a część nie — scalone mogły być różne postaci tego samego wyrobu (słusznie)
     * albo różne wyroby o tej samej nazwie.
     *
     * @param  array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array{id: int, sku: string, name: string, url: ?string, verdict: ?string}>}  $group
     */
    private function mixedSuffixes(array $group): bool
    {
        if (count($group['cards']) < 2) {
            return false;
        }
        $suffixes = $group['profile']?->variantSuffixes ?? [];
        $letterPattern = $suffixes === []
            ? null
            : '/\d(?:'.implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $suffixes)).')$/i';
        $dashPattern = ($group['profile']?->dashSuffixPad ?? 0) > 0 ? '/-\d{1,2}$/' : null;
        foreach ([$letterPattern, $dashPattern] as $pattern) {
            if ($pattern === null) {
                continue;
            }
            $with = 0;
            foreach ($group['cards'] as $card) {
                $with += preg_match($pattern, $card['sku']) === 1 ? 1 : 0;
            }
            if ($with > 0 && $with < count($group['cards'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Słowa nazwy, których nie ma w rdzeniu, poza słownikiem tego, co rdzeń ma prawo zdjąć (wymiary, rozmiary, kolory).
     * Różnica wielozbiorów słów nazwy i rdzenia — gdy rdzeń zdjął coś innego, to sygnał błędu reguły (albo luki słownika).
     *
     * @return list<string>
     */
    private function strippedWordsOutsideDictionary(string $name, string $stem): array
    {
        $tokens = static fn (string $s): array => preg_split('/[\s,;:()\[\]\/]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $left = array_count_values($tokens($stem));
        $out = [];
        foreach ($tokens($name) as $token) {
            if (($left[$token] ?? 0) > 0) {
                $left[$token]--;

                continue;
            }
            $word = trim($token, ".-–—\"'„”");
            if ($word === '' || $this->isDimensionToken($word) || $this->isSizeToken($word) || ColourWords::is($word)) {
                continue;
            }
            $out[$word] = true;
        }

        return array_keys($out);
    }

    private function isDimensionToken(string $word): bool
    {
        return in_array($word, self::DIMENSION_WORDS, true)
            || preg_match('/^[\d.,]+(?:mm|cm|m|mb|mm²|m²|kg|g|l|ml|"|”|″)?$/u', $word) === 1;
    }

    /** Rozmiar odzieżowy („XL”, „2XL”, „S-M”) albo numeryczny z zakresem („42-43”, „7/8”). */
    private function isSizeToken(string $word): bool
    {
        return preg_match('/^(?:xs|s|m|l|xl|xxl|xxxl|\d?x{1,3}l|xs-s|s-m|m-l|l-xl|xl-xxl|\d{1,2}(?:[-\/]\d{1,2})?)$/i', $word) === 1;
    }

    /**
     * Najczęstsza strona źródła grupy (najpierw wśród kart z twardym werdyktem w payloadzie) pobrana jak w przebiegu
     * (pamięć stron 24 h; gdy bramka pobierania odrzuci stronę — drugi odczyt bez kodu karty) i werdykt każdego członka
     * na niej (SourceIdentity::judgePage). Zdjęcia strony z rozpoznanym kolorem przy --images.
     *
     * @param  array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array{id: int, sku: string, name: string, url: ?string, verdict: ?string}>}  $group
     * @return array{url: ?string, hard: int, soft: int, none: int, images: list<array{colour: string, url: string}>, note: string}
     */
    private function judgeGroup(array $group, bool $withImages): array
    {
        $result = ['url' => null, 'hard' => 0, 'soft' => 0, 'none' => 0, 'images' => [], 'note' => ''];
        $url = $this->mostFrequentUrl($group['cards']);
        if ($url === null) {
            $result['note'] = 'karty bez źródła opisu';

            return $result;
        }
        $result['url'] = $url;
        $this->paceHost($url);
        $raw = $this->pages->fetchRaw($url);
        $row = ['url' => $url, 'title' => $raw !== null ? ($this->pages->pageTitles($raw['html'])[0] ?? '') : '', 'snippet' => ''];
        $fetched = $this->pages->fetch([$row], $group['cards'][0]['sku'], 1, [], null);
        if ($fetched['pages'] === []) {
            $fetched = $this->pages->fetch([$row], '', 1, [], null);
        }
        $page = $fetched['pages'][0] ?? null;
        if (! is_array($page)) {
            $result['note'] = 'strona nie pobrana';

            return $result;
        }

        $ids = array_column($group['cards'], 'id');
        $members = Product::query()->whereIntegerInRaw('id', $ids)->orderBy('id')->get();
        foreach ($members as $member) {
            $verdict = $this->identity->judgePage($member, $page, $this->profiles->for($member))['verdict'];
            $result[$verdict] = ($result[$verdict] ?? 0) + 1;
        }
        if ($withImages) {
            $imageUrls = is_array($fetched['image_urls'] ?? null) ? $fetched['image_urls'] : [];
            foreach ($imageUrls as $imageUrl) {
                // wszystkie kolory z nazwy pliku („black/yellow”) — karta dwubarwna dostaje tylko plik z równym zbiorem
                $colours = is_string($imageUrl) ? ColourWords::allInUrl($imageUrl) : [];
                if ($colours !== []) {
                    $result['images'][] = ['colour' => implode('/', $colours), 'url' => $imageUrl];
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, url: ?string, verdict: ?string}>  $cards
     */
    private function mostFrequentUrl(array $cards): ?string
    {
        foreach ([true, false] as $hardOnly) {
            $counts = [];
            $first = [];
            foreach ($cards as $card) {
                if ($card['url'] === null || ($hardOnly && $card['verdict'] !== SourceIdentity::HARD)) {
                    continue;
                }
                $key = Product::normalizeShopUrl($card['url']);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $first[$key] ??= $card['url'];
            }
            if ($counts !== []) {
                arsort($counts);

                return $first[array_key_first($counts)];
            }
        }

        return null;
    }

    /** Odstęp między stronami tej samej witryny — jak products:baseline-versions; w testach strony są z atrapy. */
    private function paceHost(string $url): void
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || app()->runningUnitTests()) {
            return;
        }
        $wait = ($this->lastFetchAt[$host] ?? 0.0) + self::HOST_DELAY_SECONDS - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastFetchAt[$host] = microtime(true);
    }

    /**
     * @param  list<array{group: array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array<string, mixed>>}, reasons: list<string>, judge: ?array<string, mixed>}>  $rows
     */
    private function printTable(array $rows, bool $judge): void
    {
        if ($rows === []) {
            $this->line('Żadna grupa nie spełnia warunków (min-size / suspicious).');

            return;
        }
        $table = [];
        foreach (array_slice($rows, 0, self::PREVIEW_ROWS) as $row) {
            $skus = array_column($row['group']['cards'], 'sku');
            $line = [
                $row['group']['key']->family,
                mb_substr($row['group']['key']->stem, 0, 45),
                count($row['group']['cards']),
                implode(', ', array_slice($skus, 0, 3)).(count($skus) > 3 ? ', …' : ''),
                mb_substr(implode('; ', $row['reasons']), 0, 60),
            ];
            if ($judge) {
                $j = $row['judge'];
                $line[] = $j === null ? '—' : ($j['url'] !== null ? mb_substr($j['url'], 0, 50) : $j['note']);
                $line[] = $j === null ? '' : "{$j['hard']}/{$j['soft']}/{$j['none']}";
            }
            $table[] = $line;
        }
        $headers = ['Rodzina', 'Rdzeń', 'Kart', 'SKU', 'Podejrzane'];
        if ($judge) {
            $headers[] = 'Strona';
            $headers[] = 'hard/soft/none';
        }
        $this->table($headers, $table);
        if (count($rows) > self::PREVIEW_ROWS) {
            $this->line('… i '.(count($rows) - self::PREVIEW_ROWS).' grup więcej (pełna lista w --out=).');
        }
    }

    /**
     * @param  array<string, array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array<string, mixed>>}>  $groups
     * @param  list<array{group: array<string, mixed>, reasons: list<string>, judge: ?array<string, mixed>}>  $rows
     */
    private function printSummary(array $groups, array $rows, int $cardsTotal, int $withoutKey, bool $judge): void
    {
        $models = count($groups) + $withoutKey;
        $singles = $withoutKey;
        $largest = null;
        foreach ($groups as $group) {
            $size = count($group['cards']);
            if ($size === 1) {
                $singles++;
            }
            if ($largest === null || $size > count($largest['cards'])) {
                $largest = $group;
            }
        }
        $suspicious = count(array_filter($rows, static fn (array $row): bool => $row['reasons'] !== []));
        $this->newLine();
        $this->info(sprintf(
            'Kart: %d · modeli: %d (grup z kluczem %d, kart bez klucza %d) · pojedynczych: %d · największa grupa: %s · podejrzanych w tabeli: %d.',
            $cardsTotal, $models, count($groups), $withoutKey, $singles,
            $largest === null ? '—' : count($largest['cards']).' kart („'.$largest['key']->stem.'”)',
            $suspicious,
        ));
        $this->info(sprintf(
            'Szacunek wywołań modelu językowego przy pełnym pobraniu: %d (liderzy grup + karty bez klucza) zamiast %d (po jednym na kartę).',
            $models, $cardsTotal,
        ));
        if (! $judge) {
            return;
        }
        $judged = array_values(array_filter($rows, static fn (array $row): bool => is_array($row['judge']) && $row['judge']['url'] !== null && $row['judge']['note'] === ''));
        $hard = array_sum(array_map(static fn (array $row): int => $row['judge']['hard'], $judged));
        $soft = array_sum(array_map(static fn (array $row): int => $row['judge']['soft'], $judged));
        $none = array_sum(array_map(static fn (array $row): int => $row['judge']['none'], $judged));
        $total = $hard + $soft + $none;
        $this->info(sprintf(
            'Werdykt członków na stronie modelu (%d grup z pobraną stroną, %d kart): hard %d (%s), soft %d, none %d — do przeglądu trafiłoby %d kart.',
            count($judged), $total, $hard, $total > 0 ? number_format(100 * $hard / $total, 1, ',', '').'%' : '—', $soft, $none, $soft + $none,
        ));
    }

    /**
     * Plik CSV z grupami; brakujący katalog powstaje (plan: storage/app/reports/). false = nie dało się zapisać
     * (komunikat już wypisany) — polecenie kończy się błędem, nie „Zapisano”.
     *
     * @param  list<array{group: array{key: ModelKey, profile: ?ManufacturerProfile, cards: list<array<string, mixed>>}, reasons: list<string>, judge: ?array<string, mixed>}>  $rows
     */
    private function writeOut(string $path, array $rows, bool $judge): bool
    {
        try {
            File::ensureDirectoryExists(dirname($path));
            $handle = fopen($path, 'wb');
        } catch (ErrorException) {
            // ostrzeżenie mkdir/fopen (ścieżka przez plik, brak uprawnień) Laravel zamienia w wyjątek
            $handle = false;
        }
        if ($handle === false) {
            $this->error("Nie da się zapisać pliku: {$path}");

            return false;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Klucz', 'Marka', 'Rodzina', 'Rdzeń', 'Kart', 'Karty', 'SKU', 'Podejrzane', 'Strona', 'hard', 'soft', 'none', '% hard', 'Zdjęcia z kolorem'], ';');
        foreach ($rows as $row) {
            $key = $row['group']['key'];
            $j = $row['judge'];
            $total = $j === null ? 0 : $j['hard'] + $j['soft'] + $j['none'];
            fputcsv($handle, [
                $key->key, $key->brandKey, $key->family, $key->stem, count($row['group']['cards']),
                implode(' ', array_column($row['group']['cards'], 'id')),
                implode(' ', array_column($row['group']['cards'], 'sku')),
                implode('; ', $row['reasons']),
                $judge && $j !== null ? (string) ($j['url'] ?? $j['note']) : '',
                $judge && $j !== null ? (string) $j['hard'] : '',
                $judge && $j !== null ? (string) $j['soft'] : '',
                $judge && $j !== null ? (string) $j['none'] : '',
                $judge && $total > 0 ? number_format(100 * $j['hard'] / $total, 1, ',', '') : '',
                $judge && $j !== null ? implode(' ', array_map(static fn (array $img): string => $img['colour'].':'.$img['url'], $j['images'])) : '',
            ], ';');
        }
        fclose($handle);

        return true;
    }

    /** Wartość ścieżki JSON: MySQL json_unquote zwraca napis „null” dla JSON null. */
    private function jsonString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || $value === 'null' ? null : $value;
    }
}
