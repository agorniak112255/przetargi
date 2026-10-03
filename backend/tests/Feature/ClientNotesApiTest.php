<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Notatki na karcie klienta: dodaje każdy z clients.view, zmienia i usuwa autor albo clients.manage, notatka innego
 * klienta = 404, przypomnienie najwcześniej dziś (czas polski), zmiana dnia zeruje reminded_at.
 */
final class ClientNotesApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // 2.10.2026 23:30 UTC = sobota 3.10.2026 01:30 w Polsce — „dziś” to już 3 października
        $this->travelTo(CarbonImmutable::parse('2026-10-02 23:30', 'UTC'));
        $this->client = Client::query()->create(['name' => 'Ciepłownia Wisłok S.A.']);
    }

    public function test_user_with_card_access_adds_note_with_reminder_not_in_the_past(): void
    {
        $me = $this->userWith(['clients.view']);
        Sanctum::actingAs($me);

        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => '  W listopadzie przetarg na odzież zimową.  ', 'remind_on' => '2026-10-03'])
            ->assertCreated()
            ->assertJsonPath('type', 'note')
            ->assertJsonPath('body', 'W listopadzie przetarg na odzież zimową.')
            ->assertJsonPath('author', ['id' => $me->id, 'name' => $me->name])
            ->assertJsonPath('remind_on', '2026-10-03')
            ->assertJsonPath('can_edit', true);
        $note = ClientNote::query()->firstOrFail();
        $this->assertSame($me->id, $note->user_id);
        $this->assertSame($this->client->id, $note->client_id);

        // wczoraj w Polsce (choć w UTC to jeszcze „dziś”) — odmowa
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => 'Telefon', 'remind_on' => '2026-10-02'])
            ->assertStatus(422)->assertJsonValidationErrors('remind_on');
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => 'Telefon', 'remind_on' => '03.10.2026'])
            ->assertStatus(422)->assertJsonValidationErrors('remind_on');
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => '   '])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => str_repeat('a', 5001)])->assertStatus(422)->assertJsonValidationErrors('body');
        // bez przypomnienia
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => 'Bez przypomnienia', 'remind_on' => null])
            ->assertCreated()->assertJsonPath('remind_on', null);
        $this->assertSame(2, ClientNote::query()->count());

        Sanctum::actingAs($this->userWith(['inquiries.use']));
        $this->postJson("/api/clients/{$this->client->id}/notes", ['body' => 'Obcy'])->assertForbidden();
    }

    public function test_only_author_or_clients_manage_edits_and_deletes(): void
    {
        $author = $this->userWith(['clients.view']);
        $note = $this->note($author, 'Zadzwonić w piątek');

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->patchJson($this->url($note), ['body' => 'Zmiana'])->assertForbidden();
        $this->deleteJson($this->url($note))->assertForbidden();
        $this->getJson("/api/clients/{$this->client->id}/timeline?type=notes")->assertOk()->assertJsonPath('data.0.can_edit', false);

        Sanctum::actingAs($author);
        $this->patchJson($this->url($note), ['body' => 'Zadzwonić w poniedziałek'])->assertOk()
            ->assertJsonPath('body', 'Zadzwonić w poniedziałek')
            ->assertJsonPath('can_edit', true);

        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage']));
        $this->patchJson($this->url($note), ['body' => 'Poprawione przez kierownika'])->assertOk();
        // autor zostaje autorem
        $this->assertSame($author->id, $note->fresh()->user_id);
        $this->deleteJson($this->url($note))->assertOk()->assertJsonPath('ok', true);
        $this->assertNull(ClientNote::query()->find($note->id));
    }

    public function test_note_must_belong_to_the_client_in_the_address(): void
    {
        $author = $this->userWith(['clients.view', 'clients.manage']);
        $note = $this->note($author, 'Notatka');
        $other = Client::query()->create(['name' => 'Inny']);

        Sanctum::actingAs($author);
        $this->patchJson("/api/clients/{$other->id}/notes/{$note->id}", ['body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/clients/{$other->id}/notes/{$note->id}")->assertNotFound();
        $this->assertSame('Notatka', $note->fresh()->body);
    }

    public function test_changing_reminder_day_clears_reminded_at_and_unchanged_past_day_is_kept(): void
    {
        $author = $this->userWith(['clients.view']);
        $note = $this->note($author, 'Oferta na rękawice', ['remind_on' => '2026-09-30', 'reminded_at' => CarbonImmutable::parse('2026-09-30 05:00', 'UTC')]);
        Sanctum::actingAs($author);

        // sama treść — dawne przypomnienie zostaje, także gdy formularz odeśle ten sam dzień
        $this->patchJson($this->url($note), ['body' => 'Oferta na rękawice nitrylowe', 'remind_on' => '2026-09-30'])->assertOk()
            ->assertJsonPath('remind_on', '2026-09-30');
        $this->assertNotNull($note->fresh()->reminded_at);

        // nowy dzień w przeszłości — odmowa
        $this->patchJson($this->url($note), ['remind_on' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('remind_on');

        $this->patchJson($this->url($note), ['remind_on' => '2026-10-10'])->assertOk()->assertJsonPath('remind_on', '2026-10-10');
        $fresh = $note->fresh();
        $this->assertNull($fresh->reminded_at);
        $this->assertSame('Oferta na rękawice nitrylowe', $fresh->body);

        $this->patchJson($this->url($note), ['remind_on' => null])->assertOk()->assertJsonPath('remind_on', null);
    }

    private function url(ClientNote $note): string
    {
        return "/api/clients/{$this->client->id}/notes/{$note->id}";
    }

    /** @param  array<string, mixed>  $extra */
    private function note(User $author, string $body, array $extra = []): ClientNote
    {
        return ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => $author->id, 'body' => $body, ...$extra]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('notes-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
