<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\Role;
use App\Models\TenderActivity;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Towary BHP dodane same z treści ogłoszenia (auto_add): wymaganie = nazwa + cechy przepisane z ogłoszenia,
 * pochodzenie 'notice_text' z numerem ogłoszenia (dokument postępowania może je potem uzupełnić).
 */
final class NoticeAutoAddItemSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Warsaw'));
        Http::fake();
    }

    public function test_auto_added_items_carry_spec_and_notice_origin(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        $parsed = $notice->parsed;
        $parsed['lots'] = [[
            'lot_no' => 6,
            'name' => 'Część 6: zasoby ochrony ludności',
            'description' => "Część 6: zasoby ochrony ludności, w tym zakup i dostawa:\n- hełm strażacki – 23 szt., zgodny z normą EN 443, kolor biały,\n- ubranie specjalne – 23 szt.",
        ]];
        $notice->forceFill(['parsed' => $parsed, 'html_body' => null])->save();
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn(['content' => json_encode(['items' => [
                ['lot_no' => 6, 'name' => 'Hełm strażacki', 'spec' => ['EN 443', 'kolor biały'], 'quantity' => 23, 'unit' => 'szt.', 'quote' => '- hełm strażacki – 23 szt.', 'bhp' => true],
                ['lot_no' => 6, 'name' => 'Ubranie specjalne', 'spec' => [], 'quantity' => 23, 'unit' => 'szt.', 'quote' => 'ubranie specjalne – 23 szt.', 'bhp' => true],
            ]], JSON_UNESCAPED_UNICODE)]);
        });

        $response = $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['auto_add' => true])
            ->assertOk()
            ->assertJsonPath('added_count', 2);
        $this->assertSame(['Hełm strażacki — EN 443; kolor biały', 'Ubranie specjalne'], array_column($response->json('items'), 'requirement'));
        $this->assertStringContainsString('hełm strażacki', $response->json('extracted_text'));

        $items = TenderItem::query()->where('tender_id', $tenderId)->orderBy('line_no')->get();
        $this->assertSame(['Hełm strażacki — EN 443; kolor biały', 'Ubranie specjalne'], $items->pluck('requirement')->all());
        $this->assertSame(['notice_text', 'notice_text'], $items->pluck('source')->all());
        // numer ogłoszenia i część zamówienia — dokument uzupełnia automatycznie tylko pozycje z jednej części
        $this->assertSame([$notice->notice_number.'|część 6', $notice->notice_number.'|część 6'], $items->pluck('source_ref')->all());

        $activity = TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'items_from_notice')->sole();
        $this->assertSame('EN 443; kolor biały', $activity->meta['items'][0]['spec']);
        $this->assertStringContainsString('cechy z ogłoszenia: EN 443; kolor biały', $activity->meta['note']);

        // drugi odczyt z pamięci podręcznej (bez modelu) — pozycje już są, nic nie dochodzi
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['auto_add' => true])
            ->assertOk()
            ->assertJsonPath('added_count', 0);
        $this->assertSame(2, TenderItem::query()->where('tender_id', $tenderId)->count());
    }

    public function test_preview_commit_from_notice_text_keeps_notice_origin(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        // podgląd z treści ogłoszenia zatwierdzony przyciskiem „Dodaj do przetargu” — pochodzenie: ogłoszenie
        $this->postJson("/api/tenders/{$tenderId}/documents/commit", [
            'origin' => 'notice_text',
            'items' => [
                ['requirement' => 'Rękawice specjalne', 'quantity' => 1, 'quantity_missing' => true],
                ['requirement' => 'Bielizna trudnopalna', 'quantity' => 5, 'lot_no' => 2],
            ],
        ])->assertOk()->assertJsonPath('items_created', 2);

        $items = TenderItem::query()->where('tender_id', $tenderId)->orderBy('line_no')->get();
        $this->assertSame(['notice_text', 'notice_text'], $items->pluck('source')->all());
        $this->assertSame([$notice->notice_number, $notice->notice_number.'|część 2'], $items->pluck('source_ref')->all());
    }

    public function test_without_tender_create_permission_only_cached_result(): void
    {
        $owner = User::factory()->withRole('przetargi')->create();
        Sanctum::actingAs($owner);
        $notice = $this->fixtureNotice('contract-lots');
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        // ta sama osoba bez uprawnienia do zakładania przetargów: model nie jest pytany
        Role::findByName('przetargi', 'web')->revokePermissionTo('tenders.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner->refresh();
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->never();
        });

        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['auto_add' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notice');
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['refresh' => true])->assertForbidden();
        $this->assertSame(0, TenderItem::query()->where('tender_id', $tenderId)->count());
    }

    private function fixtureNotice(string $name): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path("tests/Fixtures/bzp/{$name}.json")), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }
}
