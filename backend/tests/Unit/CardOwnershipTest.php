<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\BrandDictionaryEntry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\Catalog\CardOwnership;
use App\Support\CanonicalBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marka kanoniczna (słownik marek, potem pierwsze słowo) i właściciel karty z danych, które już są.
 */
final class CardOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_brand_ignores_case_and_suffixes(): void
    {
        $this->assertSame('anro', CanonicalBrand::key('ANRO'));
        $this->assertTrue(CanonicalBrand::same('ANRO', 'Anro'));
        $this->assertTrue(CanonicalBrand::same('Bolle Safety', 'BOLLE'));
        $this->assertFalse(CanonicalBrand::same('Ardon', 'ATG'));
        $this->assertSame('', CanonicalBrand::key('  '));
        $this->assertSame('', CanonicalBrand::key(null));
        $this->assertFalse(CanonicalBrand::same('', ''));
        $this->assertFalse(CanonicalBrand::same(null, 'Anro'));
    }

    public function test_canonical_brand_translates_dictionary_brand_to_producer(): void
    {
        $this->assertFalse(CanonicalBrand::same('PELTOR', '3M'));

        BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M', 'detect_in_query' => true]);

        $this->assertSame('3m', CanonicalBrand::key('PELTOR'));
        $this->assertTrue(CanonicalBrand::same('PELTOR', '3M'));
        $this->assertTrue(CanonicalBrand::same('peltor', '3m'));
    }

    public function test_linked_producer_account_owns_card_and_distributor_does_not(): void
    {
        $anro = $this->account('anro');
        $p4s = $this->account('p4s');
        $card = $this->card('ANRO');
        $this->link($p4s, $card);
        $ownership = new CardOwnership;

        // dystrybutor sam właścicielem nie jest
        $this->assertSame([], $ownership->ownerSourceKeys($card));
        $this->assertFalse($ownership->isProtected($card));
        // konto producenta jest u siebie także bez powiązania
        $this->assertTrue($ownership->isOwnerAccount($card, $anro));
        $this->assertFalse($ownership->isOwnerAccount($card, $p4s));

        $this->link($anro, $card);

        $this->assertSame(['b2b:'.$anro->id], $ownership->ownerSourceKeys($card));
        $this->assertTrue($ownership->isProtected($card));
    }

    public function test_dictionary_brand_makes_3m_account_owner_of_peltor_card(): void
    {
        $mmm = $this->account('3m');
        $card = $this->card('PELTOR');
        $this->link($mmm, $card);
        $ownership = new CardOwnership;

        $this->assertFalse($ownership->isProtected($card));
        $this->assertFalse($ownership->isOwnerAccount($card, $mmm));

        BrandDictionaryEntry::query()->create(['term' => 'PELTOR', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M', 'detect_in_query' => true]);

        $this->assertSame(['b2b:'.$mmm->id], $ownership->ownerSourceKeys($card));
        $this->assertTrue($ownership->isOwnerAccount($card, $mmm));
    }

    public function test_producer_file_owns_card_also_when_suggested_and_other_file_does_not(): void
    {
        $card = $this->card('ATG');
        $ownership = new CardOwnership;
        $other = PriceList::query()->create(['manufacturer' => 'Ardon', 'version' => 'v1', 'product_ids' => [$card->id]]);
        $slot = ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $other->id,
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        $this->assertSame([], $ownership->ownerSourceKeys($card));

        $suggested = PriceList::query()->create(['manufacturer' => 'atg', 'version' => 'v1', 'suggested_prices' => true, 'product_ids' => [$card->id]]);
        $slot->update(['price_list_id' => $suggested->id]);

        $this->assertSame([ProductSourcePrice::SOURCE_FILE], $ownership->ownerSourceKeys($card));
        $this->assertTrue($ownership->isProtected($card));
    }

    public function test_card_without_manufacturer_has_no_owner(): void
    {
        $anro = $this->account('anro');
        $card = $this->card('');
        $this->link($anro, $card);
        $ownership = new CardOwnership;

        $this->assertSame([], $ownership->ownerSourceKeys($card));
        $this->assertFalse($ownership->isOwnerAccount($card, $anro));
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
        ]);
    }

    private function card(string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => 'K-'.$manufacturer.'-1',
            'name' => 'Karta '.$manufacturer,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 1,
            'purchase_price' => 1,
        ]);
    }

    private function link(B2bAccount $account, Product $card): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => 'R-'.$account->id.'-'.$card->id,
            'product_id' => $card->id,
        ]);
    }
}
