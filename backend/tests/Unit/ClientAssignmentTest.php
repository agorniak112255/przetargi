<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Opiekun klienta: pracownik XL zmapowany na konto, potem opiekun w aplikacji, potem nikt. */
final class ClientAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_xl_employee_mapped_to_account_wins_then_app_owner_then_none(): void
    {
        $anna = User::factory()->create();
        $anna->forceFill(['erp_employee_gid' => 501])->save();
        $owner = User::factory()->create();

        $xl = Client::query()->create(['name' => 'Z XL', 'xl_manager_gid' => 501, 'owner_id' => $owner->id]);
        // pracownik XL bez konta w aplikacji — liczy się opiekun w aplikacji
        $unmapped = Client::query()->create(['name' => 'Niezmapowany', 'xl_manager_gid' => 999, 'owner_id' => $owner->id]);
        $app = Client::query()->create(['name' => 'Ręczny', 'owner_id' => $owner->id]);
        $none = Client::query()->create(['name' => 'Bez opiekuna', 'xl_manager_gid' => 999]);

        $all = (new ClientAssignment)->forClients();

        $this->assertSame(['user_id' => $anna->id, 'source' => 'xl'], $all[$xl->id]);
        $this->assertSame(['user_id' => $owner->id, 'source' => 'app'], $all[$unmapped->id]);
        $this->assertSame(['user_id' => $owner->id, 'source' => 'app'], $all[$app->id]);
        $this->assertSame(['user_id' => null, 'source' => null], $all[$none->id]);
        $this->assertCount(4, $all);
    }

    public function test_only_requested_clients_are_returned(): void
    {
        $a = Client::query()->create(['name' => 'A']);
        $b = Client::query()->create(['name' => 'B']);

        $some = (new ClientAssignment)->forClients([$b->id, $b->id, 999999]);

        $this->assertSame([$b->id], array_keys($some));
        $this->assertSame([], (new ClientAssignment)->forClients([]));
        $this->assertArrayHasKey($a->id, (new ClientAssignment)->forClients(null));
    }

    public function test_current_mapping_is_used(): void
    {
        $first = User::factory()->create();
        $first->forceFill(['erp_employee_gid' => 501])->save();
        $client = Client::query()->create(['name' => 'Z XL', 'xl_manager_gid' => 501]);
        $this->assertSame($first->id, (new ClientAssignment)->forClients([$client->id])[$client->id]['user_id']);

        // pracownik przepięty na inne konto — klient idzie za dzisiejszym przypisaniem
        $first->forceFill(['erp_employee_gid' => null])->save();
        $second = User::factory()->create();
        $second->forceFill(['erp_employee_gid' => 501])->save();
        $this->assertSame($second->id, (new ClientAssignment)->forClients([$client->id])[$client->id]['user_id']);
    }
}
