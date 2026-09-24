<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Services\Catalog\CardRedirectStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * card-redirects:anchor — zmiana pozycji wiodącej karty modelu z łączenia rozmiarów (plan łączenia kart, krok 6).
 * Przypadek: karta modelu 6X00 = #40819 (3M 6100 S, 7000146845) z pozycjami 3M M (7000146847) i L (7000146849).
 */
final class CardRedirectAnchorCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->card = Product::query()->create([
            'sku' => '7000146845', 'name' => '6X00 Półmaska 3M 6000', 'manufacturer' => '3M',
            'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN',
        ]);
        foreach (['7000146845' => true, '7000146847' => false, '7000146849' => false] as $position => $anchor) {
            $this->row('b2b:3', (string) $position, $anchor);
        }
    }

    public function test_preview_changes_nothing(): void
    {
        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => '7000146847'])
            ->expectsOutputToContain('Podgląd — nic nie zapisano')
            ->assertSuccessful();

        $this->assertSame(['7000146845'], $this->anchors('b2b:3'));
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_apply_moves_anchor_and_logs_activity(): void
    {
        // pozycja innego konta tej samej karty i wiersz „merge” — poza zmianą
        $this->row('b2b:7', 'X-1', true);
        $this->row('b2b:3', '9999', false, CardRedirect::REASON_MERGE);

        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => '7000146847', '--source' => 'b2b:3', '--apply' => true])
            ->expectsOutputToContain('Pozycja wiodąca: 7000146847 (poprzednio: 7000146845)')
            ->assertSuccessful();

        $this->assertSame(['7000146847'], $this->anchors('b2b:3'));
        $this->assertSame(['X-1'], $this->anchors('b2b:7'));
        $this->assertFalse((bool) CardRedirect::query()->where('position_key', '9999')->value('is_anchor'));
        $log = ActivityLog::query()->sole();
        $this->assertSame('card_redirect.anchor_changed', $log->action);
        $this->assertNull($log->user_id);
        $this->assertSame($this->card->id, (int) $log->subject_id);
        $this->assertSame('b2b:3', $log->meta['source_key']);
        $this->assertSame('7000146845', $log->meta['old_position']);
        $this->assertSame('7000146847', $log->meta['new_position']);
        $this->assertSame($this->card->id, $log->meta['product_id']);

        // ponownie ta sama pozycja — nic do zmiany, bez wpisu
        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => '7000146847', '--source' => 'b2b:3', '--apply' => true])
            ->expectsOutputToContain('już jest wiodąca')
            ->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_several_sources_require_source_option(): void
    {
        $this->row('b2b:7', 'X-1', true);

        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => '7000146847', '--apply' => true])
            ->expectsOutputToContain('podaj --source=')
            ->assertFailed();
        $this->assertSame(['7000146845'], $this->anchors('b2b:3'));
    }

    public function test_wrong_card_position_or_source_fails_without_changes(): void
    {
        $other = Product::query()->create([
            'sku' => 'INNA', 'name' => 'Inna karta', 'manufacturer' => '3M',
            'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN',
        ]);

        $this->artisan('card-redirects:anchor', ['product' => $other->id, 'position' => '7000146847', '--apply' => true])
            ->expectsOutputToContain('nie ma w mapie połączeń pozycji z łączenia rozmiarów')
            ->assertFailed();
        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => 'NIEZNANA', '--apply' => true])
            ->expectsOutputToContain('nie należy do karty')
            ->assertFailed();
        $this->artisan('card-redirects:anchor', ['product' => $this->card->id, 'position' => '7000146847', '--source' => 'b2b:99', '--apply' => true])
            ->expectsOutputToContain('nie ma pozycji łączenia rozmiarów źródła b2b:99')
            ->assertFailed();
        $this->artisan('card-redirects:anchor', ['product' => 'abc', 'position' => '7000146847'])
            ->assertFailed();

        $this->assertSame(['7000146845'], $this->anchors('b2b:3'));
        $this->assertSame(0, ActivityLog::query()->count());
    }

    private function row(string $source, string $position, bool $anchor, string $reason = CardRedirect::REASON_SIZE_MERGE): void
    {
        CardRedirect::query()->create([
            'source_key' => $source,
            'position_key' => $position,
            // konto nie jest potrzebne poleceniu (działa po source_key)
            'b2b_account_id' => null,
            'product_id' => $this->card->id,
            'reason' => $reason,
            'is_anchor' => $anchor,
            'target_snapshot' => CardRedirectStore::snapshot($this->card),
        ]);
    }

    /**
     * @return list<string>
     */
    private function anchors(string $source): array
    {
        return CardRedirect::query()->where('source_key', $source)->where('is_anchor', true)
            ->orderBy('position_key')->pluck('position_key')->map(static fn ($p): string => (string) $p)->all();
    }
}
