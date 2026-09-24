<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\ProductVariant;
use App\Support\InquiryLinks;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Link w zapytaniu → karta w katalogu.
 *
 * Łączniki B2B zapisują przy karcie adres jej strony u producenta albo w sklepie
 * (products.shop_source_url, tabelka sklepu, warianty). Klient, który przysyła ten sam
 * adres, wskazuje tę kartę — to zgodność identyfikatora, nie ocena podobieństwa.
 * Nic nie pobieramy z sieci: porównujemy wyłącznie z adresami, które już mamy.
 */
final class InquiryProductLinks
{
    /** Tyle kart przy jednym linku — pod wspólnym adresem stoją kolory i rozmiary jednego wyrobu. */
    private const MAX_PRODUCTS = 5;

    /** Z tylu kart spod jednego adresu wybieramy te pięć — najpierw wariant wskazany w linku. */
    private const MAX_CARDS_PER_URL = 40;

    /** Tyle adresów z LIKE sprawdzamy kluczem; najkrótsze najpierw, bo szukany zawiera się w dłuższych. */
    private const MAX_URL_ROWS = 50;

    /**
     * Próbka tekstu tuż przy adresie (bez spacji i znaków), którą musi zawierać cytat pozycji,
     * żeby adres z tego wiersza maila był jej. Krótsza próbka („jak w linku”) pasuje do
     * cytatów obcych pozycji.
     */
    private const NEIGHBOUR_MIN = 16;

    private const NEIGHBOUR_MAX = 40;

    /** Tyle słów z adresu musi już stać we frazie, żeby ich nie dopisywać (0–1). */
    private const SLUG_KNOWN_SHARE = 0.6;

    /** @var array<string, array{match: string|null, products: list<Product>}> */
    private array $resolved = [];

    /**
     * Adresy przy pozycjach i karty, do których prowadzą.
     *
     * Adres należy do pozycji, gdy stoi w jej cytacie albo w tym samym wierszu maila co jej
     * cytat (model skraca cytat i gubi adres: „RS SPLIT KEV - rozmiar 10 - Supon Rzeszów ...”,
     * #64). Adres w osobnym wierszu (#62: link, pod nim „Zamów proszę 1 szt - kolor czarny”)
     * dostaje tylko jedyna pozycja maila — przy kilku nie wiadomo, której dotyczy — i tylko
     * wtedy, gdy prowadzi do karty: adres ze stopki nie może zmienić frazy wyszukiwania.
     *
     * Pozycja z kartą z linku szuka w katalogu nazwą tej karty — alternatywy mają być podobne
     * do wskazanej. Pozycja z nieznanym linkiem dostaje słowa z adresu, jeśli jej fraza ich nie
     * zawiera. Obie zmiany frazy są odnotowane (`query_source: link`), bo to nasz wniosek.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     items: list<array<string, mixed>>,
     *     products: array<string, list<array{product: Product, url: string, match: string, variant: string|null}>>
     * }
     */
    public function attach(array $items, string $body): array
    {
        $lines = $this->bodyLinks($body);
        $byItem = [];
        $taken = [];
        foreach ($items as $i => $item) {
            $quote = (string) ($item['quote'] ?? '');
            $own = [];
            foreach (InquiryLinks::extract($quote) as $url) {
                $own[$url] = 'quote';
            }
            $compactQuote = $this->compact(InquiryLinks::withoutUrls($quote));
            foreach ($lines as $link) {
                if (! isset($own[$link['url']]) && $this->sharesLine($link, $compactQuote)) {
                    $own[$link['url']] = 'line';
                }
            }
            foreach (array_keys($own) as $url) {
                $taken[$url] = true;
            }
            $byItem[$i] = $own;
        }
        if (count($items) === 1) {
            $only = array_key_first($items);
            foreach ($lines as $link) {
                if (! isset($taken[$link['url']])) {
                    $byItem[$only][$link['url']] ??= 'mail';
                }
            }
        }

        $products = [];
        foreach ($items as $i => $item) {
            // Fraza modelu też bywa z adresem; jej grupa wyników jest zapasem pozycji
            // (groupsForItem), więc klucz musi być czysty tak samo jak fraza z cytatu.
            foreach (['query', 'search_query'] as $field) {
                if (is_string($item[$field] ?? null) && InquiryLinks::extract($item[$field]) !== []) {
                    $item[$field] = InquiryLinks::withoutUrls($item[$field]);
                    $items[$i][$field] = $item[$field];
                }
            }
            $links = [];
            $found = [];
            foreach ($byItem[$i] ?? [] as $url => $origin) {
                $hit = $this->resolve($url);
                if ($hit['match'] === null && $origin === 'mail') {
                    continue;
                }
                $links[] = [
                    'url' => $url,
                    // skąd przy pozycji: z jej cytatu, z jej wiersza maila albo jedyny link w mailu
                    'origin' => $origin,
                    // exact = ten sam adres; shop_product = ten sam wyrób sklepu, inny wariant adresu
                    'match' => $hit['match'],
                    'product_ids' => array_map(static fn (Product $p): int => (int) $p->id, $hit['products']),
                ];
                $options = InquiryLinks::fragmentOptions($url);
                foreach ($hit['products'] as $product) {
                    if (count($found) < self::MAX_PRODUCTS && ! isset($found[(int) $product->id])) {
                        $found[(int) $product->id] = [
                            'product' => $product,
                            'url' => $url,
                            'match' => (string) $hit['match'],
                            // wariant z kotwicy linku, który podaje nazwa karty („kolor 26”)
                            'variant' => InquiryLinks::variantNamedIn((string) $product->name, $options),
                        ];
                    }
                }
            }
            if ($links === []) {
                continue;
            }
            $item['links'] = $links;
            $items[$i] = $this->withLinkQuery($item, array_values($found), $links);
            if ($found !== []) {
                $products[(string) ($item['id'] ?? '')] = array_values($found);
            }
        }

        return ['items' => array_values($items), 'products' => $products];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array{product: Product, url: string, match: string, variant: string|null}>  $found
     * @param  list<array{url: string, origin: string, match: string|null, product_ids: list<int>}>  $links
     * @return array<string, mixed>
     */
    private function withLinkQuery(array $item, array $found, array $links): array
    {
        $base = InquiryLinks::withoutUrls((string) ($item['search_query'] ?? ''));
        $lead = '';
        if ($found !== []) {
            $lead = trim((string) $found[0]['product']->name);
        } else {
            foreach ($links as $link) {
                $words = InquiryLinks::slugWords($link['url']);
                if ($words !== '' && ! $this->mostlyKnown($words, $base)) {
                    $lead = $words;
                    break;
                }
            }
        }

        if ($lead !== '' && ! str_contains($this->compact($base), $this->compact($lead))) {
            $item['search_query'] = mb_substr(trim($lead.' '.$base), 0, 140);
            $item['query_source'] = 'link';
        } else {
            $item['search_query'] = $base;
        }

        return $item;
    }

    /** Czy słowa z adresu już stoją we frazie („rekawice-spawalnicze-…” przy cytacie z tą nazwą). */
    private function mostlyKnown(string $words, string $text): bool
    {
        $fold = static fn (string $s): string => Str::ascii(mb_strtolower($s));
        $have = preg_split('/[^a-z0-9-]+/', $fold($text)) ?: [];
        $need = array_filter(
            preg_split('/[^a-z0-9-]+/', $fold($words)) ?: [],
            static fn (string $w): bool => strlen($w) >= 3,
        );
        if ($need === []) {
            return true;
        }
        $known = count(array_intersect($need, $have));

        return $known / count($need) >= self::SLUG_KNOWN_SHARE;
    }

    /**
     * Adresy z każdego wiersza maila z tekstem tuż przed i tuż za nimi.
     *
     * @return list<array{url: string, before: string, after: string}>
     */
    private function bodyLinks(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            foreach (InquiryLinks::extract($line) as $url) {
                $at = mb_strpos($line, $url);
                if ($at === false) {
                    continue;
                }
                $out[] = [
                    'url' => $url,
                    'before' => $this->compact(InquiryLinks::withoutUrls(mb_substr($line, 0, $at))),
                    'after' => $this->compact(InquiryLinks::withoutUrls(mb_substr($line, $at + mb_strlen($url)))),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array{url: string, before: string, after: string}  $link
     */
    private function sharesLine(array $link, string $compactQuote): bool
    {
        if (mb_strlen($compactQuote) < self::NEIGHBOUR_MIN) {
            return false;
        }
        $before = mb_substr($link['before'], -self::NEIGHBOUR_MAX);
        $after = mb_substr($link['after'], 0, self::NEIGHBOUR_MAX);

        return (mb_strlen($before) >= self::NEIGHBOUR_MIN && str_contains($compactQuote, $before))
            || (mb_strlen($after) >= self::NEIGHBOUR_MIN && str_contains($compactQuote, $after));
    }

    private function compact(string $text): string
    {
        return preg_replace('/[^\p{L}\d]+/u', '', mb_strtolower($text)) ?? '';
    }

    /**
     * @return array{match: string|null, products: list<Product>}
     */
    private function resolve(string $url): array
    {
        $key = InquiryLinks::key($url);
        if ($key === null) {
            return ['match' => null, 'products' => []];
        }

        // po całym adresie, nie po kluczu: kotwica („#/34-kolor-26”) ustala kolejność kart
        return $this->resolved[$url] ??= $this->lookup($url, $key);
    }

    /**
     * @return array{match: string|null, products: list<Product>}
     */
    private function lookup(string $url, string $key): array
    {
        $hostPath = InquiryLinks::hostPath($key);
        // Strona główna („supon.rzeszow.pl”) nie wskazuje żadnego wyrobu.
        if (! str_contains($hostPath, '/')) {
            return ['match' => null, 'products' => []];
        }

        $ids = $this->productIdsByUrl(
            [$hostPath, ...InquiryLinks::queryPairs($key)],
            static fn (string $stored): bool => InquiryLinks::key($stored) === $key,
        );
        if ($ids !== []) {
            return ['match' => 'exact', 'products' => $this->products($ids, $url)];
        }

        $shop = InquiryLinks::shopProductKey($url);
        if ($shop !== null) {
            $ids = $this->productIdsByUrl(
                [$shop['host'].'/', '/'.$shop['id'].'-', '-'.$shop['name'].'.htm'],
                static fn (string $stored): bool => InquiryLinks::shopProductKey($stored) === $shop,
            );
            if ($ids !== []) {
                return ['match' => 'shop_product', 'products' => $this->products($ids, $url)];
            }
        }

        return ['match' => null, 'products' => []];
    }

    /**
     * Karty, przy których zapisano adres zawierający wszystkie fragmenty i zgodny kluczem.
     * LIKE zawęża, a o zgodności rozstrzyga klucz liczony tak samo po obu stronach.
     * Do LIKE idą tylko kawałki ASCII: zapisany adres bywa z „ł” albo z „%C5%82”, a klucz
     * porównuje oba po odkodowaniu.
     *
     * @param  list<string>  $needles
     * @param  Closure(string): bool  $same
     * @return list<int>
     */
    private function productIdsByUrl(array $needles, Closure $same): array
    {
        $sources = [
            ['query' => Product::query(), 'url' => 'shop_source_url', 'id' => 'id'],
            ['query' => ProductShopCard::query(), 'url' => 'source_url', 'id' => 'product_id'],
            ['query' => ProductVariant::query()->active(), 'url' => 'source_url', 'id' => 'product_id'],
        ];

        $ids = [];
        foreach ($sources as $source) {
            /** @var Builder<Model> $query */
            $query = $source['query'];
            foreach ($needles as $needle) {
                foreach (preg_split('/[^\x21-\x7E]+|%/', $needle) ?: [] as $piece) {
                    if (strlen($piece) >= 3) {
                        $query->whereRaw($source['url']." LIKE ? ESCAPE '!'", ['%'.$this->likeEscape($piece).'%']);
                    }
                }
            }
            $rows = $query->toBase()
                ->orderByRaw('LENGTH('.$source['url'].')')
                ->limit(self::MAX_URL_ROWS)
                ->get([$source['id'].' as product_id', $source['url'].' as url']);
            foreach ($rows as $row) {
                if ($same((string) $row->url)) {
                    $ids[(int) $row->product_id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Karty spod adresu, najpierw te, których nazwa podaje wariant z kotwicy linku. PrestaShop
     * zapisuje wybrany kolor i rozmiar w „#/3-rozmiar-l/34-kolor-26”, a karty MAVIBO to kolory
     * („GEFFER 620 61920, kolor 26”); bez tego limit brał kolory po kolei i wskazanego mogło
     * zabraknąć (#62: pod jednym wyrobem stoi pięć i więcej kart).
     *
     * @param  list<int>  $ids
     * @return list<Product>
     */
    private function products(array $ids, string $url): array
    {
        sort($ids);
        $products = Product::query()
            ->whereIn('id', array_slice($ids, 0, self::MAX_CARDS_PER_URL))
            ->orderBy('id')
            ->get()
            ->all();

        $options = InquiryLinks::fragmentOptions($url);
        if ($options !== []) {
            // usort jest stabilny: poza wskazanym wariantem kolejność po id zostaje
            usort($products, static fn (Product $a, Product $b): int => (InquiryLinks::variantNamedIn((string) $b->name, $options) !== null)
                <=> (InquiryLinks::variantNamedIn((string) $a->name, $options) !== null));
        }

        return array_slice($products, 0, self::MAX_PRODUCTS);
    }
}
