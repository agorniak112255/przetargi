<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\NoticeItemsReader;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * POST /api/tenders/{tender}/documents/from-notice-text — towary z treści ogłoszenia (model z atrapą): podgląd bez
 * zapisu pozycji, cytat sprawdzany w tekście ogłoszenia, ilość tylko z cytatu, towary spoza BHP odznaczone.
 */
final class NoticeItemsFromTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Warsaw'));
        Http::fake();
    }

    public function test_items_from_notice_text_are_verified_preview_only(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->withArgs(function (array $messages): bool {
                // do modelu idzie sekcja „Przedmiot zamówienia” z treści ogłoszenia
                return str_contains($messages[1]['content'], 'Część 2 - Dostawa Bielizny trudnopalnej termoaktywna');
            })->andReturn(['content' => json_encode(['items' => [
                // towar BHP z cytatem z ogłoszenia; ilości w cytacie nie ma → null (do uzupełnienia), nie 50;
                // cecha „trudnopalnej” przepisana z ogłoszenia (w odmianie modelu — zapisany wycinek z ogłoszenia),
                // „klasa 2” — nie ma jej w ogłoszeniu, odpada
                ['lot_no' => 2, 'name' => 'Bielizna termoaktywna', 'spec' => ['trudnopalna', 'klasa 2'], 'quantity' => 50, 'unit' => 'kpl.', 'quote' => 'Część 2 - Dostawa Bielizny trudnopalnej termoaktywna', 'bhp' => true],
                // spoza BHP — odznaczony
                ['lot_no' => 1, 'name' => 'Gaśnice i węże', 'quantity' => null, 'unit' => null, 'quote' => 'Cześć 1 - Dostawa gaśnic i węzy', 'bhp' => false],
                // cytatu nie ma w ogłoszeniu — odznaczony, część spoza ogłoszenia → null
                ['lot_no' => 9, 'name' => 'Rękawice nitrylowe', 'quantity' => 100, 'unit' => 'par', 'quote' => 'Rękawice nitrylowe – 100 par', 'bhp' => true],
                // przepisany schemat — pominięty
                ['lot_no' => 'numer części albo null', 'name' => 'nazwa towaru', 'quantity' => 'liczba albo null', 'unit' => 'jednostka albo null', 'quote' => 'fragment ogłoszenia', 'bhp' => true],
            ]], JSON_UNESCAPED_UNICODE)]);
        });

        $response = $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text")
            ->assertOk()
            ->assertJsonPath('document_id', null)
            ->assertJsonPath('items_count', 3)
            ->assertJsonPath('conditions', [])
            ->assertJsonPath('source.notice_number', $notice->notice_number);

        $items = $response->json('items');
        $this->assertSame(['Bielizna termoaktywna', 'Gaśnice i węże', 'Rękawice nitrylowe'], array_column($items, 'name'));
        // wymaganie pozycji = rodzaj — cechy z ogłoszenia (wyszukiwanie produktu dostaje specyfikację)
        $this->assertSame('Bielizna termoaktywna — trudnopalnej', $items[0]['requirement']);
        $this->assertSame([true, false, false], array_column($items, 'selected'));
        $this->assertSame([1, 1, 1], array_column($items, 'quantity'));
        $this->assertSame([true, true, true], array_column($items, 'quantity_missing'));
        $this->assertSame([2, 1, null], array_column($items, 'lot_no'));
        $this->assertSame([true, true, false], array_column($items, 'quote_found'));
        $this->assertStringContainsString('4.2.2.) Krótki opis przedmiotu zamówienia', $response->json('extracted_text'));

        // nic nie trafia do przetargu bez „commit”
        $this->assertSame(0, TenderItem::query()->where('tender_id', $tenderId)->count());
    }

    public function test_auto_add_saves_bhp_items_with_quantity_once_and_logs_quotes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        // treść skasowana po 30 dniach — odczyt z opisu części
        $parsed = $notice->parsed;
        $parsed['lots'] = [[
            'lot_no' => 6,
            'name' => 'Część 6: zasoby ochrony ludności',
            'description' => "Część 6: zasoby ochrony ludności, w tym zakup i dostawa:\n- hełm strażacki – 23 szt.,\n- ubranie specjalne – 23 szt.,\n- agregat prądotwórczy – 2 szt.,\n- rękawice specjalne.",
        ]];
        $notice->forceFill(['parsed' => $parsed, 'html_body' => null])->save();
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $answer = ['content' => json_encode(['items' => [
            ['lot_no' => 6, 'name' => 'Hełm strażacki', 'quantity' => 23, 'unit' => 'szt.', 'quote' => '- hełm strażacki – 23 szt.', 'bhp' => true],
            ['lot_no' => 6, 'name' => 'Ubranie specjalne', 'quantity' => 23, 'unit' => 'szt.', 'quote' => 'ubranie specjalne – 23 szt.', 'bhp' => true],
            // spoza BHP — zostaje w podglądzie
            ['lot_no' => 6, 'name' => 'Agregat prądotwórczy', 'quantity' => 2, 'unit' => 'szt.', 'quote' => 'agregat prądotwórczy – 2 szt.', 'bhp' => false],
            // BHP bez ilości w ogłoszeniu — nie zgadujemy ilości, zostaje w podglądzie
            ['lot_no' => 6, 'name' => 'Rękawice specjalne', 'quantity' => null, 'unit' => null, 'quote' => 'rękawice specjalne', 'bhp' => true],
        ]], JSON_UNESCAPED_UNICODE)];
        // drugi odczyt bierze wynik z pamięci podręcznej — model pytany raz
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock) use ($answer): void {
            $mock->shouldReceive('chat')->once()->andReturn($answer);
        });

        $response = $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['auto_add' => true])
            ->assertOk()
            ->assertJsonPath('added_count', 2)
            ->assertJsonPath('auto_add_note', null);
        $this->assertSame([true, true, false, false], array_column($response->json('items'), 'added'));
        $this->assertSame([false, false, false, true], array_column($response->json('items'), 'selected'));

        $items = TenderItem::query()->where('tender_id', $tenderId)->orderBy('line_no')->get();
        $this->assertSame(['Hełm strażacki', 'Ubranie specjalne'], $items->pluck('requirement')->all());
        $this->assertSame([23, 23], $items->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame('wycena', Tender::query()->findOrFail($tenderId)->status);

        $activity = TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'items_from_notice')->sole();
        $this->assertSame($notice->notice_number, $activity->meta['notice_number']);
        $this->assertSame('- hełm strażacki – 23 szt.', $activity->meta['items'][0]['quote']);
        $this->assertStringContainsString('Dodano automatycznie 2 towary BHP', $activity->meta['note']);

        // drugi odczyt (np. druga karta) nie dubluje pozycji
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['auto_add' => true])
            ->assertOk()
            ->assertJsonPath('added_count', 0)
            ->assertJsonPath('auto_add_note', 'Przetarg ma już pozycje — towary z ogłoszenia są tylko w podglądzie.');
        $this->assertSame(2, TenderItem::query()->where('tender_id', $tenderId)->count());
        $this->assertSame(1, TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'items_from_notice')->count());
    }

    public function test_panel_reading_is_reused_and_refresh_asks_model_again(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        $answer = ['content' => json_encode(['items' => [
            ['lot_no' => 2, 'name' => 'Bielizna termoaktywna', 'spec' => ['trudnopalnej'], 'quantity' => null, 'unit' => null, 'quote' => 'Część 2 - Dostawa Bielizny trudnopalnej termoaktywna', 'bhp' => true],
        ]], JSON_UNESCAPED_UNICODE)];
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock) use ($answer): void {
            $mock->shouldReceive('chat')->twice()->andReturn($answer);
        });

        // szczegóły ogłoszenia odczytały asortyment — kreator przetargu nie pyta modelu drugi raz
        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', false);
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text")
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Bielizna termoaktywna')
            ->assertJsonPath('items.0.requirement', 'Bielizna termoaktywna — trudnopalnej');

        // „Odczytaj ponownie” — drugie zapytanie do modelu
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text", ['refresh' => true])
            ->assertOk()
            ->assertJsonPath('items_count', 1);
    }

    public function test_quantity_only_from_quote_with_dashes_and_thousands(): void
    {
        $text = "Część 6: zakup i dostawa:\n- hełm strażacki – 23 szt.,\n- ubranie specjalne – 23 szt.\n1. Garsonki damskie - 1 000 kpl,;";
        $rows = app(NoticeItemsReader::class)->verify([
            // model zamienił półpauzę na łącznik — cytat nadal pasuje
            ['lot_no' => 6, 'name' => 'Hełm strażacki', 'quantity' => 23, 'unit' => 'szt.', 'quote' => '- hełm strażacki - 23 szt.', 'bhp' => true],
            ['lot_no' => null, 'name' => 'Garsonka damska', 'quantity' => 1000, 'unit' => 'kpl', 'quote' => 'Garsonki damskie - 1 000 kpl', 'bhp' => true],
            // liczba spoza cytatu (23 ≠ 230) — ilość nie jest zgadywana
            ['lot_no' => 6, 'name' => 'Ubranie specjalne', 'quantity' => 230, 'unit' => 'szt.', 'quote' => 'ubranie specjalne – 23 szt.', 'bhp' => true],
            // powtórzona pozycja
            ['lot_no' => 6, 'name' => 'hełm strażacki', 'quantity' => 23, 'unit' => 'szt.', 'quote' => 'hełm strażacki – 23 szt.', 'bhp' => true],
        ], $text, [6]);

        $this->assertCount(3, $rows);
        $this->assertSame([23, 1000, null], array_column($rows, 'quantity'));
        $this->assertSame([true, true, true], array_column($rows, 'quote_found'));
        $this->assertSame([6, null, 6], array_column($rows, 'lot_no'));
    }

    public function test_permissions_and_tender_without_notice(): void
    {
        $notice = $this->fixtureNotice('contract-lots');
        $owner = User::factory()->withRole('przetargi')->create();
        Sanctum::actingAs($owner);
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        // bez uprawnienia „Dodawanie dokumentów” (handlowiec) — bez odczytu
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text")->assertForbidden();

        // przetarg bez ogłoszenia (numer usunięty) — komunikat zamiast odczytu
        Tender::query()->whereKey($tenderId)->update(['contract_notice_id' => null, 'notice_number' => null]);
        Sanctum::actingAs($owner);
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice-text")
            ->assertStatus(422)
            ->assertJsonValidationErrors('notice');
    }

    private function fixtureNotice(string $name): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path("tests/Fixtures/bzp/{$name}.json")), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }
}
