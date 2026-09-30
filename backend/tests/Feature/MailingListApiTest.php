<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\MailingList;
use App\Models\MailingListContact;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Grupy odbiorców kampanii: własne i wspólne, kontakty i import z wklejonego tekstu. */
final class MailingListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_own_and_shared_lists_and_who_edits_shared(): void
    {
        $a = User::factory()->withRole('handlowiec')->create();
        $b = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create();
        $foreign = MailingList::query()->create(['user_id' => $b->id, 'name' => 'Prywatna B']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/mailing-lists')->assertForbidden();

        Sanctum::actingAs($a);
        $this->postJson('/api/mailing-lists', ['name' => 'Wspólna', 'is_shared' => true])->assertForbidden();
        $own = $this->postJson('/api/mailing-lists', ['name' => 'Moi klienci'])->assertCreated()
            ->assertJsonPath('is_shared', false)
            ->assertJsonPath('owner', ['id' => $a->id, 'name' => $a->name])
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('contacts_count', 0)
            ->assertJsonPath('basis_counts', ['customer' => 0, 'consent' => 0])
            ->json('id');
        $this->patchJson("/api/mailing-lists/{$own}", ['is_shared' => true])->assertForbidden();
        $this->patchJson("/api/mailing-lists/{$foreign->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/mailing-lists/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/mailing-lists/{$foreign->id}/contacts")->assertNotFound();

        Sanctum::actingAs($admin);
        $shared = $this->postJson('/api/mailing-lists', ['name' => 'Wspólna', 'is_shared' => true])->assertCreated()->json('id');

        Sanctum::actingAs($a);
        $rows = collect($this->getJson('/api/mailing-lists')->assertOk()->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$own, $shared], $rows->keys()->all());
        $this->assertFalse($rows[$shared]['can_edit']);
        $this->assertTrue($rows[$own]['can_edit']);
        $this->patchJson("/api/mailing-lists/{$shared}", ['name' => 'Zmiana'])->assertForbidden();
        $this->deleteJson("/api/mailing-lists/{$shared}")->assertForbidden();
        $this->postJson("/api/mailing-lists/{$shared}/import", ['text' => 'a@x.pl', 'basis' => 'customer'])->assertForbidden();
        $this->getJson("/api/mailing-lists/{$shared}/contacts")->assertOk();
        $this->patchJson("/api/mailing-lists/{$own}", ['name' => 'Nowa nazwa'])->assertOk()->assertJsonPath('name', 'Nowa nazwa');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/mailing-lists/{$shared}", ['name' => 'Wspólna 2'])->assertOk()->assertJsonPath('can_edit', true);
        $this->deleteJson("/api/mailing-lists/{$shared}")->assertOk();
        $this->assertNull(MailingList::query()->find($shared));
    }

    public function test_import_parses_lines_and_does_not_overwrite(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $list = MailingList::query()->create(['user_id' => $user->id, 'name' => 'Klienci']);
        $existing = Contact::query()->create(['email' => 'stary@firma.pl', 'name' => 'Stare Imię', 'company' => null]);
        $onList = Contact::query()->create(['email' => 'jest@firma.pl']);
        $list->contacts()->attach($onList->id, ['basis' => MailingListContact::BASIS_CONSENT, 'basis_note' => 'www']);
        EmailSuppression::query()->create(['email' => 'wypisany@firma.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);

        $text = implode("\n", [
            'Jan.Kowalski@Firma.pl;Jan Kowalski;Firma Sp. z o.o.',
            "Anna Nowak\tanna@budowa.pl\tBudowa SA",
            'sam@adres.pl',
            '',
            'stary@firma.pl;Nowe Imię;Nowa Firma',
            'jest@firma.pl',
            'wypisany@firma.pl',
            'jan.kowalski@firma.pl',
            'to nie jest adres',
            'zly@@adres;Kto',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/mailing-lists/{$list->id}/import", ['text' => $text, 'basis' => 'zle'])->assertUnprocessable();
        $this->postJson("/api/mailing-lists/{$list->id}/import", ['text' => $text, 'basis' => 'customer', 'basis_note' => 'z XL'])
            ->assertOk()
            ->assertExactJson([
                'added' => 5,
                // już w grupie + powtórzony w tekście
                'already' => 2,
                'invalid' => ['to nie jest adres', 'zly@@adres;Kto'],
                'suppressed' => 1,
            ]);

        $jan = Contact::query()->where('email', 'jan.kowalski@firma.pl')->firstOrFail();
        $this->assertSame(['Jan Kowalski', 'Firma Sp. z o.o.'], [$jan->name, $jan->company]);
        $anna = Contact::query()->where('email', 'anna@budowa.pl')->firstOrFail();
        $this->assertSame(['Anna Nowak', 'Budowa SA'], [$anna->name, $anna->company]);
        // istniejące imię zostaje, puste pole firmy uzupełnione
        $existing->refresh();
        $this->assertSame(['Stare Imię', 'Nowa Firma'], [$existing->name, $existing->company]);
        // kontakt już w grupie zachowuje podstawę
        $pivot = MailingListContact::query()->where('mailing_list_id', $list->id)->where('contact_id', $onList->id)->firstOrFail();
        $this->assertSame(['consent', 'www'], [$pivot->basis, $pivot->basis_note]);
        $new = MailingListContact::query()->where('mailing_list_id', $list->id)->where('contact_id', $jan->id)->firstOrFail();
        $this->assertSame(['customer', 'z XL', $user->id], [$new->basis, $new->basis_note, (int) $new->added_by]);

        $res = $this->getJson('/api/mailing-lists')->assertOk();
        $this->assertSame(6, $res->json('data.0.contacts_count'));
        $this->assertSame(['customer' => 5, 'consent' => 1], $res->json('data.0.basis_counts'));

        $contacts = $this->getJson("/api/mailing-lists/{$list->id}/contacts?search=wypis")->assertOk()->json('data');
        $this->assertCount(1, $contacts);
        $this->assertSame(['id', 'email', 'name', 'company', 'basis', 'basis_note', 'added_at', 'suppressed'], array_keys($contacts[0]));
        $this->assertTrue($contacts[0]['suppressed']);
        $this->assertSame(6, $this->getJson("/api/mailing-lists/{$list->id}/contacts")->json('meta.total'));
    }

    public function test_parallel_import_of_the_same_address_does_not_fail(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $list = MailingList::query()->create(['user_id' => $user->id, 'name' => 'Moi klienci']);
        Sanctum::actingAs($user);

        // drugi import tego samego adresu kończy się między odczytem a zapisem tego importu: najpierw kontakt, potem
        // wiersz w grupie
        $armed = ['contact' => true, 'pivot' => true];
        DB::listen(static function (QueryExecuted $query) use (&$armed, $list, $user): void {
            if ($armed['contact'] && str_contains($query->sql, 'from "contacts"')) {
                $armed['contact'] = false;
                DB::table('contacts')->insert(['email' => 'rownolegly@x.pl', 'name' => 'Z drugiego importu', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($armed['pivot'] && str_contains($query->sql, 'from "mailing_list_contact"')) {
                $armed['pivot'] = false;
                DB::table('mailing_list_contact')->insert([
                    'mailing_list_id' => $list->id, 'contact_id' => DB::table('contacts')->where('email', 'rownolegly@x.pl')->value('id'),
                    'basis' => MailingListContact::BASIS_CONSENT, 'added_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->postJson("/api/mailing-lists/{$list->id}/import", ['text' => 'rownolegly@x.pl;Jan Nowak;Firma', 'basis' => 'customer'])
            ->assertOk()
            ->assertJsonPath('added', 0)
            ->assertJsonPath('already', 1);

        $this->assertFalse($armed['contact'] || $armed['pivot']);
        $contact = Contact::query()->sole();
        $this->assertSame('Z drugiego importu', $contact->name);
        $this->assertSame(MailingListContact::BASIS_CONSENT, MailingListContact::query()->sole()->basis);
    }

    public function test_import_limits(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $list = MailingList::query()->create(['user_id' => $user->id, 'name' => 'Klienci']);

        Sanctum::actingAs($user);
        $tooMany = implode("\n", array_map(static fn (int $i): string => "a{$i}@x.pl", range(1, 5001)));
        $this->postJson("/api/mailing-lists/{$list->id}/import", ['text' => $tooMany, 'basis' => 'consent'])
            ->assertUnprocessable()->assertJsonValidationErrors('text');

        $bad = implode("\n", array_map(static fn (int $i): string => "zly{$i}", range(1, 80)));
        $res = $this->postJson("/api/mailing-lists/{$list->id}/import", ['text' => $bad, 'basis' => 'consent'])->assertOk();
        $this->assertCount(50, $res->json('invalid'));
        $this->assertSame(0, $res->json('added'));
    }

    public function test_remove_contact(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $list = MailingList::query()->create(['user_id' => $user->id, 'name' => 'Klienci']);
        $contact = Contact::query()->create(['email' => 'a@x.pl']);
        $outside = Contact::query()->create(['email' => 'b@x.pl']);
        $list->contacts()->attach($contact->id, ['basis' => 'customer']);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->deleteJson("/api/mailing-lists/{$list->id}/contacts/{$contact->id}")->assertNotFound();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/mailing-lists/{$list->id}/contacts/{$outside->id}")->assertNotFound();
        $this->deleteJson("/api/mailing-lists/{$list->id}/contacts/{$contact->id}")->assertOk();
        $this->assertSame(0, $list->contacts()->count());
        // kontakt zostaje (może być w innych grupach)
        $this->assertNotNull(Contact::query()->find($contact->id));
    }
}
