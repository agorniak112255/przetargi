<?php

declare(strict_types=1);

namespace App\Services\Presta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class PrestaShopExportClient implements PrestaExportGateway
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
    ) {}

    public function writeConfigured(): bool
    {
        return $this->writeError() === '';
    }

    public function writeError(): string
    {
        $cfg = $this->settings->resolve();
        if (! $cfg['enabled'] || $cfg['shop_url'] === '') {
            return 'Sklep Presta nie jest włączony albo brak URL.';
        }
        if ($cfg['webservice_key'] === '') {
            return 'Brak klucza Webservice. Ustawienia → Sklep Presta → klucz API.';
        }

        return '';
    }

    public function findExisting(string $sku, string $ean): ?array
    {
        $sku = trim($sku);
        $ean = preg_replace('/\D+/', '', $ean) ?? '';
        if ($sku === '' && $ean === '') {
            return null;
        }

        try {
            $this->connectDb();
            $prefix = $this->prefix();
            $lang = $this->idLang();
            $ors = [];
            $bindings = [];
            if ($sku !== '') {
                $ors[] = 'p.reference = ?';
                $bindings[] = $sku;
                if (Schema::connection('prestashop')->hasTable($prefix.'product_attribute')) {
                    $ors[] = 'pa.reference = ?';
                    $bindings[] = $sku;
                    $ors[] = 'pa.reference LIKE ?';
                    $bindings[] = $sku.'-%';
                }
            }
            if ($ean !== '' && Schema::connection('prestashop')->hasColumn($prefix.'product', 'ean13')) {
                $ors[] = 'p.ean13 = ?';
                $bindings[] = $ean;
            }
            if ($ors === []) {
                return null;
            }
            $joinAttr = Schema::connection('prestashop')->hasTable($prefix.'product_attribute')
                ? ' LEFT JOIN '.$prefix.'product_attribute pa ON pa.id_product = p.id_product'
                : '';
            $row = DB::connection('prestashop')->selectOne(
                'SELECT p.id_product, p.reference, pl.link_rewrite'
                .' FROM '.$prefix.'product p'
                .' LEFT JOIN '.$prefix.'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = ?'
                .$joinAttr
                .' WHERE '.implode(' OR ', $ors)
                .' ORDER BY p.id_product ASC LIMIT 1',
                array_merge([$lang], $bindings)
            );
            if ($row === null) {
                return null;
            }
            $id = (int) $row->id_product;
            $rewrite = (string) ($row->link_rewrite ?? '');

            return [
                'id_product' => $id,
                'reference' => (string) ($row->reference ?? ''),
                'url' => $this->productUrl($id, $rewrite),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function resolveManufacturerId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        try {
            $this->connectDb();
            $prefix = $this->prefix();
            $needle = mb_strtolower($name);
            $row = DB::connection('prestashop')->table($prefix.'manufacturer')
                ->whereRaw('LOWER(name) = ?', [$needle])
                ->first(['id_manufacturer']);
            if ($row === null && mb_strlen($needle) >= 3) {
                $row = DB::connection('prestashop')->table($prefix.'manufacturer')
                    ->whereRaw('LOWER(name) LIKE ?', [$needle.'%'])
                    ->orderBy('id_manufacturer')
                    ->first(['id_manufacturer']);
            }
            if ($row === null && mb_strlen($needle) >= 4) {
                $row = DB::connection('prestashop')->table($prefix.'manufacturer')
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])
                    ->orderBy('id_manufacturer')
                    ->first(['id_manufacturer']);
            }
            if ($row !== null) {
                return (int) $row->id_manufacturer;
            }
        } catch (Throwable) {
        }

        return 0;
    }

    public function resolveCategoryId(?string $name): int
    {
        $fallback = max(1, (int) $this->settings->resolve()['id_category_default']);
        $name = trim((string) $name);
        if ($name === '') {
            return $fallback;
        }
        try {
            $this->connectDb();
            $prefix = $this->prefix();
            $lang = $this->idLang();
            $row = DB::connection('prestashop')->selectOne(
                'SELECT c.id_category FROM '.$prefix.'category c'
                .' INNER JOIN '.$prefix.'category_lang cl'
                .'   ON cl.id_category = c.id_category AND cl.id_lang = ?'
                .' WHERE c.active = 1 AND cl.name LIKE ?'
                .' ORDER BY c.level_depth DESC, c.id_category ASC LIMIT 1',
                [$lang, '%'.$name.'%']
            );
            if ($row !== null) {
                return (int) $row->id_category;
            }
        } catch (Throwable) {
        }

        return $fallback;
    }

    public function resolveSizeAttributes(array $sizes, ?string $hint): array
    {
        $mapped = [];
        $missing = [];
        if ($sizes === []) {
            return ['mapped' => $mapped, 'missing' => $missing];
        }

        try {
            $this->connectDb();
            $prefix = $this->prefix();
            $lang = $this->idLang();
            $rows = DB::connection('prestashop')->select(
                'SELECT a.id_attribute, al.name AS value_name, agl.name AS group_name'
                .' FROM '.$prefix.'attribute a'
                .' INNER JOIN '.$prefix.'attribute_lang al'
                .'   ON al.id_attribute = a.id_attribute AND al.id_lang = ?'
                .' INNER JOIN '.$prefix.'attribute_group_lang agl'
                .'   ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ?',
                [$lang, $lang]
            );
        } catch (Throwable) {
            return ['mapped' => $mapped, 'missing' => $sizes];
        }

        $hintNorm = mb_strtolower(trim((string) $hint));
        $preferGloves = $hintNorm !== '' && str_contains($hintNorm, 'rękaw');
        $preferShoes = $hintNorm !== '' && (str_contains($hintNorm, 'but') || str_contains($hintNorm, 'obuw'));

        foreach ($sizes as $size) {
            $want = $this->normAttr($size);
            $best = null;
            $bestScore = -1;
            foreach ($rows as $row) {
                $value = $this->normAttr((string) $row->value_name);
                if ($value !== $want) {
                    continue;
                }
                $group = mb_strtolower((string) $row->group_name);
                $score = 1;
                if (str_contains($group, 'rozmiar')) {
                    $score += 2;
                }
                if ($preferGloves && str_contains($group, 'rękaw')) {
                    $score += 4;
                }
                if ($preferShoes && (str_contains($group, 'but') || str_contains($group, 'obuw'))) {
                    $score += 4;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = (int) $row->id_attribute;
                }
            }
            if ($best === null) {
                $missing[] = $size;
            } else {
                $mapped[$size] = $best;
            }
        }

        return ['mapped' => $mapped, 'missing' => $missing];
    }

    public function createProduct(array $data): array
    {
        $xml = $this->productXml($data, null);
        $created = $this->postXml('products', $xml);
        $id = (int) ($created->product->id ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Presta nie zwróciła id produktu.');
        }

        return [
            'id_product' => $id,
            'url' => $this->productUrl($id, (string) ($data['link_rewrite'] ?? '')),
        ];
    }

    public function updateProduct(int $prestaId, array $data): array
    {
        $existing = $this->getXml('products/'.$prestaId);
        $product = $existing->product ?? null;
        if (! $product instanceof SimpleXMLElement) {
            throw new RuntimeException('Nie udało się pobrać karty Presta #'.$prestaId.'.');
        }
        $this->setLang($product, 'name', (string) ($data['name'] ?? ''));
        $this->setLang($product, 'description', (string) ($data['description'] ?? ''));
        $this->setLang($product, 'description_short', (string) ($data['description_short'] ?? ''));
        $this->setLang($product, 'available_now', (string) ($data['delivery_label'] ?? 'Na zamówienie'));
        $this->setLang($product, 'available_later', (string) ($data['delivery_label'] ?? 'Na zamówienie'));
        $this->setLang($product, 'delivery_in_stock', (string) ($data['delivery_label'] ?? 'Na zamówienie'));
        $this->setLang($product, 'delivery_out_stock', (string) ($data['delivery_label'] ?? 'Na zamówienie'));
        $product->additional_delivery_times = '2';
        $product->out_of_stock = '1';
        $product->available_for_order = '1';
        $this->putXml('products/'.$prestaId, $this->sanitizeForWrite((string) $existing->asXML()));

        $rewrite = (string) ($data['link_rewrite'] ?? '');

        return [
            'id_product' => $prestaId,
            'url' => $this->productUrl($prestaId, $rewrite),
        ];
    }

    public function ensureCombinations(int $prestaId, array $combinations): void
    {
        if ($combinations === []) {
            return;
        }
        $existing = [];
        try {
            $this->connectDb();
            $prefix = $this->prefix();
            if (Schema::connection('prestashop')->hasTable($prefix.'product_attribute_combination')) {
                $rows = DB::connection('prestashop')->table($prefix.'product_attribute as pa')
                    ->join($prefix.'product_attribute_combination as pac', 'pac.id_product_attribute', '=', 'pa.id_product_attribute')
                    ->where('pa.id_product', $prestaId)
                    ->get(['pac.id_attribute']);
                foreach ($rows as $row) {
                    $existing[(int) $row->id_attribute] = true;
                }
            }
        } catch (Throwable) {
        }

        foreach ($combinations as $row) {
            $attrId = (int) ($row['attribute_id'] ?? 0);
            if ($attrId <= 0 || isset($existing[$attrId])) {
                continue;
            }
            $xml = $this->xmlRoot('combination', [
                'id_product' => (string) $prestaId,
                'reference' => $this->cdata((string) ($row['reference'] ?? '')),
                'minimal_quantity' => '1',
                'associations' => [
                    'product_option_values' => [
                        'product_option_value' => ['id' => (string) $attrId],
                    ],
                ],
            ]);
            $this->postXml('combinations', $xml);
        }
    }

    public function uploadImage(int $prestaId, string $binary, string $filename): void
    {
        if ($binary === '' || $prestaId <= 0) {
            return;
        }
        $cfg = $this->settings->resolve();
        $url = rtrim($cfg['shop_url'], '/').'/api/images/products/'.$prestaId;
        $response = Http::timeout(60)
            ->withBasicAuth($cfg['webservice_key'], '')
            ->withQueryParameters(['ws_key' => $cfg['webservice_key']])
            ->attach('image', $binary, $filename)
            ->post($url);
        if ($response->failed()) {
            throw new RuntimeException('Upload zdjęcia do Presty nie powiódł się (HTTP '.$response->status().').');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function productXml(array $data, ?int $id): string
    {
        $lang = (string) $this->idLang();
        $label = (string) ($data['delivery_label'] ?? 'Na zamówienie');
        $categoryId = (string) max(1, (int) ($data['id_category'] ?? 2));
        $fields = [
            'id_category_default' => $categoryId,
            'id_tax_rules_group' => (string) max(1, (int) ($data['id_tax_rules_group'] ?? 1)),
            'id_shop_default' => '1',
            'reference' => $this->cdata((string) ($data['reference'] ?? '')),
            'ean13' => $this->cdata((string) ($data['ean13'] ?? '')),
            'price' => number_format((float) ($data['price'] ?? 0), 6, '.', ''),
            'active' => '1',
            'state' => '1',
            'available_for_order' => '1',
            'show_price' => '1',
            'minimal_quantity' => '1',
            'out_of_stock' => '1',
            'additional_delivery_times' => '2',
            'visibility' => 'both',
            'name' => $this->lang($lang, (string) ($data['name'] ?? '')),
            'description' => $this->lang($lang, (string) ($data['description'] ?? '')),
            'description_short' => $this->lang($lang, (string) ($data['description_short'] ?? '')),
            'link_rewrite' => $this->lang($lang, (string) ($data['link_rewrite'] ?? 'produkt')),
            'available_now' => $this->lang($lang, $label),
            'available_later' => $this->lang($lang, $label),
            'delivery_in_stock' => $this->lang($lang, $label),
            'delivery_out_stock' => $this->lang($lang, $label),
            'associations' => [
                'categories' => [
                    'category' => ['id' => $categoryId],
                ],
            ],
        ];
        $manufacturerId = (int) ($data['id_manufacturer'] ?? 0);
        if ($manufacturerId > 0) {
            $fields = ['id_manufacturer' => (string) $manufacturerId] + $fields;
        }
        if ($id !== null && $id > 0) {
            $fields = ['id' => (string) $id] + $fields;
        }

        return $this->xmlRoot('product', $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function xmlRoot(string $resource, array $fields): string
    {
        $inner = $this->xmlNode($resource, $fields);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">'
            .$inner
            .'</prestashop>';
    }

    /**
     * @param  array<string, mixed>|string  $value
     */
    private function xmlNode(string $name, mixed $value): string
    {
        if (is_string($value)) {
            return '<'.$name.'>'.$value.'</'.$name.'>';
        }
        if (! is_array($value)) {
            return '<'.$name.'></'.$name.'>';
        }
        $inner = '';
        foreach ($value as $child => $childValue) {
            $inner .= $this->xmlNode((string) $child, $childValue);
        }

        return '<'.$name.'>'.$inner.'</'.$name.'>';
    }

    private function lang(string $id, string $value): string
    {
        return '<language id="'.$id.'">'.$this->cdata($value).'</language>';
    }

    private function cdata(string $value): string
    {
        $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);

        return '<![CDATA['.$safe.']]>';
    }

    private function setLang(SimpleXMLElement $product, string $field, string $value): void
    {
        $langId = (string) $this->idLang();
        if (! isset($product->{$field})) {
            $product->addChild($field);
        }
        $node = $product->{$field};
        $found = false;
        foreach ($node->language ?? [] as $language) {
            $id = (string) ($language['id'] ?? '');
            if ($id === $langId || $id === '') {
                $this->setCdata($language, $value);
                $found = true;
            }
        }
        if (! $found) {
            $child = $node->addChild('language');
            $child['id'] = $langId;
            $this->setCdata($child, $value);
        }
    }

    private function setCdata(SimpleXMLElement $node, string $value): void
    {
        $dom = dom_import_simplexml($node);
        if ($dom->ownerDocument === null) {
            return;
        }
        while ($dom->firstChild !== null) {
            $dom->removeChild($dom->firstChild);
        }
        $dom->appendChild($dom->ownerDocument->createCDATASection($value));
    }

    private function postXml(string $resource, string $xml): SimpleXMLElement
    {
        return $this->parseXml($this->request('POST', $resource, $xml));
    }

    private function putXml(string $resource, string $xml): SimpleXMLElement
    {
        return $this->parseXml($this->request('PUT', $resource, $this->sanitizeForWrite($xml)));
    }

    private function sanitizeForWrite(string $xml): string
    {
        return preg_replace('/\s+xlink:href="[^"]*"/', '', $xml) ?? $xml;
    }

    private function getXml(string $resource): SimpleXMLElement
    {
        return $this->parseXml($this->request('GET', $resource, null));
    }

    private function request(string $method, string $resource, ?string $xml): Response
    {
        $cfg = $this->settings->resolve();
        $url = rtrim($cfg['shop_url'], '/').'/api/'.ltrim($resource, '/');
        $pending = Http::timeout(60)
            ->withBasicAuth($cfg['webservice_key'], '')
            ->withQueryParameters(['ws_key' => $cfg['webservice_key']])
            ->withHeaders(['Io-Format' => 'XML', 'Output-Format' => 'XML']);
        $response = match ($method) {
            'POST' => $pending->withBody((string) $xml, 'application/xml')->post($url),
            'PUT' => $pending->withBody((string) $xml, 'application/xml')->put($url),
            default => $pending->get($url),
        };
        if ($response->failed()) {
            if (str_contains($response->body(), 'is not allowed')) {
                throw new RuntimeException(
                    'Klucz Webservice nie ma uprawnienia do „'.$resource.'”. '
                    .'W Preście: Parametry zaawansowane → Webservice → ten klucz → zaznacz zasób i GET/POST/PUT.'
                );
            }
            $snippet = mb_substr(trim($response->body()), 0, 220);
            throw new RuntimeException(
                'Presta API '.$method.' /'.$resource.' HTTP '.$response->status()
                .($snippet !== '' ? ': '.$snippet : '')
            );
        }

        return $response;
    }

    private function parseXml(Response $response): SimpleXMLElement
    {
        $body = $response->body();
        $xml = @simplexml_load_string($body);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Presta zwróciła niepoprawny XML.');
        }
        if (isset($xml->errors->error->message)) {
            throw new RuntimeException('Presta: '.(string) $xml->errors->error->message);
        }

        return $xml;
    }

    private function connectDb(): void
    {
        $cfg = $this->settings->resolve();
        Config::set('database.connections.prestashop.host', $cfg['host']);
        Config::set('database.connections.prestashop.port', $cfg['port']);
        Config::set('database.connections.prestashop.database', $cfg['database']);
        Config::set('database.connections.prestashop.username', $cfg['username']);
        Config::set('database.connections.prestashop.password', $cfg['password']);
        DB::purge('prestashop');
    }

    private function prefix(): string
    {
        return $this->settings->resolve()['prefix'];
    }

    private function idLang(): int
    {
        return $this->settings->resolve()['id_lang'];
    }

    private function productUrl(int $id, string $rewrite): string
    {
        $shop = rtrim($this->settings->resolve()['shop_url'], '/');
        $slug = $rewrite !== '' ? $rewrite : 'produkt';

        return $shop.'/'.$id.'-'.$slug.'.html';
    }

    private function normAttr(string $value): string
    {
        $t = mb_strtolower(trim(str_replace(',', '.', $value)));
        $t = preg_replace('/\s+/', '', $t) ?? $t;
        if (preg_match('/^\d+\.0$/', $t) === 1) {
            return (string) (int) $t;
        }

        return $t;
    }
}
