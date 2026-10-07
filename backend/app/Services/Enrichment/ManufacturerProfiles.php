<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Profil producenta karty w locie: config/manufacturer_profiles.php (wpis marki na profilu domyślnym) scalony
 * z istniejącym configiem wzbogacania — hosty z manufacturer_domains (ManufacturerDomainResolver::configDomainsFor),
 * manufacturer_only_sources i katalogi PDF z manufacturer_catalogs (ManufacturerCatalogPdf::catalogUrlsFor). Fizyczne scalenie configów — etap 5.
 */
final class ManufacturerProfiles
{
    public function __construct(
        private readonly ManufacturerDomainResolver $domains,
        private readonly ManufacturerCatalogPdf $catalogs,
    ) {}

    /** Null, gdy karta nie ma producenta — wtedy reguły domyślne bez hostów. */
    public function for(Product $p): ?ManufacturerProfile
    {
        $brandKey = $this->domains->brandKey((string) $p->manufacturer);
        if ($brandKey === '') {
            return null;
        }

        $default = (array) config('manufacturer_profiles.default', []);
        $entry = [];
        $profileKey = null;
        foreach ((array) config('manufacturer_profiles.profiles', []) as $key => $profile) {
            if (is_array($profile) && in_array($brandKey, (array) ($profile['brand_keys'] ?? []), true)) {
                $entry = $profile;
                $profileKey = (string) $key;
                break;
            }
        }
        $code = array_replace((array) ($default['code'] ?? []), (array) ($entry['code'] ?? []));
        $identityIn = array_values(array_filter(
            (array) ($entry['identity_in'] ?? $default['identity_in'] ?? ['url', 'title', 'markup']),
            'is_string'
        ));
        $resolver = $entry['resolver'] ?? $default['resolver'] ?? null;
        $modelRegex = $code['model_regex'] ?? null;

        return new ManufacturerProfile(
            brandKey: $brandKey,
            hosts: $this->domains->configDomainsFor($p),
            onlyManufacturer: in_array($brandKey, (array) config('enrichment.manufacturer_only_sources', []), true),
            catalogs: $this->catalogs->catalogUrlsFor($p),
            identityIn: $identityIn,
            codeNormalize: (string) ($code['normalize'] ?? 'upper_alnum'),
            minLength: max(1, (int) ($code['min_length'] ?? 4)),
            modelRegex: is_string($modelRegex) && $modelRegex !== '' ? $modelRegex : null,
            modelAliasIsKey: (bool) ($entry['model_alias_is_key'] ?? $default['model_alias_is_key'] ?? false),
            resolver: is_string($resolver) && $resolver !== '' ? $resolver : null,
            profileKey: $profileKey,
            dashSuffixPad: max(0, (int) ($code['dash_suffix_pad'] ?? 0)),
            variantSuffixes: array_values(array_filter((array) ($code['variant_suffixes'] ?? []), static fn (mixed $s): bool => is_string($s) && $s !== '')),
            combinationSuffix: is_string($code['combination_suffix'] ?? null) && $code['combination_suffix'] !== '' ? $code['combination_suffix'] : null,
            labelledShortCodes: (bool) ($entry['labelled_short_codes'] ?? $default['labelled_short_codes'] ?? false),
        );
    }
}
