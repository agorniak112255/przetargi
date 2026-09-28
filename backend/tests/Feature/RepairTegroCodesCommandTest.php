<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:repair-tegro-codes — decyzja właściciela 28.09.2026: kod karty Tegro bez rozmiaru, wyrób w jednym rozmiarze
 * także bez rozmiaru w nazwie. Dane syntetyczne w kształcie z produkcji (kody „CITRIN 7”, pozycje bez modelu z wierszem
 * „Rozmiar” w tabelce sklepu, dawny podział HEAVY / HEAVY 8).
 */
final class RepairTegroCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const RS = 'RĘKAWICE RS ARBEITSSCHUTZ ';

    private B2bAccount $tegro;

    private int $position = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tegro = B2bAccount::query()->create(['username' => 'jan@example.com', 'sites' => ['https://b2b.tegro.pl/'], 'connector' => 'tegro']);
    }

    public function test_preview_changes_nothing_apply_repairs_and_restore_skips_cards_edited_after_the_repair(): void
    {
        // karta z rozmiarami: nazwa modelu już jest, kod = najmniejszy rozmiar
        $citrin = $this->card('CITRIN 7', self::RS.'CITRIN', [['CITRIN 7', self::RS.'CITRIN 7', '7'], ['CITRIN 8', self::RS.'CITRIN 8', '8'], ['CITRIN 11', self::RS.'CITRIN 11', '11']]);
        // wyrób bez modelu: rozmiar tylko w wierszu „Rozmiar” tabelki sklepu
        $comfort = $this->card('COMFORT PREMIUM 10', self::RS.'COMFORT PREMIUM 10', [['COMFORT PREMIUM 10', self::RS.'COMFORT PREMIUM 10', null]], '10');
        // kod bez rozmiaru — zostaje, z nazwy rozmiar schodzi
        $polar = $this->card('POLAR I', self::RS.'POLAR I 10', [['POLAR I', self::RS.'POLAR I 10', null]], '10');
        // zakres liczbowy w tabelce z liczbą, na którą kończy się nazwa — rozmiar schodzi (decyzja 28.09.2026)
        $split = $this->card('SPLIT 10', self::RS.'SPLIT 10', [['SPLIT 10', self::RS.'SPLIT 10', null]], '7-11');
        // liczba spoza zakresu i zakres literowy — nic nie zgadujemy
        $luwac = $this->card('LUWAC 12', self::RS.'LUWAC 12', [['LUWAC 12', self::RS.'LUWAC 12', null]], '6-11');
        $buffalo = $this->card('BUFFALO XL', self::RS.'BUFFALO XL', [['BUFFALO XL', self::RS.'BUFFALO XL', null]], 'S-XXL');

        $this->artisan('products:repair-tegro-codes', ['--account' => $this->tegro->id])
            ->expectsOutputToContain('do zmiany: 4 (kodów 3, nazw 3)')
            ->expectsOutputToContain('Podgląd — uruchom z --apply')
            ->assertSuccessful();
        $this->assertSame('CITRIN 7', $citrin->fresh()?->sku);
        $this->assertSame(self::RS.'COMFORT PREMIUM 10', $comfort->fresh()?->name);

        $backup = storage_path('framework/testing/tegro-codes.json');
        @unlink($backup);
        $this->artisan('products:repair-tegro-codes', ['--account' => $this->tegro->id, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Poprawiono 4 kart.')
            ->assertSuccessful();

        $this->assertSame(['CITRIN', self::RS.'CITRIN'], [$citrin->fresh()?->sku, $citrin->fresh()?->name]);
        $this->assertSame(['COMFORT PREMIUM', self::RS.'COMFORT PREMIUM'], [$comfort->fresh()?->sku, $comfort->fresh()?->name]);
        $this->assertSame(['POLAR I', self::RS.'POLAR I'], [$polar->fresh()?->sku, $polar->fresh()?->name]);
        $this->assertSame(['SPLIT', self::RS.'SPLIT'], [$split->fresh()?->sku, $split->fresh()?->name]);
        $this->assertSame(['LUWAC 12', self::RS.'LUWAC 12'], [$luwac->fresh()?->sku, $luwac->fresh()?->name]);
        $this->assertSame(['BUFFALO XL', self::RS.'BUFFALO XL'], [$buffalo->fresh()?->sku, $buffalo->fresh()?->name]);
        // kody i nazwy pozycji u dostawcy zostają dosłownie
        $this->assertSame(['COMFORT PREMIUM 10', self::RS.'COMFORT PREMIUM 10'], [
            B2bProductLink::query()->where('product_id', $comfort->id)->value('remote_sku'),
            B2bProductLink::query()->where('product_id', $comfort->id)->value('remote_name'),
        ]);
        $this->assertSame('COMFORT PREMIUM 10', ProductIdentifier::query()->where('product_id', $comfort->id)->value('value'));
        // zapis przez model — indeks wyszukiwania zna nowy kod
        $this->assertStringContainsString('comfort premium', mb_strtolower((string) $comfort->fresh()?->search_blob));

        // drugi przebieg: nic do zmiany
        $this->artisan('products:repair-tegro-codes', ['--account' => $this->tegro->id])
            ->expectsOutputToContain('Nic do zapisania.')
            ->assertSuccessful();

        // przywracanie omija kartę zmienioną po naprawie
        $citrin->fresh()?->update(['name' => self::RS.'CITRIN (poprawiona ręcznie)']);
        $this->artisan('products:repair-tegro-codes', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 3 kart')
            ->expectsOutputToContain('#'.$citrin->id.' (karta zmieniła się od naprawy)')
            ->assertSuccessful();
        $this->assertSame(['COMFORT PREMIUM 10', self::RS.'COMFORT PREMIUM 10'], [$comfort->fresh()?->sku, $comfort->fresh()?->name]);
        $this->assertSame(['POLAR I', self::RS.'POLAR I 10'], [$polar->fresh()?->sku, $polar->fresh()?->name]);
        $this->assertSame(['CITRIN', self::RS.'CITRIN (poprawiona ręcznie)'], [$citrin->fresh()?->sku, $citrin->fresh()?->name]);
        @unlink($backup);
    }

    public function test_taken_code_duplicate_cards_foreign_links_and_presta_matches_are_left_alone(): void
    {
        $other = B2bAccount::query()->create(['username' => 'inne', 'sites' => ['https://inne.example/'], 'connector' => 'procera']);
        // kod modelu „ALASKA” ma karta innego producenta — kod zostaje, nazwa wyrobu w jednym rozmiarze się zmienia
        $foreignAlaska = Product::query()->create(['sku' => 'ALASKA', 'name' => 'Buty ALASKA', 'manufacturer' => 'INNY']);
        $alaska = $this->card('ALASKA 10', self::RS.'ALASKA 10', [['ALASKA 10', self::RS.'ALASKA 10', '10']]);
        // dawny podział rozmiarów według ceny: HEAVY 9/10/11 i HEAVY 8 — obie bez zmian, najpierw „Scal rozmiary”
        $heavy = $this->card('HEAVY 9', self::RS.'HEAVY', [['HEAVY 9', self::RS.'HEAVY 9', '9'], ['HEAVY 10', self::RS.'HEAVY 10', '10'], ['HEAVY 11', self::RS.'HEAVY 11', '11']]);
        $heavy8 = $this->card('HEAVY 8', self::RS.'HEAVY 8', [['HEAVY 8', self::RS.'HEAVY 8', '8']]);
        // karta z powiązaniem innego konta
        $bass = $this->card('BASS 7', self::RS.'BASS 7', [['BASS 7', self::RS.'BASS 7', '7']]);
        B2bProductLink::query()->create(['b2b_account_id' => $other->id, 'remote_id' => 'x1', 'product_id' => $bass->id, 'remote_sku' => 'B7', 'remote_name' => 'Rękawice BASS 7']);
        // karta dopasowana do PrestaShop
        $drum = $this->card('DRUM 8', self::RS.'DRUM 8', [['DRUM 8', self::RS.'DRUM 8', '8']]);
        PrestaProductMatch::query()->create(['product_id' => $drum->id, 'presta_id' => 77, 'method' => 'sku', 'score' => 100, 'status' => PrestaProductMatch::STATUS_APPLIED]);
        // kod pozycji, który nie jest „kod-modelu rozmiar”
        $mix = $this->card('MIX 7', self::RS.'MIX', [['MIX 7', self::RS.'MIX 7', '7'], ['MIX-8', self::RS.'MIX 8', '8']]);

        $backup = storage_path('framework/testing/tegro-codes-conflicts.json');
        @unlink($backup);
        $this->artisan('products:repair-tegro-codes', ['--account' => $this->tegro->id, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('kod ALASKA zajęty przez #'.$foreignAlaska->id)
            ->expectsOutputToContain('najpierw Scal rozmiary (#'.$heavy->id.', #'.$heavy8->id.')')
            ->expectsOutputToContain('pominięta: powiązania innych kont B2B (#'.$other->id.')')
            ->expectsOutputToContain('pominięta: karta dopasowana do PrestaShop')
            ->expectsOutputToContain('kod MIX-8 nie jest „MIX rozmiar” — kod zostaje')
            ->expectsOutputToContain('Poprawiono 1 kart.')
            ->assertSuccessful();

        $this->assertSame(['ALASKA 10', self::RS.'ALASKA'], [$alaska->fresh()?->sku, $alaska->fresh()?->name]);
        $this->assertSame('ALASKA', $foreignAlaska->fresh()?->sku);
        $this->assertSame(['HEAVY 9', self::RS.'HEAVY'], [$heavy->fresh()?->sku, $heavy->fresh()?->name]);
        $this->assertSame(['HEAVY 8', self::RS.'HEAVY 8'], [$heavy8->fresh()?->sku, $heavy8->fresh()?->name]);
        $this->assertSame(['BASS 7', self::RS.'BASS 7'], [$bass->fresh()?->sku, $bass->fresh()?->name]);
        $this->assertSame(['DRUM 8', self::RS.'DRUM 8'], [$drum->fresh()?->sku, $drum->fresh()?->name]);
        $this->assertSame(['MIX 7', self::RS.'MIX'], [$mix->fresh()?->sku, $mix->fresh()?->name]);
        @unlink($backup);
    }

    public function test_account_must_exist_and_be_a_tegro_account(): void
    {
        $other = B2bAccount::query()->create(['username' => 'inne', 'sites' => ['https://inne.example/'], 'connector' => 'procera']);

        $this->artisan('products:repair-tegro-codes')->assertFailed();
        $this->artisan('products:repair-tegro-codes', ['--account' => 999])->expectsOutputToContain('Nie ma konta B2B numer 999.')->assertFailed();
        $this->artisan('products:repair-tegro-codes', ['--account' => $other->id])->expectsOutputToContain('nie jest kontem Tegro')->assertFailed();
    }

    /**
     * Karta konta Tegro z pozycjami: [kod pozycji, nazwa pozycji, rozmiar (etykieta kodu) albo null].
     *
     * @param  list<array{0: string, 1: string, 2: string|null}>  $positions
     */
    private function card(string $sku, string $name, array $positions, ?string $shopSize = null): Product
    {
        $product = Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'RS']);
        foreach ($positions as [$code, $remoteName, $label]) {
            $remoteId = (string) $this->position++;
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->tegro->id, 'remote_id' => $remoteId, 'product_id' => $product->id,
                'remote_sku' => $code, 'remote_name' => $remoteName, 'manufacturer' => 'RS',
            ]);
            ProductIdentifier::query()->create([
                'product_id' => $product->id, 'source_key' => 'b2b:'.$this->tegro->id, 'b2b_account_id' => $this->tegro->id,
                'position_key' => $remoteId, 'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => $code,
                'source_field' => 'Sku', 'variant_label' => $label,
            ]);
        }
        if ($shopSize !== null) {
            ProductShopCard::query()->create([
                'product_id' => $product->id, 'b2b_account_id' => $this->tegro->id, 'synced_at' => now(),
                'fields' => [
                    ['section' => 'Parametry produktu', 'rows' => [['name' => 'Rozmiar', 'value' => $shopSize], ['name' => 'KodCN', 'value' => '61161020']]],
                    ['section' => 'Informacje handlowe', 'rows' => [['name' => 'Marka', 'value' => 'RS']]],
                ],
            ]);
        }

        return $product;
    }
}
