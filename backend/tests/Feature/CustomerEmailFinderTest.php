<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerEmailLookup;
use App\Models\CustomerEmailSuggestion;
use App\Models\ErpCustomer;
use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use App\Models\User;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Inspections\CustomerEmailFinder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adresy e-mail klientów ze stron WWW (Przeglądy, 06.10.2026): strona firmy z NIP-em = dowód „nip”, adres w domenie
 * strony firmy bez NIP-u = „name”, katalog tylko z NIP-em, bez adresów katalogu i operatora; decyzja człowieka;
 * zatwierdzony adres trafia do ofert i filtra „z adresem e-mail”.
 */
final class CustomerEmailFinderTest extends TestCase
{
    use RefreshDatabase;

    private const NIP = '8133218073';

    /** @var array<string, string> adres strony → treść (wprost i z czytnika) */
    private array $pages = [];

    /** @var list<string> strony, które odmawiają pobrania wprost (403) — tylko czytnik */
    private array $blocked = ['https://panoramafirm.pl/gokom'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['enrichment.reader_api_key' => 'jina_test_key', 'enrichment.reader_min_interval' => 0]);
        Cache::flush();
        // DNS bez sieci: *.internal = adres wewnętrzny, reszta publiczny
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(
            static fn (string $host): array => str_ends_with($host, '.internal') ? ['10.0.0.5'] : ['93.184.216.34'],
        ));
        $this->pages = [
            'https://go-kom.pl/' => 'GOKOM Boguchwała — gospodarka komunalna. Napisz: sekretariat@go-kom.pl albo prywatny.jan@gmail.com. Inspektor ochrony danych: iod@go-kom.pl, praca: rekrutacja@go-kom.pl',
            'https://go-kom.pl/kontakt' => 'Kontakt z nami. GOKOM Sp. z o.o. w Boguchwale, NIP 813-321-80-73, sekretariat i biuro: biuro@go-kom.pl, logo@2x.png',
            'https://panoramafirm.pl/gokom' => 'GOKOM Sp. z o.o. NIP 8133218073 e-mail: gokom.biuro@wp.pl. Operator: kontakt@wenet.pl, reklama@panoramafirm.pl',
            'https://aleo.com/pl/firma/inna' => 'Inna firma z tego samego katalogu, inny NIP 1234567890, adres: inna@firma.pl — nie nasz klient.',
        ];
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://s.jina.ai/')) {
                $query = urldecode(substr($url, strlen('https://s.jina.ai/')));

                return Http::response(['data' => str_contains($query, self::NIP) ? [
                    // gowork zawsze blokuje czytnik — nie zajmuje miejsca w limicie katalogów
                    ['url' => 'https://www.gowork.pl/gokom/dane-kontaktowe', 'title' => 'GOKOM — GoWork', 'description' => ''],
                    ['url' => 'https://panoramafirm.pl/gokom', 'title' => 'GOKOM — Panorama Firm', 'description' => ''],
                ] : [
                    ['url' => 'https://go-kom.pl/', 'title' => 'GOKOM', 'description' => ''],
                    // serwer w sieci wewnętrznej — nie pobierany ani wprost, ani czytnikiem
                    ['url' => 'https://gokom.internal/', 'title' => 'GOKOM intranet', 'description' => ''],
                    ['url' => 'https://www.facebook.com/gokom', 'title' => 'GOKOM | Facebook', 'description' => 'kontakt@facebook.com'],
                    ['url' => 'https://aleo.com/pl/firma/inna', 'title' => 'Inna', 'description' => ''],
                ]], 200);
            }
            if (str_starts_with($url, 'https://r.jina.ai/')) {
                $page = substr($url, strlen('https://r.jina.ai/'));

                return isset($this->pages[$page]) ? Http::response($this->pages[$page], 200) : Http::response('Not found', 404);
            }
            if (in_array($url, $this->blocked, true)) {
                return Http::response('Forbidden', 403);
            }

            // pobranie wprost: strona jako HTML z encjami i odnośnikiem mailto
            return isset($this->pages[$url])
                ? Http::response('<html><body><p>'.str_replace('@', '&#64;', htmlspecialchars($this->pages[$url])).'</p></body></html>', 200)
                : Http::response('Not found', 404);
        });
    }

    private function customer(array $attributes = []): ErpCustomer
    {
        return ErpCustomer::query()->create([
            'xl_gid' => 3830, 'acronym' => 'GOKOM BOGUCHWAŁA', 'name' => 'GOKOM SP.Z O.O.', 'nip' => self::NIP,
            'city' => 'BOGUCHWAŁA', 'archived' => false, ...$attributes,
        ]);
    }

    /** @return array<string, array{source: string, evidence: string, status: string}> */
    private function suggestions(): array
    {
        return CustomerEmailSuggestion::query()->orderBy('id')->get()
            ->mapWithKeys(static fn (CustomerEmailSuggestion $s): array => [$s->email => ['source' => $s->source, 'evidence' => $s->evidence, 'status' => $s->status]])
            ->all();
    }

    public function test_short_name_drops_legal_form_and_general_words(): void
    {
        $this->assertSame('ZAWPOL', CustomerEmailFinder::shortName('PRZEDSIĘBIORSTWO WIELOBRANŻOWE "ZAWPOL" SPÓŁKA Z O.O.'));
        $this->assertSame('GOKOM', CustomerEmailFinder::shortName('GOKOM SP.Z O.O.'));
        $this->assertSame('ŚWIAT DRZWI OKIEN MARIUSZ PYSZKA', CustomerEmailFinder::shortName('FHU ŚWIAT DRZWI I OKIEN MARIUSZ PYSZKA'));
    }

    public function test_finds_addresses_with_evidence_and_skips_noise(): void
    {
        $customer = $this->customer();

        $result = app(CustomerEmailFinder::class)->find(DB::table('erp_customers')->where('xl_gid', $customer->xl_gid)->first());

        $this->assertNull($result['error']);
        $this->assertSame([
            // strona firmy z NIP-em (zakładka kontakt) — przed adresem bez NIP-u
            'biuro@go-kom.pl' => ['source' => 'website', 'evidence' => 'nip', 'status' => 'pending'],
            // katalog z NIP-em klienta
            'gokom.biuro@wp.pl' => ['source' => 'directory', 'evidence' => 'nip', 'status' => 'pending'],
            // strona firmy bez NIP-u — adres w domenie strony
            'sekretariat@go-kom.pl' => ['source' => 'website', 'evidence' => 'name', 'status' => 'pending'],
        ], $this->suggestions());
        // bez: gmail na stronie bez NIP-u, adresy katalogu i jego operatora, obrazek, katalog innej firmy, Facebook
        $this->assertSame(3, $result['found']);
        Http::assertNotSent(static fn (HttpRequest $r): bool => str_contains($r->url(), 'gowork.pl') || str_contains($r->url(), 'gokom.internal'));
        // strony firmy wprost, bez czytnika; czytnikiem tylko katalog, który odmówił (403)
        Http::assertNotSent(static fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://r.jina.ai/https://go-kom.pl'));
        Http::assertSent(static fn (HttpRequest $r): bool => $r->url() === 'https://r.jina.ai/https://panoramafirm.pl/gokom');
        // co sprawdzono: strona firmy, kontakt (z NIP-em), serwer wewnętrzny (nie), katalog innej firmy, katalog z NIP-em
        $this->assertSame(
            ['go-kom.pl' => [true, false], 'go-kom.pl/kontakt' => [true, true], 'gokom.internal' => [false, false], 'gokom.internal/kontakt' => [false, false], 'aleo.com' => [true, false], 'panoramafirm.pl' => [true, true]],
            collect($result['pages'])->mapWithKeys(static fn (array $p): array => [
                $p['host'].(str_ends_with($p['url'], '/kontakt') ? '/kontakt' : '') => [$p['read'], $p['nip']],
            ])->all(),
        );
        $lookup = CustomerEmailLookup::query()->where('customer_xl_gid', 3830)->firstOrFail();
        $this->assertSame(3, $lookup->found);
        $this->assertNull($lookup->error);
    }

    public function test_html_decoding_reveals_hidden_addresses(): void
    {
        // Cloudflare ukrywa adres jako szesnastkowy XOR z kluczem w pierwszym bajcie; encje i mailto z %40
        $key = 0x2A;
        $hex = sprintf('%02x', $key).implode('', array_map(static fn (string $ch): string => sprintf('%02x', ord($ch) ^ $key), str_split('biuro@firma.pl')));
        $text = CustomerEmailFinder::decodeHtml('<a data-cfemail="'.$hex.'">[email protected]</a> <a href="mailto:handel%40firma.pl">napisz</a> sklep&#64;firma.pl <script>{"html":"\u003ebok@firma.pl\u003c/a\u003e"}</script>');

        $this->assertSame(['biuro@firma.pl', 'handel@firma.pl', 'sklep@firma.pl', 'bok@firma.pl'], CustomerEmailFinder::emailsIn($text));
    }

    public function test_directory_page_must_be_about_the_client(): void
    {
        $tokens = ['pociask'];
        // strona innej firmy z NIP-em klienta w spisie firm w okolicy — nie
        $this->assertFalse(CustomerEmailFinder::pageAboutClient(
            'https://cabb.pl/firma/cezary-szczepanik-firma-handlowouslugowa-geo-komp-6842157343',
            '<title>Geo-Komp Cezary Szczepanik</title> Firmy w okolicy: Pociask NIP 8181008250', $tokens, '8181008250',
        ));
        // strona klienta: słowo nazwy w adresie, NIP w adresie, słowo nazwy w tytule z czytnika
        $this->assertTrue(CustomerEmailFinder::pageAboutClient('https://www.krs-online.com.pl/firma/1538424-pociask-w-autoryzowany', '', $tokens, '8181008250'));
        $this->assertTrue(CustomerEmailFinder::pageAboutClient('https://mapa.targeo.pl/8181008250/nip/firma', '', [], '8181008250'));
        $this->assertTrue(CustomerEmailFinder::pageAboutClient('https://panoramafirm.pl/x', "Title: Zakład POCIASK — Panorama Firm\n", $tokens, ''));
    }

    public function test_card_and_decided_addresses_do_not_come_back(): void
    {
        $this->customer(['emails' => ['biuro@go-kom.pl']]);
        CustomerEmailSuggestion::query()->create([
            'customer_xl_gid' => 3830, 'email' => 'sekretariat@go-kom.pl', 'source' => 'website', 'evidence' => 'name',
            'status' => 'rejected', 'found_at' => now(),
        ]);

        app(CustomerEmailFinder::class)->find(DB::table('erp_customers')->where('xl_gid', 3830)->first());

        $this->assertSame(['sekretariat@go-kom.pl', 'gokom.biuro@wp.pl'], CustomerEmailSuggestion::query()->orderBy('id')->pluck('email')->all());
    }

    public function test_search_endpoint_decision_and_accepted_address_used_like_card_address(): void
    {
        $this->customer();
        $position = InspectionPosition::query()->create([
            'xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GP-6', 'interval_months' => 12,
        ]);
        InspectionDue::query()->create([
            'customer_xl_gid' => 3830, 'inspection_position_id' => $position->id, 'due_on' => now()->addDays(5)->toDateString(),
            'open_count' => 1, 'open_quantity' => 3, 'last_on' => now()->subYear()->toDateString(), 'last_quantity' => 3,
            'last_documents' => [], 'first_on' => now()->subYear()->toDateString(), 'computed_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(['inspections.view', 'inspections.offer']);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inspections/customers/3830/email-search')->assertOk();
        $this->assertSame(3, $res->json('found'));
        $this->assertSame('biuro@go-kom.pl', $res->json('suggestions.0.email'));
        $this->assertSame(3, $res->json('lookup.found'));
        $this->assertContains('panoramafirm.pl', array_column($res->json('pages'), 'host'));
        $this->postJson('/api/inspections/customers/9999/email-search')->assertNotFound();

        // przed zatwierdzeniem: klient bez adresu, z propozycjami
        $this->getJson('/api/inspections?with_email=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inspections')->assertOk()
            ->assertJsonPath('data.0.customer.emails', [])
            ->assertJsonPath('data.0.customer.pending_email_suggestions', 3);

        $id = CustomerEmailSuggestion::query()->where('email', 'biuro@go-kom.pl')->value('id');
        $this->patchJson("/api/inspections/email-suggestions/{$id}", ['status' => 'accepted'])->assertOk()
            ->assertJsonPath('suggestions.2.status', 'accepted');
        $this->patchJson("/api/inspections/email-suggestions/{$id}", ['status' => 'zle'])->assertStatus(422);

        $this->getJson('/api/inspections?with_email=1')->assertOk()
            ->assertJsonPath('data.0.customer.emails', ['biuro@go-kom.pl'])
            ->assertJsonPath('data.0.customer.web_emails', ['biuro@go-kom.pl'])
            ->assertJsonPath('data.0.customer.pending_email_suggestions', 2);
        $this->getJson('/api/inspections/customers/3830')->assertOk()
            ->assertJsonPath('email_suggestions.2.decided_by_name', $user->name);

        // oferta przeglądu dostaje zatwierdzony adres
        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [3830]])->assertCreated()
            ->assertJsonPath('offers.0.emails', ['biuro@go-kom.pl']);
    }

    public function test_command_checks_customers_without_address_once_per_period(): void
    {
        $this->customer();
        $this->customer(['xl_gid' => 3831, 'acronym' => 'Z ADRESEM', 'name' => 'Z ADRESEM', 'emails' => ['a@b.pl']]);
        $position = InspectionPosition::query()->create([
            'xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GP-6', 'interval_months' => 12,
        ]);
        foreach ([3830, 3831] as $gid) {
            InspectionDue::query()->create([
                'customer_xl_gid' => $gid, 'inspection_position_id' => $position->id, 'due_on' => now()->addDays(5)->toDateString(),
                'open_count' => 1, 'open_quantity' => 3, 'last_on' => now()->subYear()->toDateString(), 'last_quantity' => 3,
                'last_documents' => [], 'first_on' => now()->subYear()->toDateString(), 'computed_at' => now(),
            ]);
        }

        $this->artisan('inspections:find-emails')
            ->expectsOutputToContain('Klienci sprawdzeni: 1, z nowymi propozycjami adresu: 1, propozycji: 3, błędy wyszukiwarki: 0.')
            ->assertSuccessful();
        // sprawdzony niedawno — drugi raz nie
        $this->artisan('inspections:find-emails')
            ->expectsOutputToContain('Klienci sprawdzeni: 0')
            ->assertSuccessful();
    }
}
