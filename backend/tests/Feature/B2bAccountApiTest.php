<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class B2bAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_creates_account_and_password_is_encrypted_and_hidden(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $id = $this->postJson('/api/b2b-accounts', [
            'username' => 'jan.kowalski',
            'password' => 'tajne-haslo-1',
            'sites' => [' b2b.example.test ', 'b2b.example.test', ''],
            'note' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('username', 'jan.kowalski')
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('sites', ['b2b.example.test'])
            ->assertJsonMissingPath('password')
            ->json('id');

        $raw = DB::table('b2b_accounts')->where('id', $id)->value('password');
        $this->assertNotSame('tajne-haslo-1', $raw);
        $this->assertSame('tajne-haslo-1', Crypt::decryptString((string) $raw));

        $this->getJson('/api/b2b-accounts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.password');

        $this->postJson("/api/b2b-accounts/{$id}/password")
            ->assertOk()
            ->assertJsonPath('password', 'tajne-haslo-1');

        $this->deleteJson("/api/b2b-accounts/{$id}")->assertOk();
        $this->assertSame(0, B2bAccount::query()->count());
    }

    public function test_update_without_password_keeps_saved_password(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $account = B2bAccount::query()->create([
            'username' => 'stary',
            'password' => 'haslo-bez-zmian',
            'sites' => ['b2b.example.test'],
        ]);

        $this->patchJson("/api/b2b-accounts/{$account->id}", [
            'username' => 'nowy',
            'password' => '',
            'sites' => ['b2b.example.test', 'sklep.example.test'],
            'note' => 'Konto działu zakupów',
        ])
            ->assertOk()
            ->assertJsonPath('username', 'nowy')
            ->assertJsonPath('note', 'Konto działu zakupów')
            ->assertJsonPath('sites', ['b2b.example.test', 'sklep.example.test']);

        $this->assertSame('haslo-bez-zmian', $account->fresh()->password);
    }

    public function test_create_requires_password_and_site(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/b2b-accounts', [
            'username' => 'jan',
            'sites' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'sites']);
    }

    public function test_user_without_b2b_permission_cannot_list_or_reveal(): void
    {
        $account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => ['b2b.example.test'],
        ]);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $this->getJson('/api/b2b-accounts')->assertForbidden();
        $this->postJson("/api/b2b-accounts/{$account->id}/password")->assertForbidden();
        $this->postJson('/api/b2b-accounts', [
            'username' => 'x',
            'password' => 'y',
            'sites' => ['b2b.example.test'],
        ])->assertForbidden();
    }
}
