<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\NoticeItemsReader;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * GET /api/notices/{notice}/items — podsumowanie asortymentu w szczegółach ogłoszenia (NoticeItemsReader::readCached,
 * model z atrapą): wynik zapamiętany po treści, ponowny odczyt, uprawnienia (model tylko dla zakładających przetargi),
 * limit odczytów naraz (503), błędy bez zapamiętania. Plus weryfikacja cech (spec) w oknie pozycji.
 */
final class NoticeItemsSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private const LOT_TEXT = "Część 6: zasoby ochrony ludności, w tym zakup i dostawa:\n"
        ."- hełm strażacki zgodny z EN 443:2008, kolor biały – 23 szt.,\n"
        ."- rękawice specjalne skórzane, EN 659, rozmiar 10 – 46 par,\n"
        .'- agregat prądotwórczy 5 kW – 2 szt.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Warsaw'));
        Http::fake();
    }

    public function test_summary_is_read_once_cached_and_refreshed_on_request(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->lotNotice();
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->twice()->andReturn($this->answer());
        });

        $first = $this->getJson("/api/notices/{$notice->id}/items")
            ->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'lots')
            ->assertJsonPath('note', null)
            ->assertJsonPath('lots.0.lot_no', 6)
            ->assertJsonPath('lots.0.bhp', true);
        $items = $first->json('items');
        $this->assertSame(['Hełm strażacki', 'Rękawice specjalne', 'Agregat prądotwórczy'], array_column($items, 'name'));
        $this->assertSame([23, 46, 2], array_column($items, 'quantity'));
        // cechy przepisane z ogłoszenia (oryginalna pisownia), „skórzane” w odmianie modelu dopasowane po rdzeniu
        $this->assertSame('EN 443:2008; kolor biały', $items[0]['spec']);
        $this->assertSame(['skórzane', 'EN 659', 'rozmiar 10'], $items[1]['spec_fragments']);
        // „5 kW” to cecha agregatu, „EN 443” spoza jego okna — odrzucone
        $this->assertSame('5 kW', $items[2]['spec']);
        $this->assertSame([true, true, false], array_column($items, 'bhp'));
        $this->assertNotNull($first->json('read_at'));

        // drugie otwarcie panelu — z pamięci, bez modelu
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('read_at', $first->json('read_at'))
            ->assertJsonPath('items.0.spec', 'EN 443:2008; kolor biały');

        // „Odczytaj ponownie” — model jeszcze raz
        $this->travel(5)->minutes();
        $refreshed = $this->getJson("/api/notices/{$notice->id}/items?refresh=1")
            ->assertOk()
            ->assertJsonPath('cached', false);
        $this->assertNotSame($first->json('read_at'), $refreshed->json('read_at'));
    }

    public function test_viewer_without_create_gets_only_cached_result_and_cannot_refresh(): void
    {
        $notice = $this->lotNotice();
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn($this->answer());
        });

        // dyrektor: wgląd we wszystkie przetargi bez zakładania — model się nie uruchamia
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertOk()
            ->assertJsonPath('items', null)
            ->assertJsonPath('cached', false);
        $this->assertStringContainsString('nikt jeszcze nie odczytał', (string) $this->getJson("/api/notices/{$notice->id}/items")->json('note'));
        $this->getJson("/api/notices/{$notice->id}/items?refresh=1")->assertForbidden();

        // zakładający przetargi odczytuje — dyrektor widzi zapamiętany wynik
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', false);
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('items.0.name', 'Hełm strażacki');

        // bez wglądu w ogłoszenia — 403
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/notices/{$notice->id}/items")->assertForbidden();
    }

    public function test_busy_slots_return_503_and_errors_are_not_cached(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->lotNotice();
        $calls = 0;
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('chat')->andReturnUsing(function () use (&$calls): array {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('Model nie odpowiedział w wyznaczonym czasie.');
                }

                return $this->answer();
            });
        });

        // oba miejsca na odczyt zajęte (dwa inne ogłoszenia czytane w tej chwili)
        $slots = [Cache::lock('notice-items-slot:0', 60), Cache::lock('notice-items-slot:1', 60)];
        foreach ($slots as $slot) {
            $this->assertTrue($slot->get());
        }
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Model zajęty — spróbuj za chwilę.');
        $this->assertSame(0, $calls);
        $slots[1]->release();

        // błąd modelu — 422 z komunikatem, bez zapamiętania (następne otwarcie pyta model jeszcze raz)
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Model nie odpowiedział w wyznaczonym czasie.');
        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', false);
        $this->assertSame(2, $calls);
        $slots[0]->release();
    }

    public function test_empty_list_is_remembered_shorter_and_missing_description_is_422(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->lotNotice();
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->twice()->andReturn(['content' => '{"items": []}']);
        });

        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('items', []);
        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', true);
        // pusta lista pamiętana 1 dzień, nie 14
        $this->travel(2)->days();
        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', false);

        $parsed = $notice->parsed;
        $parsed['lots'] = [];
        $notice->forceFill(['parsed' => $parsed])->save();
        $this->getJson("/api/notices/{$notice->id}/items")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ogłoszenie nie ma opisu przedmiotu zamówienia — pozycje są w dokumentach postępowania.')
            // stan trwały — panel nie proponuje ponowienia
            ->assertJsonPath('reason', 'no_description');
    }

    public function test_cached_only_never_runs_model_nor_takes_slot(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->lotNotice();
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn($this->answer());
        });

        // oba miejsca zajęte — podgląd pamięci i tak odpowiada (bez modelu, bez 503)
        $slots = [Cache::lock('notice-items-slot:0', 60), Cache::lock('notice-items-slot:1', 60)];
        foreach ($slots as $slot) {
            $slot->get();
        }
        $this->getJson("/api/notices/{$notice->id}/items?cached_only=1")
            ->assertOk()
            ->assertJsonPath('items', null)
            ->assertJsonPath('note', null);
        foreach ($slots as $slot) {
            $slot->release();
        }

        $this->getJson("/api/notices/{$notice->id}/items")->assertOk()->assertJsonPath('cached', false);
        $this->getJson("/api/notices/{$notice->id}/items?cached_only=1")
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('items.0.name', 'Hełm strażacki');
        // odczyt zwolnił miejsce na odczyt
        $this->assertTrue(Cache::lock('notice-items-slot:0', 1)->get());
    }

    public function test_spec_fragments_verified_only_in_item_window(): void
    {
        $text = "Część 1\n- rękawice ochronne nitrylowe, EN 388:2016 4121X, długość 30 cm – 100 par\n"
            ."- buty robocze S3 SRC, rozmiar 42 – 10 par\n"
            ."Część 2\n- kurtka zimowa kolor granatowy, z kapturem – 5 szt.\n"
            .'Wymagania ogólne: zgodność z EN ISO 20345, gwarancja 24 miesiące.';
        $rows = app(NoticeItemsReader::class)->verify([
            ['lot_no' => 1, 'name' => 'Rękawice ochronne', 'quantity' => 100, 'unit' => 'par', 'quote' => '- rękawice ochronne nitrylowe, EN 388:2016 4121X, długość 30 cm – 100 par', 'bhp' => true,
                'spec' => [
                    'nitrylowe',
                    'EN 388:2016 4121X',
                    // inna liczba niż w ogłoszeniu — odrzucone, nie „poprawione”
                    'długość 31 cm',
                    // cecha butów (następna pozycja) — poza oknem rękawic
                    'S3 SRC',
                    // zawarte w nazwie — bez powtórzenia
                    'ochronne',
                ]],
            ['lot_no' => 1, 'name' => 'Buty robocze', 'quantity' => 10, 'unit' => 'par', 'quote' => 'buty robocze S3 SRC, rozmiar 42 – 10 par', 'bhp' => true,
                // „S3”, „SRC”, „42” identyczne, słowa po rdzeniu; „S30” nie pasuje do „S3”; „nitrylowe” — cecha rękawic
                // (przed oknem butów) odpada
                'spec' => ['S3 SRC', 'rozmiarze 42', 'S30', 'nitrylowe']],
            // ostatnia pozycja: okno do końca tekstu — wymagania ogólne całego zamówienia nie są w spec, bo model ich nie
            // podał; cechy jednym napisem (nie listą) — jeden fragment, bez dzielenia po przecinkach
            ['lot_no' => 2, 'name' => 'Kurtka zimowa', 'quantity' => 5, 'unit' => 'szt.', 'quote' => 'kurtka zimowa kolor granatowy, z kapturem – 5 szt.', 'bhp' => true,
                'spec' => 'kolor granatowy, z kapturem'],
            // cytatu nie ma w ogłoszeniu — bez cech, nawet gdy fragment jest w tekście
            ['lot_no' => 1, 'name' => 'Okulary ochronne', 'quantity' => 3, 'unit' => 'szt.', 'quote' => 'okulary ochronne – 3 szt.', 'bhp' => true,
                'spec' => ['EN 388:2016']],
        ], $text, [1, 2]);

        $this->assertSame(['nitrylowe', 'EN 388:2016 4121X'], $rows[0]['spec_fragments']);
        $this->assertSame('nitrylowe; EN 388:2016 4121X', $rows[0]['spec']);
        // oryginalna pisownia z ogłoszenia („rozmiar 42”), nie sformułowanie modelu („rozmiarze 42”)
        $this->assertSame(['S3 SRC', 'rozmiar 42'], $rows[1]['spec_fragments']);
        $this->assertSame(['kolor granatowy, z kapturem'], $rows[2]['spec_fragments']);
        $this->assertNull($rows[3]['spec']);
        $this->assertSame([], $rows[3]['spec_fragments']);
        $this->assertFalse($rows[3]['quote_found']);
        // jednostka tylko z cytatu znalezionego w ogłoszeniu
        $this->assertSame(['par', 'par', 'szt.', null], array_column($rows, 'unit'));
    }

    public function test_loose_match_keeps_numbers_and_order(): void
    {
        $text = "1. Rękawice robocze wykonane z dzianiny powlekanej lateksem, rozmiar 9 i 10 – 200 par.\n"
            .'2. Kamizelka ostrzegawcza żółta – 40 szt.';
        $rows = app(NoticeItemsReader::class)->verify([
            ['lot_no' => null, 'name' => 'Rękawice robocze', 'quantity' => 200, 'unit' => 'par', 'quote' => 'Rękawice robocze wykonane z dzianiny powlekanej lateksem, rozmiar 9 i 10 – 200 par', 'bhp' => true,
                'spec' => [
                    // inna odmiana, ta sama kolejność — wycinek z ogłoszenia
                    'z dzianiny powlekanej lateksem',
                    'wykonana z dzianiny powlekana lateksem',
                    // inne liczby — odrzucone
                    'rozmiar 8 i 10',
                    // odwrócona kolejność — odrzucone
                    'lateksem powlekanej',
                ]],
        ], $text, []);

        $this->assertSame(['z dzianiny powlekanej lateksem', 'wykonane z dzianiny powlekanej lateksem'], $rows[0]['spec_fragments']);
    }

    private function lotNotice(): ProcurementNotice
    {
        $notice = $this->fixtureNotice('contract-lots');
        $parsed = $notice->parsed;
        $parsed['lots'] = [[
            'lot_no' => 6,
            'name' => 'Część 6: zasoby ochrony ludności',
            'description' => self::LOT_TEXT,
        ]];
        $notice->forceFill(['parsed' => $parsed, 'html_body' => null])->save();

        return $notice;
    }

    /** @return array{content: string} */
    private function answer(): array
    {
        return ['content' => json_encode(['items' => [
            ['lot_no' => 6, 'name' => 'Hełm strażacki', 'spec' => ['EN 443:2008', 'kolor biały'], 'quantity' => 23, 'unit' => 'szt.', 'quote' => 'hełm strażacki zgodny z EN 443:2008, kolor biały – 23 szt.', 'bhp' => true],
            ['lot_no' => 6, 'name' => 'Rękawice specjalne', 'spec' => ['skórzana', 'EN 659', 'rozmiar 10'], 'quantity' => 46, 'unit' => 'par', 'quote' => 'rękawice specjalne skórzane, EN 659, rozmiar 10 – 46 par', 'bhp' => true],
            ['lot_no' => 6, 'name' => 'Agregat prądotwórczy', 'spec' => ['5 kW', 'EN 443'], 'quantity' => 2, 'unit' => 'szt.', 'quote' => 'agregat prądotwórczy 5 kW – 2 szt.', 'bhp' => false],
        ]], JSON_UNESCAPED_UNICODE)];
    }

    private function fixtureNotice(string $name): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path("tests/Fixtures/bzp/{$name}.json")), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }
}
