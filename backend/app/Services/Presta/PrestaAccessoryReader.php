<?php

declare(strict_types=1);

namespace App\Services\Presta;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PrestaAccessoryReader
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
    ) {}

    /**
     * @param  array{host?: string, port?: int, database?: string, username?: string, password?: string, prefix?: string, id_lang?: int}  $override
     */
    public function useConnection(array $override): void
    {
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (array_key_exists($key, $override)) {
                Config::set('database.connections.prestashop.'.$key, $override[$key]);
            }
        }
        DB::purge('prestashop');
        if (isset($override['prefix']) && is_string($override['prefix']) && $override['prefix'] !== '') {
            Config::set('prestashop.prefix', $override['prefix']);
        }
        if (isset($override['id_lang'])) {
            Config::set('prestashop.id_lang', (int) $override['id_lang']);
        }
    }

    /**
     * @return list<array{
     *     parent_id: int,
     *     child_id: int,
     *     parent_sku: string,
     *     child_sku: string,
     *     parent_ean: string,
     *     child_ean: string,
     *     parent_name: string,
     *     child_name: string,
     *     parent_manufacturer: string,
     *     child_manufacturer: string
     * }>
     */
    public function links(?int $parentPrestaId = null): array
    {
        $this->connect();
        $prefix = $this->prefix();
        if (! Schema::connection('prestashop')->hasTable($prefix.'accessory')) {
            throw new RuntimeException('Brak tabeli akcesoriów w Preście.');
        }
        $lang = $this->idLang();
        $hasEan = Schema::connection('prestashop')->hasColumn($prefix.'product', 'ean13');
        $eanParent = $hasEan ? 'pp.ean13' : "''";
        $eanChild = $hasEan ? 'pc.ean13' : "''";
        $sql = 'SELECT a.id_product_1 AS parent_id, a.id_product_2 AS child_id,'
            .' pp.reference AS parent_sku, pc.reference AS child_sku,'
            .' '.$eanParent.' AS parent_ean, '.$eanChild.' AS child_ean,'
            .' COALESCE(plp.name, \'\') AS parent_name, COALESCE(plc.name, \'\') AS child_name,'
            .' COALESCE(mp.name, \'\') AS parent_manufacturer, COALESCE(mc.name, \'\') AS child_manufacturer'
            .' FROM '.$prefix.'accessory a'
            .' INNER JOIN '.$prefix.'product pp ON pp.id_product = a.id_product_1'
            .' INNER JOIN '.$prefix.'product pc ON pc.id_product = a.id_product_2'
            .' LEFT JOIN '.$prefix.'product_lang plp ON plp.id_product = a.id_product_1 AND plp.id_lang = ?'
            .' LEFT JOIN '.$prefix.'product_lang plc ON plc.id_product = a.id_product_2 AND plc.id_lang = ?'
            .' LEFT JOIN '.$prefix.'manufacturer mp ON mp.id_manufacturer = pp.id_manufacturer'
            .' LEFT JOIN '.$prefix.'manufacturer mc ON mc.id_manufacturer = pc.id_manufacturer';
        $bindings = [$lang, $lang];
        if ($parentPrestaId !== null && $parentPrestaId > 0) {
            $sql .= ' WHERE a.id_product_1 = ?';
            $bindings[] = $parentPrestaId;
        }

        try {
            $rows = DB::connection('prestashop')->select($sql, $bindings);
        } catch (Throwable $e) {
            throw new RuntimeException('Nie udało się odczytać akcesoriów z Presty: '.$e->getMessage(), 0, $e);
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'parent_id' => (int) $row->parent_id,
                'child_id' => (int) $row->child_id,
                'parent_sku' => (string) ($row->parent_sku ?? ''),
                'child_sku' => (string) ($row->child_sku ?? ''),
                'parent_ean' => (string) ($row->parent_ean ?? ''),
                'child_ean' => (string) ($row->child_ean ?? ''),
                'parent_name' => (string) ($row->parent_name ?? ''),
                'child_name' => (string) ($row->child_name ?? ''),
                'parent_manufacturer' => (string) ($row->parent_manufacturer ?? ''),
                'child_manufacturer' => (string) ($row->child_manufacturer ?? ''),
            ];
        }

        return $out;
    }

    private function connect(): void
    {
        $cfg = $this->settings->resolve();
        $host = (string) Config::get('database.connections.prestashop.host');
        if ($host === '') {
            Config::set('database.connections.prestashop.host', $cfg['host']);
            Config::set('database.connections.prestashop.port', $cfg['port']);
            Config::set('database.connections.prestashop.database', $cfg['database']);
            Config::set('database.connections.prestashop.username', $cfg['username']);
            Config::set('database.connections.prestashop.password', $cfg['password']);
            DB::purge('prestashop');
        }
    }

    private function prefix(): string
    {
        $fromConfig = (string) Config::get('prestashop.prefix', '');
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return $this->settings->resolve()['prefix'];
    }

    private function idLang(): int
    {
        $fromConfig = (int) Config::get('prestashop.id_lang', 0);
        if ($fromConfig > 0) {
            return $fromConfig;
        }

        return $this->settings->resolve()['id_lang'];
    }
}
