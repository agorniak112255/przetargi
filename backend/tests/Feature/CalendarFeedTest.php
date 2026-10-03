<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Osobisty adres kalendarza (GET|POST|DELETE /api/me/calendar-feed) i plik ICS (GET /api/calendar/{token}.ics):
 * w bazie tylko skrót klucza, nowy adres unieważnia stary, uprawnienia sprawdzane przy każdym pobraniu, format
 * iCalendar (CRLF, łamanie co 75 oktetów, escape, strefa Europe/Warsaw, całodniowe bez godziny, stały UID), bez cen.
 */
final class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // sobota 3.10.2026, 9:00 w Polsce
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'UTC'));
        config(['app.url' => 'https://przetargi.example.pl', 'app.frontend_url' => 'https://przetargi.example.pl']);
    }

    public function test_address_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        Sanctum::actingAs($me);

        $this->getJson('/api/me/calendar-feed')->assertOk()
            ->assertExactJson(['active' => false, 'scope' => null, 'created_at' => null, 'used_at' => null]);

        $created = $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('scope', 'mine')
            ->assertJsonPath('used_at', null);
        $url = (string) $created->json('url');
        $this->assertMatchesRegularExpression('#^https://przetargi\.example\.pl/api/calendar/[A-Za-z0-9]{40}\.ics$#', $url);
        $token = $this->token($url);

        $row = (array) DB::table('users')->where('id', $me->id)->first();
        $this->assertSame(hash('sha256', $token), $row['calendar_token_hash']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "Klucz w kolumnie {$column}.");
        }
        // potem adresu już nie da się odczytać; skrót nie wychodzi w żadnej odpowiedzi
        $shown = $this->getJson('/api/me/calendar-feed')->assertOk()->assertJsonMissingPath('url');
        $this->assertTrue($shown->json('active'));
        $this->assertStringNotContainsString((string) $row['calendar_token_hash'], (string) $this->getJson('/api/me')->getContent());
        $this->assertStringNotContainsString($token, (string) $shown->getContent());
    }

    public function test_new_address_invalidates_the_old_one_and_switching_off_kills_it(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        Sanctum::actingAs($me);

        $old = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $this->ics($old)->assertOk();

        $new = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $this->assertNotSame($old, $new);
        $this->ics($old)->assertNotFound();
        $this->ics($new)->assertOk();

        $this->deleteJson('/api/me/calendar-feed')->assertOk()->assertExactJson(['ok' => true]);
        $this->ics($new)->assertNotFound();
        $this->getJson('/api/me/calendar-feed')->assertOk()->assertJsonPath('active', false);
        $this->assertNull(DB::table('users')->where('id', $me->id)->value('calendar_token_hash'));
    }

    public function test_scope_all_needs_view_all(): void
    {
        Sanctum::actingAs($this->userWith(['tenders.view_own']));
        $this->postJson('/api/me/calendar-feed', ['scope' => 'all'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->postJson('/api/me/calendar-feed', ['scope' => 'everything'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->postJson('/api/me/calendar-feed', [])->assertStatus(422)->assertJsonValidationErrors('scope');

        Sanctum::actingAs($this->userWith(['tenders.view_all']));
        $this->postJson('/api/me/calendar-feed', ['scope' => 'all'])->assertOk()->assertJsonPath('scope', 'all');
    }

    public function test_permissions_are_checked_on_every_download(): void
    {
        $me = $this->userWith(['tenders.view_own', 'tenders.view_all']);
        Sanctum::actingAs($me);
        $token = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'all'])->json('url'));
        $this->ics($token)->assertOk();

        // bez view_all zakres „wszystkie” już nie działa
        $this->role->revokePermissionTo('tenders.view_all');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->ics($token)->assertForbidden();

        // bez żadnego dostępu do przetargów — także zakres „moje”
        Sanctum::actingAs($me->fresh());
        $mine = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $this->ics($mine)->assertOk();
        $this->role->revokePermissionTo('tenders.view_own');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $denied = $this->ics($mine)->assertForbidden();
        $this->assertStringNotContainsString('BEGIN:VCALENDAR', (string) $denied->getContent());

        // przywrócone uprawnienie — ten sam adres działa znowu
        $this->role->givePermissionTo('tenders.view_own');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->ics($mine)->assertOk();
    }

    public function test_file_lists_only_own_tenders_in_the_window_without_rejected_and_without_prices(): void
    {
        $me = $this->userWith(['tenders.view_own', 'tenders.view_all']);
        $other = User::factory()->create();
        $mine = $this->tender($me, 'wycena', '2026-10-05', '10:00', 'Szpital Wojewódzki nr 2', 'Rękawice nitrylowe');
        $mine->forceFill(['notice_number' => '2026/BZP 00431178/01', 'offer_value_net' => 98765.43, 'margin_percent' => 17.5])->save();
        $invited = $this->tender($other, 'wycena', '2026-10-06', null, 'Gmina', 'Okulary');
        TenderInvitation::query()->create(['tender_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $other->id]);
        $foreign = $this->tender($other, 'wycena', '2026-10-07', null, 'Cudzy', 'Kaski');
        $rejected = $this->tender($me, 'odrzucony', '2026-10-08', null, 'Odrzucony', 'Buty');
        $old = $this->tender($me, 'exported', '2026-07-04', null, 'Stary', 'Kurtki');      // 91 dni temu
        $edge = $this->tender($me, 'exported', '2026-07-05', null, 'Na granicy', 'Spodnie'); // 90 dni temu
        $far = $this->tender($me, 'wycena', '2027-10-04', null, 'Za rok i dzień', 'Fartuchy');

        Sanctum::actingAs($me);
        $token = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $this->travel(5)->minutes();
        $response = $this->ics($token)->assertOk();

        $this->assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=utf-8', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $body = (string) $response->getContent();
        $uids = $this->uids($body);
        $this->assertSame([
            "tender-{$edge->id}-deadline@przetargi.example.pl",
            "tender-{$mine->id}-deadline@przetargi.example.pl",
            "tender-{$invited->id}-deadline@przetargi.example.pl",
        ], $uids, 'Zakres „moje”: prowadzone i zaproszenia; bez odrzuconych; od 90 dni wstecz do roku naprzód.');
        $this->assertNotContains("tender-{$foreign->id}-deadline@przetargi.example.pl", $uids);
        $this->assertNotContains("tender-{$rejected->id}-deadline@przetargi.example.pl", $uids);
        $this->assertNotContains("tender-{$old->id}-deadline@przetargi.example.pl", $uids);
        $this->assertNotContains("tender-{$far->id}-deadline@przetargi.example.pl", $uids);

        $unfolded = $this->unfold($body);
        $this->assertStringContainsString('SUMMARY:Termin składania ofert: Przetarg Szpital Wojewódzki nr 2', $unfolded);
        $this->assertStringContainsString('Przetarg: '.$mine->number.'\nNumer ogłoszenia: 2026/BZP 00431178/01\nTytuł: Przetarg Szpital Wojewódzki nr 2\nZamawiający: Szpital Wojewódzki nr 2\nTermin składania ofert: 5.10.2026\, 10:00\nOtwórz w aplikacji: https://przetargi.example.pl/tenders/'.$mine->id, $unfolded);
        $this->assertStringContainsString('URL:https://przetargi.example.pl/tenders/'.$mine->id, $unfolded);
        // bez cen i wartości oferty
        $this->assertStringNotContainsString('98765', $unfolded);
        $this->assertStringNotContainsString('98 765', $unfolded);
        $this->assertStringNotContainsString('17.5', $unfolded);
        $this->assertDoesNotMatchRegularExpression('/zł|PLN|cena|wartość/iu', $unfolded);

        // użycie adresu zapisane (bez ruszania updated_at konta); actingAs trzyma model sprzed pobrania — świeży z bazy
        $fresh = $me->fresh();
        $this->assertSame('2026-10-03 07:00:00', $fresh->updated_at->format('Y-m-d H:i:s'));
        Sanctum::actingAs($fresh);
        $this->getJson('/api/me/calendar-feed')->assertJsonPath('used_at', '2026-10-03T07:05:00+00:00');

        // zakres „wszystkie” dokłada cudze, nadal bez odrzuconych
        $all = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'all'])->json('url'));
        $allUids = $this->uids((string) $this->ics($all)->assertOk()->getContent());
        $this->assertContains("tender-{$foreign->id}-deadline@przetargi.example.pl", $allUids);
        $this->assertNotContains("tender-{$rejected->id}-deadline@przetargi.example.pl", $allUids);
    }

    public function test_timed_events_use_warsaw_timezone_and_dateless_ones_are_all_day(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $timed = $this->tender($me, 'wycena', '2026-10-05', '23:45', 'Wieczór', 'Rękawice');
        $allDay = $this->tender($me, 'wycena', '2026-10-31', null, 'Cały dzień', 'Okulary');
        $timed->forceFill(['updated_at' => Carbon::parse('2026-10-02 08:15:00', 'UTC')])->saveQuietly();

        Sanctum::actingAs($me);
        $token = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $body = (string) $this->ics($token)->assertOk()->getContent();

        // CRLF w każdym wierszu, żadnego samotnego LF
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $body));

        $this->assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Warsaw\r\n", $body);
        $this->assertStringContainsString("TZOFFSETTO:+0200\r\nTZNAME:CEST\r\n", $body);
        $this->assertStringContainsString("TZOFFSETTO:+0100\r\nTZNAME:CET\r\n", $body);

        $timedEvent = $this->event($body, $timed->id);
        $this->assertStringContainsString("DTSTART;TZID=Europe/Warsaw:20261005T234500\r\n", $timedEvent);
        // 30 minut, przez północ — koniec następnego dnia
        $this->assertStringContainsString("DTEND;TZID=Europe/Warsaw:20261006T001500\r\n", $timedEvent);
        $this->assertStringContainsString("LAST-MODIFIED:20261002T081500Z\r\n", $timedEvent);
        $this->assertStringContainsString("DTSTAMP:20261003T070000Z\r\n", $timedEvent);

        $allDayEvent = $this->event($body, $allDay->id);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261031\r\n", $allDayEvent);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261101\r\n", $allDayEvent);
        $this->assertStringNotContainsString('TZID', $allDayEvent);
    }

    public function test_uid_stays_the_same_across_downloads_changes_and_new_addresses(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $tender = $this->tender($me, 'wycena', '2026-10-05', '10:00', 'Szpital', 'Rękawice');
        Sanctum::actingAs($me);

        $first = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $before = $this->uids((string) $this->ics($first)->getContent());

        $tender->forceFill(['deadline' => '2026-10-09', 'deadline_time' => '12:00'])->save();
        $second = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $body = (string) $this->ics($second)->getContent();

        $this->assertSame(["tender-{$tender->id}-deadline@przetargi.example.pl"], $before);
        $this->assertSame($before, $this->uids($body), 'Przesunięty termin to to samo zdarzenie — kalendarz je przesuwa, nie dubluje.');
        $this->assertStringContainsString('DTSTART;TZID=Europe/Warsaw:20261009T120000', $body);
    }

    public function test_text_is_escaped_and_long_lines_are_folded_at_75_octets(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $title = 'Rękawice; nitrylowe, bez pudru \ rozmiar „M” — dostawy żółtych ćwiczeniowych źdźbeł dla Szpitala Wojewódzkiego w Rzeszowie';
        $tender = $this->tender($me, 'wycena', '2026-10-05', '10:00', "Zakład\nGospodarki, Komunalnej", 'x');
        $tender->forceFill(['title' => $title])->save();

        Sanctum::actingAs($me);
        $token = $this->token((string) $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->json('url'));
        $body = (string) $this->ics($token)->assertOk()->getContent();

        foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'Wiersz dłuższy niż 75 oktetów: '.$line);
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'Łamanie rozcięło znak UTF-8: '.bin2hex($line));
        }
        $unfolded = $this->unfold($body);
        $this->assertStringContainsString('SUMMARY:Termin składania ofert: Rękawice\; nitrylowe\, bez pudru \\\\ rozmiar „M” — dostawy żółtych ćwiczeniowych źdźbeł dla Szpitala Wojewódzkiego w Rzeszowie'."\r\n", $unfolded);
        $this->assertStringContainsString('Zamawiający: Zakład\nGospodarki\, Komunalnej\n', $unfolded);
    }

    public function test_builder_folds_on_character_boundaries_and_escapes_text(): void
    {
        $this->assertSame('a\\\\b\;c\,d\ne\nf', IcsBuilder::escapeText("a\\b;c,d\r\ne\nf"));
        $this->assertSame('ab', IcsBuilder::escapeText("a\x07b"));

        $line = 'SUMMARY:'.str_repeat('ż', 60);
        $folded = IcsBuilder::fold($line);
        $parts = explode("\r\n", $folded);
        $this->assertGreaterThan(1, count($parts));
        // „SUMMARY:” (8 oktetów) + 33 × „ż” (po 2) = 74 — 34. „ż” przekroczyłoby 75, więc przechodzi do kontynuacji
        $this->assertSame(74, strlen($parts[0]));
        foreach ($parts as $i => $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            if ($i > 0) {
                $this->assertStringStartsWith(' ', $part);
            }
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
        $this->assertSame('KROTKI', IcsBuilder::fold('KROTKI'));
    }

    public function test_unknown_or_malformed_token_is_not_found(): void
    {
        $this->get('/api/calendar/'.str_repeat('A', 40).'.ics')->assertNotFound();
        $this->get('/api/calendar/'.str_repeat('A', 41).'.ics')->assertNotFound();
    }

    public function test_feed_endpoints_need_tender_access(): void
    {
        Sanctum::actingAs($this->userWith(['dashboard.view']));
        $this->getJson('/api/me/calendar-feed')->assertForbidden();
        $this->postJson('/api/me/calendar-feed', ['scope' => 'mine'])->assertForbidden();
        $this->deleteJson('/api/me/calendar-feed')->assertForbidden();
    }

    private function ics(string $token): TestResponse
    {
        return $this->get('/api/calendar/'.$token.'.ics');
    }

    private function token(string $url): string
    {
        $this->assertSame(1, preg_match('#/api/calendar/([A-Za-z0-9]{40})\.ics$#', $url, $m), 'Adres: '.$url);

        return $m[1];
    }

    private function unfold(string $body): string
    {
        return str_replace("\r\n ", '', $body);
    }

    /** @return list<string> */
    private function uids(string $body): array
    {
        preg_match_all('/^UID:(.+)\r$/m', $this->unfold($body), $m);

        return $m[1];
    }

    private function event(string $body, int $tenderId): string
    {
        $unfolded = $this->unfold($body);
        $this->assertSame(1, preg_match('/BEGIN:VEVENT\r\nUID:tender-'.$tenderId.'-deadline@[^\r]+\r\n.*?END:VEVENT\r\n/s', $unfolded, $m));

        return $m[0];
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $this->role = Role::findOrCreate('feed-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $this->role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($this->role);

        return $user;
    }

    private function tender(User $owner, string $status, ?string $deadline, ?string $time, string $client, string $requirement): Tender
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/2026/'.Str::random(6),
            'title' => 'Przetarg '.$client,
            'client_id' => Client::query()->create(['name' => $client])->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'deadline' => $deadline,
            'deadline_time' => $time,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $tender->items()->create(['line_no' => 1, 'requirement' => $requirement]);

        return $tender;
    }
}
