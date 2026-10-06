<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerEmailLookup;
use App\Models\CustomerEmailSuggestion;
use App\Models\ErpCustomer;
use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use App\Models\User;
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

    /** @var array<string, string> adres strony → treść z czytnika */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['enrichment.reader_api_key' => 'jina_test_key', 'enrichment.reader_min_interval' => 0]);
        Cache::flush();
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
                    ['url' => 'https://www.facebook.com/gokom', 'title' => 'GOKOM | Facebook', 'description' => 'kontakt@facebook.com'],
                    ['url' => 'https://aleo.com/pl/firma/inna', 'title' => 'Inna', 'description' => ''],
                ]], 200);
            }
            if (str_starts_with($url, 'https://r.jina.ai/')) {
                $page = substr($url, strlen('https://r.jina.ai/'));

                return isset($this->pages[$page]) ? Http::response($this->pages[$page], 200) : Http::response('Not found', 404);
            }

            return Http::response('', 500);
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
        Http::assertNotSent(static fn (HttpRequest $r): bool => str_contains($r->url(), 'r.jina.ai/https://www.gowork.pl'));
        // co sprawdzono: strona firmy, kontakt (z NIP-em), katalog z NIP-em, katalog innej firmy
        $this->assertSame(
            ['go-kom.pl' => [true, false], 'go-kom.pl/kontakt' => [true, true], 'aleo.com' => [true, false], 'panoramafirm.pl' => [true, true]],
            collect($result['pages'])->mapWithKeys(static fn (array $p): array => [
                $p['host'].(str_ends_with($p['url'], '/kontakt') ? '/kontakt' : '') => [$p['read'], $p['nip']],
            ])->all(),
        );
        $lookup = CustomerEmailLookup::query()->where('customer_xl_gid', 3830)->firstOrFail();
        $this->assertSame(3, $lookup->found);
        $this->assertNull($lookup->error);
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
