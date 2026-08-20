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

        $sku = trim((string) $product->sku);
        $ean = preg_replace('/\D+/', '', (string) $product->ean) ?? '';
        $brand = trim((string) $product->manufacturer);
        $name = trim((string) $product->name);

        $db = DB::connection('prestashop');
        try {
            $db->statement('SET SESSION group_concat_max_len = 32768');
        } catch (Throwable) {
        }

        $hasEan = Schema::connection('prestashop')->hasColumn($prefix.'product', 'ean13');

        $sql = $this->productSelectSql($prefix, $hasEan)
            .' WHERE p.active = 1 AND (';

        $bindings = [$lang, $lang];
        $ors = [];
        if ($hasEan && $ean !== '' && mb_strlen($ean) >= 8) {
            $ors[] = 'p.ean13 = ?';
            $bindings[] = $ean;
        }
        if ($sku !== '') {
            $ors[] = 'p.reference = ?';
            $bindings[] = $sku;
            $ors[] = 'p.reference LIKE ?';
            $bindings[] = $sku.'%';
        }
        if ($brand !== '') {
            $ors[] = 'm.name LIKE ?';
            $bindings[] = '%'.$brand.'%';
        }
        if ($name !== '' && mb_strlen($name) >= 4) {
            $ors[] = 'pl.name LIKE ?';
            $bindings[] = '%'.mb_substr($name, 0, 40).'%';
        }
        if ($ors === []) {
            return [];
        }

        $sql .= implode(' OR ', $ors).') ORDER BY p.id_product ASC LIMIT '.$limit;
        $rows = $db->select($sql, $bindings);

        return array_map(fn (object $row): array => $this->mapRow($row), $rows);
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
                $images = DB::connection('prestashop')
                    ->table($prefix.'image')
                    ->where('id_product', $prestaId)
                    ->orderByDesc('cover')
                    ->orderBy('position')
                    ->limit(6)
                    ->get(['id_image']);
                foreach ($images as $image) {
                    $id = (int) $image->id_image;
                    if ($id <= 0) {
                        continue;
                    }
                    $urls[] = $shop.'/'.$id.'-large_default/'.$linkRewrite.'.jpg';
                    $urls[] = $shop.'/'.$prestaId.'-'.$id.'-'.$linkRewrite.'.jpg';
                }
            }
        } catch (Throwable) {
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
