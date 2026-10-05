<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\User;
use App\Services\B2b\SirB2bConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Stan systemu › „Kolejki teraz”. 05.10.2026: opisy cennika Ansella stały 10 minut, bo wszystkich 16 pracowników kolejki
 * „enrich” zajęły tłumaczenia kart SIR — panel ma pokazać, kto zajmuje kolejkę i czyja praca czeka.
 */
final class SystemQueuesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_needs_admin_access_and_system_view(): void
    {
        Sanctum::actingAs($this->userWith(['admin.access']));
        $this->getJson('/api/admin/system-status/queues')->assertForbidden();

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $this->getJson('/api/admin/system-status/queues')->assertOk()
            ->assertJsonStructure(['checked_at', 'queues' => [['key', 'label', 'running', 'waiting', 'jobs']], 'batches', 'failed_24h']);
    }

    public function test_shows_who_occupies_the_queue_and_whose_work_waits(): void
    {
        $sir = B2bAccount::query()->create(['username' => 'sir-konto', 'password' => 'x', 'sites' => ['b2b.sir.example'], 'connector' => SirB2bConnector::key()]);
        $sirCard = $this->product('SIR Safety System');
        $ansell = $this->product('Ansell');
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS, 'scope_id' => 1, 'total' => 319, 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'force' => true, 'message' => 'Prefetch · wyszukiwarka…',
        ]);
        $queue = Queue::connection('database');
        for ($i = 0; $i < 3; $i++) {
            $queue->push(new TranslateB2bProductTextJob((int) $sirCard->id, (int) $sir->id), '', TranslateB2bProductTextJob::QUEUE);
        }
        $queue->push(new EnrichProductJob((int) $ansell->id, (int) $batch->id, true), '', EnrichProductJob::QUEUE);
        // dwa tłumaczenia w trakcie, jedno od 10 minut — dłużej niż limit zadania (300 s)
        $ids = DB::table('jobs')->where('queue', 'enrich')->orderBy('id')->pluck('id')->all();
        DB::table('jobs')->where('id', $ids[0])->update(['reserved_at' => now()->subMinutes(10)->getTimestamp(), 'attempts' => 1]);
        DB::table('jobs')->where('id', $ids[1])->update(['reserved_at' => now()->subSeconds(20)->getTimestamp(), 'attempts' => 1]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'prefetch',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\PrefetchProductSourcesJob']),
            'exception' => "Illuminate\\Queue\\TimeoutExceededException: PrefetchProductSourcesJob has timed out.\n#0 trace",
            'failed_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->userWith(['admin.access', 'admin.system.view']));
        $data = $this->getJson('/api/admin/system-status/queues')->assertOk()->json();

        $enrich = collect($data['queues'])->firstWhere('key', 'enrich');
        $this->assertSame(2, $enrich['running']);
        $this->assertSame(2, $enrich['waiting']);
        $this->assertSame(1, $enrich['over_timeout']);
        $this->assertGreaterThanOrEqual(590, $enrich['longest_running_seconds']);
        $translate = $enrich['jobs'][0];
        $this->assertSame('Tłumaczenie karty dostawcy', $translate['label']);
        $this->assertSame([2, 1], [$translate['running'], $translate['waiting']]);
        $this->assertSame([['label' => SirB2bConnector::label(), 'count' => 3]], $translate['sources']);
        $describe = collect($enrich['jobs'])->firstWhere('type', 'EnrichProductJob');
        $this->assertSame([0, 1], [$describe['running'], $describe['waiting']]);
        $this->assertSame([['label' => 'Ansell', 'count' => 1]], $describe['sources']);

        $this->assertSame(0, collect($data['queues'])->firstWhere('key', 'prefetch')['waiting'], 'pusta kolejka też jest na liście');
        $this->assertSame([$batch->id, 319, 'Prefetch · wyszukiwarka…'], [$data['batches'][0]['id'], $data['batches'][0]['total'], $data['batches'][0]['message']]);
        $this->assertSame('Wyszukiwanie stron przed opisem', $data['failed_24h'][0]['label']);
        $this->assertSame(1, $data['failed_24h'][0]['count']);
        $this->assertStringContainsString('has timed out', $data['failed_24h'][0]['last_error']);
    }

    private function product(string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => 'K-'.Str::random(6), 'name' => 'Rękawice', 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('kolejki-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
