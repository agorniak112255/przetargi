<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Numer wewnętrzny przetargu „PRZ/RRRR/NNNN” przy zakładaniu (POST /api/tenders): rok w czasie polskim i ponowienie
 * z następnym numerem, gdy równoległy zapis zajął wyliczony numer.
 */
final class TenderNumberTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->client = Client::query()->create(['name' => 'Szpital Wojewódzki']);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_year_is_taken_from_polish_time(): void
    {
        // 31.12.2026 23:30 UTC = 01.01.2027 00:30 w Polsce
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));

        $this->postJson('/api/tenders', ['title' => 'Rękawice', 'client_id' => $this->client->id])
            ->assertCreated()
            ->assertJsonPath('number', 'PRZ/2027/0001');
    }

    public function test_taken_number_is_retried_with_the_next_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
        $clientId = $this->client->id;
        $raced = false;
        Tender::creating(static function (Tender $tender) use (&$raced, $clientId): void {
            if ($raced) {
                return;
            }
            $raced = true;
            // równoległe założenie przetargu z tym samym numerem tuż przed naszym zapisem
            DB::table('tenders')->insert([
                'number' => $tender->number,
                'title' => 'Założony w tej samej chwili',
                'client_id' => $clientId,
                'status' => 'draft',
                'ai_percent' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/tenders', ['title' => 'Rękawice', 'client_id' => $this->client->id])
            ->assertCreated()
            ->assertJsonPath('number', 'PRZ/2026/0002');
        $this->assertSame(['PRZ/2026/0001', 'PRZ/2026/0002'], Tender::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_number_given_by_hand_is_validated_not_retried(): void
    {
        Tender::query()->create(['number' => 'WŁASNY/1', 'title' => 'Pierwszy', 'client_id' => $this->client->id, 'status' => 'draft', 'ai_percent' => 0]);

        $this->postJson('/api/tenders', ['title' => 'Rękawice', 'client_id' => $this->client->id, 'number' => 'WŁASNY/1'])
            ->assertJsonValidationErrors('number');
        $this->assertSame(1, Tender::query()->count());
    }

    public function test_next_number_after_a_taken_one_skips_past_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));

        $this->assertSame('PRZ/2026/0001', Tender::nextNumber());
        // odczyt nie widzi zajętego numeru (np. transakcja równoległa) — wynik i tak jest od niego większy
        $this->assertSame('PRZ/2026/0006', Tender::nextNumber('PRZ/2026/0005'));
        // numer z innego roku nie przesuwa licznika
        $this->assertSame('PRZ/2026/0001', Tender::nextNumber('PRZ/2025/0005'));
    }
}
