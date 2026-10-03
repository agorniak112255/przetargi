<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Clients\InquiryClientLinker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pewne powiązanie zapytania z klientem: ten sam adres e-mail co na karcie klienta albo NIP z maila — nigdy domena,
 * nigdy przy niejednoznaczności, nigdy wbrew wyborowi handlowca.
 */
final class InquiryClientLinkerTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NIP = '8132283737';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bzp.our_company.nip' => self::OUR_NIP]);
        $this->author = User::factory()->create(['email' => 'handlowiec@supon.pl']);
    }

    public function test_sender_email_from_client_card_or_contact_links_the_client(): void
    {
        $acme = $this->client('ACME', '526-025-09-95', emails: ['biuro@acme.pl'], contacts: [['name' => 'Jan', 'email' => 'Jan.Kowalski@ACME.pl']]);
        $byCard = $this->inquiry('Biuro@Acme.pl');
        $byContact = $this->inquiry('jan.kowalski@acme.pl');
        // ta sama domena, inny adres — domena nie wystarcza
        $sameDomain = $this->inquiry('magazyn@acme.pl');

        $linker = new InquiryClientLinker;
        $this->assertSame(['client_id' => $acme->id, 'source' => 'email'], $linker->match($byCard));
        $this->assertSame(['client_id' => $acme->id, 'source' => 'email'], $linker->match($byContact));
        $this->assertNull($linker->match($sameDomain));
    }

    public function test_email_shared_by_two_clients_is_ambiguous(): void
    {
        $this->client('Oddział A', null, emails: ['zakupy@grupa.pl']);
        $this->client('Oddział B', null, contacts: [['email' => 'zakupy@grupa.pl']]);

        $this->assertNull((new InquiryClientLinker)->match($this->inquiry('zakupy@grupa.pl')));
    }

    public function test_ambiguous_email_is_resolved_only_by_nip_of_one_of_its_clients(): void
    {
        $a = $this->client('Oddział A', '5260250995', emails: ['zakupy@grupa.pl']);
        $this->client('Oddział B', '8130000008', emails: ['zakupy@grupa.pl']);
        $other = $this->client('Obca firma', '6340000019');

        $linker = new InquiryClientLinker;
        $this->assertSame(['client_id' => $a->id, 'source' => 'nip'], $linker->match($this->inquiry('zakupy@grupa.pl', "Pozdrawiam\nNIP: 526-025-09-95")));
        // NIP wskazuje klienta spoza tych, do których należy adres — sprzeczność, brak powiązania
        $this->assertNull($linker->match($this->inquiry('zakupy@grupa.pl', 'NIP 6340000019')));
        $this->assertNotNull($other->id);
    }

    public function test_nip_from_mail_body_links_when_exactly_one_valid_labelled_nip(): void
    {
        $huta = $this->client('Huta', 'PL 634-000-00-19');

        $linker = new InquiryClientLinker;
        $this->assertSame(['client_id' => $huta->id, 'source' => 'nip'], $linker->match($this->inquiry('ktos@gmail.com', "Huta S.A.\nNIP: PL6340000019\ntel. 15 000 00 00")));
        // bez etykiety „NIP” — liczba to nie NIP
        $this->assertNull($linker->match($this->inquiry('ktos@gmail.com', 'Zamówienie 6340000019')));
        // zła suma kontrolna
        $this->assertNull($linker->match($this->inquiry('ktos@gmail.com', 'NIP 6340000018')));
        // dwa różne NIP-y w mailu — nie wiadomo, który jest klienta
        $this->assertNull($linker->match($this->inquiry('ktos@gmail.com', "NIP 6340000019\nNIP odbiorcy: 9510000015")));
        // ten sam NIP dwa razy — jeden NIP
        $this->assertSame($huta->id, $linker->match($this->inquiry('ktos@gmail.com', "NIP 634 000 00 19\n> NIP: 634-000-00-19"))['client_id'] ?? null);
    }

    public function test_our_own_nip_in_quoted_reply_is_ignored(): void
    {
        $huta = $this->client('Huta', '6340000019');
        // klient o naszym NIP-ie (np. wpis testowy) — nasz NIP nigdy nie wiąże
        $this->client('Supon (wpis)', self::OUR_NIP);

        $linker = new InquiryClientLinker;
        $this->assertNull($linker->match($this->inquiry('ktos@gmail.com', "> PHT Supon\n> NIP: 813-22-83-737")));
        $this->assertSame(
            ['client_id' => $huta->id, 'source' => 'nip'],
            $linker->match($this->inquiry('ktos@gmail.com', "NIP: 6340000019\n> PHT Supon NIP 8132283737")),
        );
    }

    public function test_nip_shared_by_two_clients_does_not_link(): void
    {
        $this->client('Dubel A', '6340000019');
        $this->client('Dubel B', '634-000-00-19');

        $this->assertNull((new InquiryClientLinker)->match($this->inquiry('ktos@gmail.com', 'NIP 6340000019')));
    }

    public function test_email_and_nip_pointing_to_different_clients_conflict(): void
    {
        $acme = $this->client('ACME', '5260250995', emails: ['biuro@acme.pl']);
        $this->client('Huta', '6340000019');

        $linker = new InquiryClientLinker;
        $this->assertNull($linker->match($this->inquiry('biuro@acme.pl', 'NIP: 6340000019')));
        // zgodne — powiązanie przez e-mail
        $this->assertSame(['client_id' => $acme->id, 'source' => 'email'], $linker->match($this->inquiry('biuro@acme.pl', 'NIP: 5260250995')));
    }

    public function test_address_of_an_application_account_never_links(): void
    {
        // adres handlowca wpisany w XL jako e-mail klienta — przekazany przez niego mail nie jest od klienta
        $this->client('ACME', null, emails: ['handlowiec@supon.pl']);

        $this->assertNull((new InquiryClientLinker)->match($this->inquiry('Handlowiec@Supon.pl')));
    }

    public function test_link_all_sets_and_removes_automatic_links_but_never_touches_manual(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 05:55:00', 'UTC'));
        $acme = $this->client('ACME', null, emails: ['biuro@acme.pl']);
        $huta = $this->client('Huta', '6340000019');
        $chosen = $this->client('Wybrany ręcznie', null);

        $auto = $this->inquiry('biuro@acme.pl');
        $byNip = $this->inquiry('x@gmail.com', 'NIP: 6340000019');
        // handlowiec wybrał innego klienta, choć adres pasuje do ACME
        $manual = $this->inquiry('biuro@acme.pl', null, ['client_id' => $chosen->id, 'client_link_source' => 'manual']);
        // świadomie bez klienta
        $manualNone = $this->inquiry('biuro@acme.pl', null, ['client_link_source' => 'manual']);
        // zapis sprzed reguły: client_id z formularza, bez źródła — traktowany jak ręczny
        $legacy = $this->inquiry('biuro@acme.pl', null, ['client_id' => $chosen->id]);
        // automatyczne powiązanie, które przestało pasować (adres zniknął z karty)
        $stale = $this->inquiry('stary@firma.pl', null, ['client_id' => $huta->id, 'client_link_source' => 'email']);
        $updatedAt = $auto->fresh()->updated_at?->toIso8601String();

        $this->travel(1)->hours();
        $stats = (new InquiryClientLinker)->linkAll();

        $this->assertSame(['checked' => 6, 'manual' => 3, 'linked_email' => 1, 'linked_nip' => 1, 'changed' => 2, 'removed' => 1], $stats);
        $this->assertSame([$acme->id, 'email'], [$auto->fresh()->client_id, $auto->fresh()->client_link_source]);
        $this->assertSame('2026-10-03T06:55:00+00:00', $auto->fresh()->client_linked_at?->toIso8601String());
        // zapis automatu nie zmienia „ostatniej zmiany” zapytania
        $this->assertSame($updatedAt, $auto->fresh()->updated_at?->toIso8601String());
        $this->assertSame([$huta->id, 'nip'], [$byNip->fresh()->client_id, $byNip->fresh()->client_link_source]);
        $this->assertSame([$chosen->id, 'manual'], [$manual->fresh()->client_id, $manual->fresh()->client_link_source]);
        $this->assertSame([null, 'manual'], [$manualNone->fresh()->client_id, $manualNone->fresh()->client_link_source]);
        $this->assertSame([$chosen->id, null], [$legacy->fresh()->client_id, $legacy->fresh()->client_link_source]);
        $this->assertSame([null, null, null], [$stale->fresh()->client_id, $stale->fresh()->client_link_source, $stale->fresh()->client_linked_at]);

        // drugi przebieg bez zmian w danych niczego nie zmienia
        $again = (new InquiryClientLinker)->linkAll();
        $this->assertSame([0, 0], [$again['changed'], $again['removed']]);
    }

    public function test_manual_link_and_unlink_are_kept_by_the_nightly_run(): void
    {
        $acme = $this->client('ACME', null, emails: ['biuro@acme.pl']);
        $other = $this->client('Inny', null);
        $inquiry = $this->inquiry('biuro@acme.pl');
        $linker = new InquiryClientLinker;

        $linker->link($inquiry, $other->id);
        $linker->linkAll();
        $this->assertSame(['client' => ['id' => $other->id, 'name' => 'Inny'], 'source' => 'manual'], InquiryClientLinker::present($inquiry->fresh()));

        $linker->link($inquiry->fresh(), null);
        $linker->linkAll();
        $this->assertNull($inquiry->fresh()->client_id);
        // świadome „Bez klienta” widać jako wybór handlowca (a nie jako brak powiązania)
        $this->assertSame(['client' => null, 'source' => 'manual'], InquiryClientLinker::present($inquiry->fresh()));
        $this->assertNotNull($acme->id);
    }

    public function test_present_shows_automatic_source_and_legacy_rows_as_manual(): void
    {
        $acme = $this->client('ACME', null);

        $this->assertSame('nip', InquiryClientLinker::present($this->inquiry(null, null, ['client_id' => $acme->id, 'client_link_source' => 'nip']))['source'] ?? null);
        $this->assertSame('manual', InquiryClientLinker::present($this->inquiry(null, null, ['client_id' => $acme->id]))['source'] ?? null);
        $this->assertNull(InquiryClientLinker::present($this->inquiry(null)));
    }

    /**
     * @param  list<string>|null  $emails
     * @param  list<array<string, string>>|null  $contacts
     */
    private function client(string $name, ?string $nip, ?array $emails = null, ?array $contacts = null): Client
    {
        return Client::query()->create(['name' => $name, 'nip' => $nip, 'emails' => $emails, 'contacts' => $contacts]);
    }

    /** @param  array<string, mixed>  $extra */
    private function inquiry(?string $fromEmail, ?string $body = null, array $extra = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $this->author->id,
            'tone' => 'formal',
            'source_subject' => 'Zapytanie',
            'source_from_email' => $fromEmail,
            'source_body' => $body ?? 'Proszę o ofertę na rękawice.',
        ]);
        if ($extra !== []) {
            $inquiry->forceFill($extra)->save();
        }

        return $inquiry;
    }
}
