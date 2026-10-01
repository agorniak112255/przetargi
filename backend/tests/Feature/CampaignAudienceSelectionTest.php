<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Wybór adresów z grup (list_exclusions), lista odbiorców przed wysyłką i start z sumą kontrolną tej listy. */
final class CampaignAudienceSelectionTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    public function test_patch_list_exclusions_normalizes_validates_and_skips_deleted_list(): void
    {
        $author = $this->sender();
        $own = $this->mailingList($author, ['a@x.pl', 'b@x.pl', 'c@x.pl'], name: 'Moja');
        $shared = $this->mailingList(User::factory()->create(), ['d@x.pl'], shared: true, name: 'Wspólna');
        $foreign = $this->mailingList(User::factory()->create(), ['e@x.pl'], name: 'Cudza');
        $deleted = $this->mailingList($author, [], name: 'Usunięta');
        $deletedId = $deleted->id;
        $deleted->delete();
        [$a, $b] = [$this->contactId('a@x.pl'), $this->contactId('b@x.pl')];
        $campaign = $this->campaign($author, [], ['audience' => ['xl' => ['mode' => 'items', 'months' => 12]]]);
        Sanctum::actingAs($author);

        // usunięta grupa po cichu wypada; wpisy: powtórzone id raz, rosnąco; pusty wpis i grupa spoza wyboru znikają
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => [
            'list_ids' => [$own->id, $shared->id, $deletedId],
            'list_exclusions' => [
                ['list_id' => $own->id, 'contact_ids' => [$b, $a, $b]],
                ['list_id' => $shared->id, 'contact_ids' => []],
                ['list_id' => $foreign->id, 'contact_ids' => [$a]],
            ],
        ]])->assertOk()->assertJsonPath('audience', [
            'list_ids' => [$own->id, $shared->id],
            'list_exclusions' => [['list_id' => $own->id, 'contact_ids' => [$a, $b]]],
            'xl' => ['mode' => 'items', 'months' => 12, 'only_mine' => false, 'customer_ids' => null],
        ]);

        // istniejąca cudza prywatna grupa — błąd, nic się nie zmienia
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$own->id, $foreign->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.list_ids');
        $this->assertSame([$own->id, $shared->id], $campaign->fresh()->audienceSettings()['list_ids']);

        // zmiana samych grup: odznaczenia grupy, której już nie ma w wyborze, znikają
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$shared->id]]])
            ->assertOk()->assertJsonPath('audience.list_exclusions', []);
        // ponowne zaznaczenie grupy — znowu cała grupa
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$shared->id, $own->id]]])
            ->assertOk()->assertJsonPath('audience.list_exclusions', []);

        // sam klucz list_exclusions zastępuje całość, grupy zostają
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => [['list_id' => $own->id, 'contact_ids' => [$a]]]]])
            ->assertOk()->assertJsonPath('audience.list_ids', [$shared->id, $own->id])
            ->assertJsonPath('audience.list_exclusions', [['list_id' => $own->id, 'contact_ids' => [$a]]]);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => []]])
            ->assertOk()->assertJsonPath('audience.list_exclusions', []);

        // walidacja kształtu
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => [['contact_ids' => [$a]]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.list_exclusions.0.list_id');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => [['list_id' => $own->id]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.list_exclusions.0.contact_ids');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => [['list_id' => $own->id, 'contact_ids' => ['x']]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.list_exclusions.0.contact_ids.0');

        // limit sumy odznaczonych we wszystkich grupach
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_exclusions' => [
            ['list_id' => $own->id, 'contact_ids' => range(1, 10000)],
            ['list_id' => $shared->id, 'contact_ids' => range(10001, 20001)],
        ]]])->assertUnprocessable()->assertJsonPath('errors', ['audience.list_exclusions' => ['Za dużo odznaczonych adresów.']]);
        $this->assertSame([], $campaign->fresh()->audienceSettings()['list_exclusions']);
    }

    public function test_duplicate_keeps_exclusions_only_for_lists_visible_to_new_author(): void
    {
        $author = $this->sender();
        $admin = User::factory()->withRole('admin')->create();
        $private = $this->mailingList($author, ['a@x.pl'], name: 'Prywatna');
        $shared = $this->mailingList($author, ['b@x.pl', 'c@x.pl'], shared: true, name: 'Wspólna');
        $campaign = $this->campaign($author, [], ['audience' => [
            'list_ids' => [$private->id, $shared->id],
            'list_exclusions' => [
                ['list_id' => $private->id, 'contact_ids' => [$this->contactId('a@x.pl')]],
                ['list_id' => $shared->id, 'contact_ids' => [$this->contactId('c@x.pl')]],
            ],
        ]]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/campaigns/{$campaign->id}/duplicate")->assertCreated()
            ->assertJsonPath('audience.list_ids', [$shared->id])
            ->assertJsonPath('audience.list_exclusions', [['list_id' => $shared->id, 'contact_ids' => [$this->contactId('c@x.pl')]]]);
    }

    public function test_contact_unchecked_in_one_group_still_gets_mail_from_another_and_later_additions_join(): void
    {
        $author = $this->sender();
        $first = $this->mailingList($author, ['x@a.pl', 'y@a.pl', 'z@a.pl'], name: 'Pierwsza');
        $second = $this->mailingList($author, ['y@a.pl'], name: 'Druga');
        $campaign = $this->campaign($author, [], ['audience' => [
            'list_ids' => [$first->id, $second->id],
            'list_exclusions' => [['list_id' => $first->id, 'contact_ids' => [$this->contactId('x@a.pl'), $this->contactId('y@a.pl')]]],
        ]]);
        // dopisany do grupy po wyborze — dochodzi
        $later = Contact::query()->create(['email' => 'nowy@a.pl']);
        $first->contacts()->attach($later->id, ['basis' => 'customer', 'added_by' => $author->id]);

        $resolver = app(AudienceResolver::class);
        $p = $resolver->preview($campaign);
        $this->assertSame(3, $p['lists']['contacts']);
        $this->assertSame(0, $p['duplicates']);
        $this->assertSame(['z@a.pl', 'nowy@a.pl', 'y@a.pl'], array_column($p['sample'], 'email'));

        $list = $resolver->recipientList($campaign);
        // y@ odznaczony w pierwszej, ale jest w drugiej — idzie z drugiej
        $this->assertSame(['Pierwsza', 'Pierwsza', 'Druga'], array_column($list['final'], 'origin'));
        $this->assertSame([], $list['skipped']);

        // odznaczony także w drugiej — nie idzie
        $campaign->update(['audience' => [...$campaign->audience, 'list_exclusions' => [
            ...$campaign->audience['list_exclusions'],
            ['list_id' => $second->id, 'contact_ids' => [$this->contactId('y@a.pl')]],
        ]]]);
        $this->assertSame(['z@a.pl', 'nowy@a.pl'], array_column($resolver->recipientList($campaign->fresh())['final'], 'email'));
    }

    public function test_list_contacts_rows_also_in_ids_and_access(): void
    {
        $author = $this->sender();
        $other = User::factory()->withRole('handlowiec')->create();
        $own = $this->mailingList($author, ['bartek@a.pl', 'Adam@a.pl', 'cezary@b.pl'], name: 'Moja');
        $shared = $this->mailingList($other, ['bartek@a.pl', 'cezary@b.pl'], shared: true, name: 'Wspólna');
        $third = $this->mailingList($author, ['bartek@a.pl'], name: 'Trzecia');
        $foreign = $this->mailingList($other, ['bartek@a.pl'], name: 'Cudza prywatna');
        $notSelected = $this->mailingList($author, ['bartek@a.pl'], name: 'Niewybrana');
        Contact::query()->where('email', 'cezary@b.pl')->update(['company' => 'Firma Cezar']);
        EmailSuppression::query()->create(['email' => 'adam@a.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);
        [$bartek, $adam, $cezary] = [$this->contactId('bartek@a.pl'), $this->contactId('Adam@a.pl'), $this->contactId('cezary@b.pl')];
        $campaign = $this->campaign($author, [], ['audience' => [
            'list_ids' => [$own->id, $third->id, $shared->id, $foreign->id],
            'list_exclusions' => [
                ['list_id' => $own->id, 'contact_ids' => [$bartek, 999999]],
                // w trzeciej odznaczony — tam się nie liczy
                ['list_id' => $third->id, 'contact_ids' => [$bartek]],
            ],
        ]]);
        Sanctum::actingAs($author);

        $res = $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}")->assertOk();
        $res->assertJsonPath('list', ['id' => $own->id, 'name' => 'Moja']);
        $this->assertSame(['Adam@a.pl', 'bartek@a.pl', 'cezary@b.pl'], array_column($res->json('data'), 'email'));
        $this->assertSame([null, null, 'Firma Cezar'], array_column($res->json('data'), 'company'));
        $this->assertSame(['suppressed', null, null], array_column($res->json('data'), 'skipped'));
        $this->assertSame(['customer', 'customer', 'customer'], array_column($res->json('data'), 'basis'));
        $this->assertNotNull($res->json('data.0.added_at'));
        // also_in: tylko inne WYBRANE grupy widoczne dla autora, bez tych, w których kontakt odznaczono
        $this->assertSame([[], ['Wspólna'], ['Wspólna']], array_column($res->json('data'), 'also_in'));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 3], $res->json('meta'));
        $this->assertEqualsCanonicalizing([$bartek, $adam, $cezary], $res->json('ids'));
        // zapisane odznaczenia ∩ grupa
        $this->assertSame([$bartek], $res->json('excluded_ids'));

        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}&search=FIRMA")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'cezary@b.pl')->assertJsonPath('meta.total', 1)
            ->assertJsonCount(3, 'ids');
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}&per_page=10&page=2")
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 1);

        // wspólna grupa innego użytkownika — można; niewybrana też (okno otwiera się przed zaznaczeniem)
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$shared->id}")->assertOk()
            ->assertJsonPath('excluded_ids', [])
            ->assertJsonPath('data.0.also_in', []);
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$notSelected->id}")->assertOk()
            ->assertJsonPath('data.0.also_in', ['Wspólna']);

        // cudza prywatna, nieistniejąca, bez list_id, za małe per_page
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$foreign->id}")->assertNotFound();
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id=999999")->assertNotFound();
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts")->assertUnprocessable()->assertJsonValidationErrors('list_id');
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}&per_page=5")->assertUnprocessable();

        // cudza kampania — 404; administrator (campaigns.manage) widzi grupy AUTORA kampanii, nie swoje prywatne
        Sanctum::actingAs($other);
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}")->assertNotFound();
        $admin = User::factory()->withRole('admin')->create();
        $adminList = $this->mailingList($admin, ['admin@a.pl'], name: 'Admina');
        Sanctum::actingAs($admin);
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$own->id}")->assertOk();
        $this->getJson("/api/campaigns/{$campaign->id}/list-contacts?list_id={$adminList->id}")->assertNotFound();
    }

    public function test_audience_recipients_views_summary_checksum_and_search(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417');
        $list = $this->mailingList($author, ['Jan@Alfa.pl', 'zly-adres', 'wypisany@a.pl', 'odznaczony@a.pl'], name: 'Stali klienci');
        $this->customer('BETA', ['jan@alfa.pl', 'faktury@beta.pl', 'kontakt@beta.pl'], [$item->id => '2026-05-01']);
        EmailSuppression::query()->create(['email' => 'wypisany@a.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);
        $campaign = $this->campaign($author, [$item], ['audience' => [
            'list_ids' => [$list->id],
            'list_exclusions' => [['list_id' => $list->id, 'contact_ids' => [$this->contactId('odznaczony@a.pl')]]],
            'xl' => ['mode' => 'items', 'months' => 12],
        ]]);
        Sanctum::actingAs($author);

        $res = $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->assertOk();
        $this->assertSame([
            'total' => 2, 'from_lists' => 3, 'from_xl' => 3, 'duplicates' => 1, 'already' => 0,
            'skipped' => ['invalid' => 1, 'generic' => 1, 'suppressed' => 1, 'capped' => 0],
        ], $res->json('summary'));
        $this->assertSame(sha1("jan@alfa.pl\nkontakt@beta.pl"), $res->json('checksum'));
        $this->assertSame(AudienceResolver::checksum(['kontakt@beta.pl', 'jan@alfa.pl']), $res->json('checksum'));
        $this->assertSame([], $res->json('warnings'));
        $this->assertSame([
            ['email' => 'jan@alfa.pl', 'name' => 'Kontakt Jan@Alfa.pl', 'source' => 'list', 'origin' => 'Stali klienci', 'reason' => null],
            ['email' => 'kontakt@beta.pl', 'name' => 'Firma BETA', 'source' => 'xl', 'origin' => 'BETA', 'reason' => null],
        ], $res->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 2], $res->json('meta'));

        $skipped = $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?view=skipped")->assertOk();
        $this->assertSame([
            ['zly-adres', 'invalid', 'list'], ['faktury@beta.pl', 'generic', 'xl'], ['wypisany@a.pl', 'suppressed', 'list'],
        ], array_map(static fn (array $r): array => [$r['email'], $r['reason'], $r['source']], $skipped->json('data')));
        $this->assertSame(3, $skipped->json('meta.total'));

        // szukanie po pochodzeniu (akronim XL / nazwa grupy) i po adresie, bez wielkości liter; stronicowanie
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?search=beta")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'kontakt@beta.pl')
            ->assertJsonPath('summary.total', 2);
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?view=skipped&search=STALI")
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?per_page=10&page=2")
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 1);
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?view=all")->assertUnprocessable();
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients?per_page=500")->assertUnprocessable();

        // cudza kampania — 404; administrator widzi pełną listę
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->assertNotFound();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->assertOk()->assertJsonPath('summary.total', 2);
    }

    public function test_send_with_stale_checksum_is_refused_and_matching_one_starts(): void
    {
        $author = $this->sender();
        $list = $this->mailingList($author, ['a@klient.pl', 'b@klient.pl']);
        $campaign = $this->campaign($author, [$this->erpItem('B20417', 420)], ['audience' => ['list_ids' => [$list->id]]]);
        Sanctum::actingAs($author);

        $checksum = (string) $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->assertOk()->json('checksum');
        $this->postJson("/api/campaigns/{$campaign->id}/send", ['recipients_checksum' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients_checksum');

        // po podglądzie ktoś się wypisał — lista inna, nic nie wychodzi, migawki pozycji wycofane
        EmailSuppression::query()->create(['email' => 'b@klient.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);
        $this->postJson("/api/campaigns/{$campaign->id}/send", ['recipients_checksum' => $checksum])
            ->assertUnprocessable()
            ->assertJsonPath('errors.recipients_checksum', ['Lista odbiorców zmieniła się od podglądu — sprawdź ją jeszcze raz.']);
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertSame(0, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        $this->assertNull(CampaignItem::query()->where('campaign_id', $campaign->id)->firstOrFail()->snap_name);

        $fresh = (string) $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->json('checksum');
        $this->assertNotSame($checksum, $fresh);
        $this->postJson("/api/campaigns/{$campaign->id}/send", ['recipients_checksum' => $fresh])
            ->assertOk()->assertJsonPath('status', Campaign::STATUS_SENDING);
        $this->assertSame(['a@klient.pl'], CampaignRecipient::query()->where('campaign_id', $campaign->id)->pluck('email')->all());
    }

    public function test_send_without_checksum_still_starts(): void
    {
        $author = $this->sender();
        $list = $this->mailingList($author, ['a@klient.pl']);
        $campaign = $this->campaign($author, [$this->erpItem('B20417', 420)], ['audience' => ['list_ids' => [$list->id]]]);
        Sanctum::actingAs($author);

        $this->postJson("/api/campaigns/{$campaign->id}/send")->assertOk()->assertJsonPath('status', Campaign::STATUS_SENDING);
        $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
    }

    private function contactId(string $email): int
    {
        return (int) Contact::query()->where('email', $email)->value('id');
    }
}
