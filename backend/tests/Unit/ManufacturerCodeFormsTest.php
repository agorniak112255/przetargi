<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerCodeForms;
use App\Services\Enrichment\ManufacturerProfile;
use App\Services\Enrichment\ManufacturerProfiles;
use Tests\TestCase;

/**
 * Inne zapisy kodu do drugiej próby na hostach producenta (etap 3, §1.1) — karty AJ GROUP z audytu 08.10.2026.
 */
final class ManufacturerCodeFormsTest extends TestCase
{
    public function test_aj_group_price_list_codes_have_manufacturer_page_forms(): void
    {
        $this->assertSame([['code' => 'SBA01', 'rule' => 'letter_suffix']], $this->forms('SBA01B', 'AJ GROUP'));
        $this->assertSame([['code' => 'WRA02', 'rule' => 'letter_suffix']], $this->forms('WRA02B', 'AJ GROUP'));
        $this->assertSame([['code' => 'SB01', 'rule' => 'dash_suffix']], $this->forms('SB01-J', 'AJ GROUP'));
        $this->assertSame([['code' => '071', 'rule' => 'trailing_words']], $this->forms('071 STRAŻ', 'AJ GROUP'));
        $this->assertSame([['code' => '108', 'rule' => 'dash_suffix']], $this->forms('108 - 130/120', 'AJ GROUP'));
        $this->assertSame(
            [['code' => 'SB04 AIR', 'rule' => 'trailing_words'], ['code' => 'SB04', 'rule' => 'trailing_words']],
            $this->forms('SB04 AIR CARP', 'AJ GROUP')
        );
    }

    public function test_short_or_letter_only_codes_have_no_forms(): void
    {
        // CP: klucz od 3 znaków z cyfrą — handlowiec poda adres
        $this->assertSame([], $this->forms('CP', 'AJ GROUP'));
        $this->assertSame([], $this->forms('103', 'AJ GROUP'));
        $this->assertSame([], $this->forms('', 'AJ GROUP'));
        // „08C” → „08” ma 2 znaki
        $this->assertSame([], $this->forms('08C', 'AJ GROUP'));
    }

    public function test_brands_without_alt_forms_in_profile_get_nothing(): void
    {
        $this->assertSame([], $this->forms('CD010610C', 'Coba'));
        $this->assertSame([], $this->forms('FF0100-5', 'Coba'));
        $this->assertSame([], $this->forms('ULTRANITRIL 492 B', 'MAPA'));
        $this->assertSame([], $this->forms('S56212-50', 'SECURA'));
        $this->assertSame([], (new ManufacturerCodeForms)->alternatives(new Product(['sku' => 'SBA01B', 'manufacturer' => 'AJ GROUP']), null));
    }

    public function test_leading_zeros_rule(): void
    {
        $profile = app(ManufacturerProfiles::class)->for(new Product(['sku' => '00123', 'manufacturer' => 'AJ GROUP']));
        $this->assertNotNull($profile);
        $withZeros = new ManufacturerProfile(
            brandKey: $profile->brandKey, hosts: $profile->hosts, onlyManufacturer: true, catalogs: [], identityIn: $profile->identityIn,
            codeNormalize: 'upper_alnum', minLength: 4, modelRegex: null, modelAliasIsKey: false, resolver: null,
            altForms: ['leading_zeros'],
        );

        $this->assertSame([['code' => '123', 'rule' => 'leading_zeros']], (new ManufacturerCodeForms)->alternatives(new Product(['sku' => '00123']), $withZeros));
        $this->assertSame([], (new ManufacturerCodeForms)->alternatives(new Product(['sku' => '071']), $withZeros), '71 ma 2 znaki');
    }

    /**
     * @return list<array{code: string, rule: string}>
     */
    private function forms(string $sku, string $manufacturer): array
    {
        $product = new Product(['sku' => $sku, 'name' => 'Wyrób', 'manufacturer' => $manufacturer]);

        return (new ManufacturerCodeForms)->alternatives($product, app(ManufacturerProfiles::class)->for($product));
    }
}
