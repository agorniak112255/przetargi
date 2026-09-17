<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * b2b:relink-descriptions — karty, którym przerwany zapis zostawił opis z synchronizacji, ale powiązanie ze starym
 * odciskiem. Bez odcisku synchronizacja uznaje taki opis za ręczny i nigdy go już nie odświeża.
 */
final class B2bRelinkDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SYNC_TEXT = "Wstępnie uformowane wykrywalne zatyczki wielokrotnego użytku.\n\nJednostka: kart.";

    public function test_preview_lists_cards_and_changes_nothing(): void
    {
        $card = $this->card('2111.239', self::SYNC_TEXT, sha1('inny opis, zapisany przy poprzednim przebiegu'));

        $this->artisan('b2b:relink-descriptions')
            ->expectsOutputToContain('kart z rozjechanym odciskiem opisu: 1')
            ->expectsOutputToContain('Uruchom z --apply')
            ->assertSuccessful();

        $this->assertSame(
            sha1('inny opis, zapisany przy poprzednim przebiegu'),
            B2bProductLink::query()->where('product_id', $card->id)->value('description_hash'),
        );
    }

    public function test_apply_writes_the_fingerprint_of_the_description_the_card_has(): void
    {
        $card = $this->card('2111.239', self::SYNC_TEXT, sha1('opis z poprzedniego przebiegu'));
        // druga pozycja tego samego wyrobu (rozmiar) — powiązanie każdego kodu wskazuje tę samą kartę
        B2bProductLink::query()->create([
            'b2b_account_id' => (int) B2bAccount::query()->value('id'),
            'product_id' => $card->id,
            'remote_id' => '2111.240',
            'remote_sku' => '2111.240',
            'description_hash' => sha1('opis z poprzedniego przebiegu'),
            'last_seen_at' => now(),
        ]);

        $this->artisan('b2b:relink-descriptions', ['--apply' => true])
            ->expectsOutputToContain('Naprawiono 1 kart')
            ->assertSuccessful();

        $this->assertSame(
            [sha1(self::SYNC_TEXT), sha1(self::SYNC_TEXT)],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('description_hash')->all(),
        );
        // opis karty zostaje bez zmian — o treści rozstrzyga dopiero kolejne pobranie cennika
        $this->assertSame(self::SYNC_TEXT, $card->fresh()?->description);
    }

    public function test_card_with_a_matching_fingerprint_or_a_hand_written_description_is_left_alone(): void
    {
        $intact = $this->card('2112.004', self::SYNC_TEXT, sha1(self::SYNC_TEXT));
        $byHand = $this->card('2112.106', 'Opis wpisany ręcznie przez handlowca, bez śladu synchronizacji.', sha1('poprzedni opis'));

        $this->artisan('b2b:relink-descriptions', ['--apply' => true])
            ->expectsOutputToContain('nie ma czego naprawiać')
            ->expectsOutputToContain('Pominięto 1 kart bez śladu synchronizacji')
            ->assertSuccessful();

        $this->assertSame(sha1(self::SYNC_TEXT), B2bProductLink::query()->where('product_id', $intact->id)->value('description_hash'));
        $this->assertSame(sha1('poprzedni opis'), B2bProductLink::query()->where('product_id', $byHand->id)->value('description_hash'));
    }

    public function test_unknown_account_is_an_error(): void
    {
        $this->artisan('b2b:relink-descriptions', ['--account' => 987])
            ->expectsOutputToContain('Nie ma konta B2B o id 987')
            ->assertFailed();
    }

    private function card(string $sku, string $description, ?string $hash): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Zatyczki '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'stock' => 0,
            'description' => $description,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        $account = B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            ['contractor_code' => 'K123', 'password' => 'haslo', 'sites' => ['izam.system-b2b.pl'], 'connector' => 'uvex'],
        );
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'product_id' => $product->id,
            'remote_id' => $sku,
            'remote_sku' => $sku,
            'description_hash' => $hash,
            'last_seen_at' => now(),
        ]);

        return $product;
    }
}
