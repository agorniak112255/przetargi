<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\ManufacturerSite;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Producent karty z sitemapy: unikalna domena marki albo token ze sluga.
 */
final class CatalogPageManufacturer
{
    /**
     * Alias → rodzina. Host z wieloma rodzinami (honeywell+kcl, jalas+ejendals) zostaje pusty.
     *
     * @var array<string, string>
     */
    private const FAMILY = [
        'ansell' => 'ansell',
        'kleenguard' => 'ansell',
        'kimberly' => 'ansell',
        'ringers' => 'ansell',
        'activarmr' => 'ansell',
        'alphatec' => 'ansell',
        'atg' => 'atg',
        'maxiflex' => 'atg',
        'maxicut' => 'atg',
        'maxidry' => 'atg',
        'urgent' => 'urgent',
        'pilne' => 'urgent',
        'pros' => 'pros',
        'aj-group' => 'pros',
        'ajgroup' => 'pros',
        'ejendals' => 'ejendals',
        'tegera' => 'ejendals',
        'jalas' => 'jalas',
        'delta' => 'delta',
        'delta-plus' => 'delta',
        'deltaplus' => 'delta',
        'gvs' => 'gvs',
        'rpb' => 'gvs',
        'sir' => 'sir',
        'sir-safety' => 'sir',
        'sirsafety' => 'sir',
        'sordin' => 'sordin',
        'hellberg' => 'sordin',
        'arelax' => 'arelax',
        'artra' => 'arelax',
        'eider' => 'cerva',
        'cerva' => 'cerva',
        'msa' => 'msa',
        'msa-safety' => 'msa',
        'msa-auer' => 'msa',
        'ardon' => 'ardon',
        'ardon-safety' => 'ardon',
        'marel' => 'marel',
        'marel-plus' => 'marel',
        'worklink' => 'worklink',
        'supertech' => 'worklink',
        'elten' => 'elten',
        'jori' => 'elten',
        'stoko' => 'stoko',
        'deb' => 'stoko',
        'deb-stoko' => 'stoko',
        'coba' => 'coba',
        'coba-europe' => 'coba',
        'safeline' => 'safeline',
        'i-a-arbeitsschutz' => 'safeline',
        'honeywell' => 'honeywell',
        'perfect-fit' => 'honeywell',
        'kcl' => 'kcl',
        'canis' => 'canis',
        'cxs' => 'cxs',
    ];

    /**
     * Token ze sluga, którego nie ma jako klucz w manufacturer_domains.
     *
     * @var array<string, string>
     */
    private const TOKEN_ALIASES = [
        'tegera' => 'ejendals',
        'alphatec' => 'ansell',
        'kleenguard' => 'ansell',
        'activarmr' => 'ansell',
        'deltaplus' => 'delta-plus',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $hostKeys = null;

    /** @var array<string, string>|null */
    private ?array $tokenKeys = null;

    public function resolve(string $host, string $url): ?string
    {
        return $this->fromHost($host) ?? $this->fromUrl($url);
    }

    public function conflictsWithProduct(?string $pageManufacturer, Product $product): bool
    {
        $pageKey = $this->knownKey((string) $pageManufacturer);
        $productKey = $this->knownKey((string) $product->manufacturer);

        return $pageKey !== null
            && $productKey !== null
            && $this->familyOf($pageKey) !== $this->familyOf($productKey);
    }

    public function matchesProduct(?string $pageManufacturer, Product $product): bool
    {
        $pageKey = $this->knownKey((string) $pageManufacturer);
        $productKey = $this->knownKey((string) $product->manufacturer);

        return $pageKey !== null
            && $productKey !== null
            && $this->familyOf($pageKey) === $this->familyOf($productKey);
    }

    public function knownKey(string $raw): ?string
    {
        $norm = $this->normalizeKey($raw);
        if ($norm === '') {
            return null;
        }
        $map = $this->tokenBrandMap();
        if (isset($map[$norm])) {
            return $map[$norm];
        }
        $compact = str_replace('-', '', $norm);
        if ($compact !== $norm && isset($map[$compact])) {
            return $map[$compact];
        }
        if (isset(self::FAMILY[$norm])) {
            return $norm;
        }
        if (isset(self::FAMILY[$compact])) {
            return $compact;
        }

        return null;
    }

    public function familyOf(string $key): string
    {
        $norm = $this->normalizeKey($key);

        return self::FAMILY[$norm] ?? $norm;
    }

    private function fromHost(string $host): ?string
    {
        return $this->collapseToSingle($this->keysForHost($host));
    }

    private function fromUrl(string $url): ?string
    {
        $tokens = $this->urlTokens($url);
        $map = $this->tokenBrandMap();
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (isset($map[$token])) {
                $found[$map[$token]] = $map[$token];
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }
            foreach ([$token.'-'.$next, $token.$next] as $joined) {
                if (isset($map[$joined])) {
                    $found[$map[$joined]] = $map[$joined];
                }
            }
        }

        return $this->collapseToSingle(array_values($found));
    }

    /**
     * @return list<string>
     */
    private function keysForHost(string $host): array
    {
        $host = ManufacturerSite::normalizeHost($host);
        $map = $this->hostBrandMap();
        if (isset($map[$host])) {
            return $map[$host];
        }
        $parts = explode('.', $host);
        while (count($parts) > 2) {
            array_shift($parts);
            $parent = implode('.', $parts);
            if (isset($map[$parent])) {
                return $map[$parent];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $keys
     */
    private function collapseToSingle(array $keys): ?string
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
        if ($keys === []) {
            return null;
        }
        $families = [];
        foreach ($keys as $key) {
            $families[$this->familyOf($key)] = true;
        }
        if (count($families) !== 1) {
            return null;
        }
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $keys[0];
    }

    /**
     * @return array<string, list<string>>
     */
    private function hostBrandMap(): array
    {
        if ($this->hostKeys !== null) {
            return $this->hostKeys;
        }
        $map = [];
        foreach ((array) config('enrichment.manufacturer_domains', []) as $key => $hosts) {
            $key = $this->normalizeKey((string) $key);
            if ($key === '' || ! is_array($hosts)) {
                continue;
            }
            foreach ($hosts as $host) {
                if (! is_string($host)) {
                    continue;
                }
                $clean = ManufacturerSite::normalizeHost($host);
                if ($clean === '') {
                    continue;
                }
                $map[$clean][$key] = $key;
            }
        }
        if (Schema::hasTable('manufacturer_sites')) {
            foreach (ManufacturerSite::query()->get(['brand_key', 'host']) as $row) {
                $key = $this->normalizeKey((string) $row->brand_key);
                $clean = ManufacturerSite::normalizeHost((string) $row->host);
                if ($key === '' || $clean === '') {
                    continue;
                }
                $map[$clean][$key] = $key;
            }
        }
        foreach ($map as $host => $keys) {
            $map[$host] = array_values($keys);
        }
        $this->hostKeys = $map;

        return $this->hostKeys;
    }

    /**
     * @return array<string, string>
     */
    private function tokenBrandMap(): array
    {
        if ($this->tokenKeys !== null) {
            return $this->tokenKeys;
        }
        $map = [];
        foreach (array_keys((array) config('enrichment.manufacturer_domains', [])) as $key) {
            $this->registerToken($map, (string) $key, (string) $key);
        }
        if (Schema::hasTable('manufacturer_sites')) {
            foreach (ManufacturerSite::query()->pluck('brand_key') as $key) {
                $this->registerToken($map, (string) $key, (string) $key);
            }
        }
        foreach (self::TOKEN_ALIASES as $token => $key) {
            $this->registerToken($map, $token, $key);
        }
        $this->tokenKeys = $map;

        return $this->tokenKeys;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function registerToken(array &$map, string $token, string $key): void
    {
        $token = $this->normalizeKey($token);
        $key = $this->normalizeKey($key);
        if ($token === '' || $key === '') {
            return;
        }
        $map[$token] = $key;
        $compact = str_replace('-', '', $token);
        if ($compact !== $token && mb_strlen($compact) >= 4) {
            $map[$compact] = $key;
        }
    }

    /**
     * @return list<string>
     */
    private function urlTokens(string $url): array
    {
        $path = mb_strtolower(Str::ascii(urldecode(
            (string) (parse_url($url, PHP_URL_PATH) ?? '').' '.(string) (parse_url($url, PHP_URL_QUERY) ?? '')
        )));
        $parts = preg_split('/[^a-z0-9]+/u', $path) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out[] = $part;
            if (preg_match('/^[a-z]+$/u', $part) === 1 || preg_match('/^[0-9]+$/u', $part) === 1) {
                continue;
            }
            foreach (preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/u', $part) ?: [] as $piece) {
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return $out;
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;

        return trim($value, '-');
    }
}
