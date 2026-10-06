<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Widoczność ofert (decyzja właściciela 06.10.2026): swoje zawsze; offers.view_all — wszystkie; offers.view_selected —
 * osób wybranych w ustawieniach roli. Cudze oferty tylko do podglądu (zmiana, wysyłka, usunięcie: 403).
 */
final class OfferVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $bartek;

    private User $celina;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['offers.use', 'offers.view_all', 'offers.view_selected', 'admin.access', 'admin.roles.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->anna = $this->user('Anna');
        $this->bartek = $this->user('Bartek');
        $this->celina = $this->user('Celina');
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo('offers.use');

        return $user;
    }

    private function offer(User $author, string $subject): Offer
    {
        return Offer::query()->create(['user_id' => $author->id, 'subject' => $subject]);
    }

    /** @param  list<int>  $visible */
    private function role(string $name, array $permissions, array $visible = []): Role
    {
        $role = Role::query()->create(['name' => $name, 'display_name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $role->forceFill(['offer_visible_user_ids' => $visible])->save();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $role;
    }

    /** @return list<string> */
    private function listedSubjects(): array
    {
        $subjects = array_column($this->getJson('/api/offers')->assertOk()->json('data'), 'subject');
        sort($subjects);

        return $subjects;
    }

    public function test_without_visibility_permission_only_own_offers(): void
    {
        $this->offer($this->anna, 'Anny');
        $foreign = $this->offer($this->bartek, 'Bartka');
        Sanctum::actingAs($this->anna);

        $this->assertSame(['Anny'], $this->listedSubjects());
        $this->getJson("/api/offers/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/offers/{$foreign->id}/preview")->assertNotFound();
    }

    public function test_view_all_sees_everyone_read_only(): void
    {
        $this->offer($this->anna, 'Anny');
        $foreign = $this->offer($this->bartek, 'Bartka');
        $this->offer($this->celina, 'Celiny');
        $this->anna->assignRole($this->role('kierownik-ofert', ['offers.view_all']));
        Sanctum::actingAs($this->anna->fresh());

        $this->assertSame(['Anny', 'Bartka', 'Celiny'], $this->listedSubjects());
        $row = collect($this->getJson('/api/offers')->json('data'))->firstWhere('id', $foreign->id);
        $this->assertFalse($row['can_edit']);
        $this->assertSame('Bartek', $row['author']['name']);

        $this->getJson("/api/offers/{$foreign->id}")->assertOk()
            ->assertJsonPath('can_edit', false)->assertJsonPath('author.name', 'Bartek');
        $this->getJson("/api/offers/{$foreign->id}/preview")->assertOk();
        // tylko autor zmienia, wysyła i usuwa
        $this->patchJson("/api/offers/{$foreign->id}", ['subject' => 'X'])->assertForbidden();
        $this->postJson("/api/offers/{$foreign->id}/send", ['emails' => ['k@klient.pl']])->assertForbidden();
        $this->deleteJson("/api/offers/{$foreign->id}")->assertForbidden();
        $this->assertSame('Bartka', $foreign->fresh()->subject);
    }

    public function test_view_selected_sees_only_people_chosen_in_role(): void
    {
        $this->offer($this->anna, 'Anny');
        $this->offer($this->bartek, 'Bartka');
        $hidden = $this->offer($this->celina, 'Celiny');
        $this->anna->assignRole($this->role('zespol-anny', ['offers.view_selected'], [$this->bartek->id]));
        // lista osób w roli BEZ uprawnienia nic nie daje
        $this->anna->assignRole($this->role('bez-uprawnienia', [], [$this->celina->id]));
        Sanctum::actingAs($this->anna->fresh());

        $this->assertSame(['Anny', 'Bartka'], $this->listedSubjects());
        $this->getJson("/api/offers/{$hidden->id}")->assertNotFound();
    }

    public function test_role_api_saves_people_whose_offers_role_sees(): void
    {
        $role = $this->role('zespol', ['offers.view_selected']);
        $admin = User::factory()->create();
        $admin->givePermissionTo(['admin.access', 'admin.roles.manage']);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/roles/zespol/offer-viewers', ['user_ids' => [$this->celina->id, $this->bartek->id]])
            ->assertOk()
            ->assertJsonPath('offer_visible_user_ids', [$this->bartek->id, $this->celina->id]);
        $this->patchJson('/api/admin/roles/zespol/offer-viewers', ['user_ids' => [999999]])->assertStatus(422);

        $index = $this->getJson('/api/admin/roles')->assertOk();
        $this->assertSame([$this->bartek->id, $this->celina->id], collect($index->json('roles'))->firstWhere('name', 'zespol')['offer_visible_user_ids']);
        $this->assertContains('Bartek', array_column($index->json('users'), 'name'));
        $this->assertSame([$this->bartek->id, $this->celina->id], $role->fresh()->offer_visible_user_ids);
    }
}
