<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\Product;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PrestaShopCatalogClient implements PrestaCatalogGateway
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
        private readonly PrestaSearchQuery $query,
    ) {}

    public function configured(): bool
    {
        $cfg = $this->settings->resolve();

        return $cfg['enabled']
            && $cfg['host'] !== ''
            && $cfg['database'] !== ''
            && $cfg['username'] !== '';
    }

    public function ping(): array
    {
        if (! $this->configured()) {
            return [
                'ok' => false,
                'message' => 'Połączenie ze sklepem jest wyłączone albo nieuzupełnione.',
                'active_products' => 0,
                'has_image_table' => false,
            ];
        }

        try {
            $this->connect();
            $prefix = $this->prefix();
            $count = (int) DB::connection('prestashop')->table($prefix.'product')
                ->where('active', 1)
                ->count();
            $hasImages = Schema::connection('prestashop')->hasTable($prefix.'image');

            return [
                'ok' => true,
                'message' => 'Połączenie OK. Aktywnych produktów: '.$count
                    .($hasImages ? '. Tabela zdjęć dostępna.' : '. Brak SELECT na ps_image — zdjęcia z karty HTML.'),
                'active_products' => $count,
                'has_image_table' => $hasImages,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'active_products' => 0,
                'has_image_table' => false,
            ];
        }
    }

    public function findCandidates(Product $product, int $limit = 20): array
    {
        $this->assertReady();
        $this->connect();
        $prefix = $this->prefix();
        $lang = $this->idLang();
        $limit = max(1, min(40, $limit));

        $db = DB::connection('prestashop');
        try {
            $db->statement('SET SESSION group_concat_max_len = 32768');
        } catch (Throwable) {
        }

        $hasEan = Schema::connection('prestashop')->hasColumn($prefix.'product', 'ean13');
        $codeHits = $this->selectByCode($product, $prefix, $hasEan, $lang, $limit);
        if ($codeHits !== []) {
            return $codeHits;
        }

        return $this->selectByBrandAndName($product, $prefix, $hasEan, $lang, $limit);
    }

    public function findCard(int $prestaId): ?array
    {
        $this->assertReady();
        $this->connect();
        $prefix = $this->prefix();
        $lang = $this->idLang();
        $hasEan = Schema::connection('prestashop')->hasColumn($prefix.'product', 'ean13');

        $db = DB::connection('prestashop');
        try {
            $db->statement('SET SESSION group_concat_max_len = 32768');
        } catch (Throwable) {
        }

        $row = $db->selectOne(
            $this->productSelectSql($prefix, $hasEan)
            .' WHERE p.id_product = ? AND p.active = 1',
            [$lang, $lang, $prestaId]
        );

        return $row === null ? null : $this->mapRow($row);
    }

    public function imageUrls(int $prestaId, string $linkRewrite): array
    {
        $this->assertReady();
        $this->connect();
        $prefix = $this->prefix();
        $shop = rtrim($this->settings->resolve()['shop_url'], '/');
        $urls = [];

        try {
            if (Schema::connection('prestashop')->hasTable($prefix.'image')) {
                $query = DB::connection('prestashop')
                    ->table($prefix.'image')
                    ->where('id_product', $prestaId);
                if (Schema::connection('prestashop')->hasColumn($prefix.'image', 'cover')) {
                    $query->orderByDesc('cover');
                }
                if (Schema::connection('prestashop')->hasColumn($prefix.'image', 'position')) {
                    $query->orderBy('position');
                }
                $images = $query->limit(6)->get(['id_image']);
                foreach ($images as $image) {
                    $urls = array_merge(
                        $urls,
                        PrestaImageUrlBuilder::urls($shop, (int) $image->id_image, $linkRewrite)
                    );
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return array_values(array_unique($urls));
    }

    private function connect(): void
    {
        $cfg = $this->settings->resolve();
        Config::set('database.connections.prestashop.host', $cfg['host']);
        Config::set('database.connections.prestashop.port', $cfg['port']);
        Config::set('database.connections.prestashop.database', $cfg['database']);
        Config::set('database.connections.prestashop.username', $cfg['username']);
        Config::set('database.connections.prestashop.password', $cfg['password']);
        DB::purge('prestashop');
    }

    private function assertReady(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Sklep Presta nie jest skonfigurowany. Ustawienia → Administracja → Sklep Presta.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectByCode(Product $product, string $prefix, bool $hasEan, int $lang, int $limit): array
    {
        $ors = [];
        $bindings = [];
        $ean = $this->query->ean($product);
        if ($hasEan && $ean !== '') {
            $ors[] = 'p.ean13 = ?';
            $bindings[] = $ean;
        }
        $sku = $this->query->sku($product);
        if ($sku !== '') {
            $ors[] = 'p.reference = ?';
            $bindings[] = $sku;
            $ors[] = 'p.reference LIKE ?';
            $bindings[] = $this->query->likePrefix($sku);
        }
        if ($ors === []) {
            return [];
        }

        return $this->selectWhere($prefix, $hasEan, $lang, implode(' OR ', $ors), $bindings, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectByBrandAndName(Product $product, string $prefix, bool $hasEan, int $lang, int $limit): array
    {
        $brand = $this->query->brand($product);
        $tokens = $this->query->nameTokens($product);
        if ($brand === '' || $tokens === []) {
            return [];
        }

        $nameOrs = [];
        $bindings = [$this->query->likeContains($brand)];
        foreach ($tokens as $token) {
            $nameOrs[] = 'pl.name LIKE ?';
            $bindings[] = $this->query->likeContains($token);
        }

        return $this->selectWhere(
            $prefix,
            $hasEan,
            $lang,
            'm.name LIKE ? AND ('.implode(' OR ', $nameOrs).')',
            $bindings,
            $limit
        );
    }

    /**
     * @param  list<mixed>  $whereBindings
     * @return list<array<string, mixed>>
     */
    private function selectWhere(
        string $prefix,
        bool $hasEan,
        int $lang,
        string $whereSql,
        array $whereBindings,
        int $limit,
    ): array {
        $sql = $this->productSelectSql($prefix, $hasEan)
            .' WHERE p.active = 1 AND ('.$whereSql.')'
            .' ORDER BY p.id_product ASC LIMIT '.$limit;
        $rows = DB::connection('prestashop')->select(
            $sql,
            array_merge([$lang, $lang], $whereBindings)
        );

        return array_map(fn (object $row): array => $this->mapRow($row), $rows);
    }

    private function productSelectSql(string $prefix, bool $hasEan): string
    {
        $eanSelect = $hasEan ? 'p.ean13' : "''";

        return 'SELECT p.id_product, p.reference, '.$eanSelect.' AS ean13,'
            .' pl.name, pl.link_rewrite, pl.description_short, pl.description,'
            .' m.name AS manufacturer, feat.features'
            .' FROM '.$prefix.'product p'
            .' INNER JOIN '.$prefix.'product_lang pl'
            .'   ON pl.id_product = p.id_product AND pl.id_lang = ?'
            .' LEFT JOIN '.$prefix.'manufacturer m ON m.id_manufacturer = p.id_manufacturer'
            .' LEFT JOIN ('
            .'   SELECT fp.id_product, GROUP_CONCAT(DISTINCT fvl.value SEPARATOR \'; \') AS features'
            .'   FROM '.$prefix.'feature_product fp'
            .'   LEFT JOIN '.$prefix.'feature_value_lang fvl'
            .'     ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ?'
            .'   GROUP BY fp.id_product'
            .' ) feat ON feat.id_product = p.id_product';
    }

    private function prefix(): string
    {
        return $this->settings->resolve()['prefix'];
    }

    private function idLang(): int
    {
        return $this->settings->resolve()['id_lang'];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $id = (int) $row->id_product;
        $rewrite = (string) ($row->link_rewrite ?? '');
        $shop = rtrim($this->settings->resolve()['shop_url'], '/');

        return [
            'id_product' => $id,
            'reference' => (string) ($row->reference ?? ''),
            'ean13' => (string) ($row->ean13 ?? ''),
            'name' => (string) ($row->name ?? ''),
            'link_rewrite' => $rewrite,
            'description_short' => (string) ($row->description_short ?? ''),
            'description' => (string) ($row->description ?? ''),
            'manufacturer' => (string) ($row->manufacturer ?? ''),
            'features' => (string) ($row->features ?? ''),
            'url' => $shop.'/'.$id.'-'.$rewrite.'.html',
        ];
    }
}
