<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\OfferComposeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Otwórz w Thunderbirdzie” z okna oferty dla klienta: prośba zapisana przez przeglądarkę, odebrana przez dodatek
 * przy pytaniu o kolejkę (with_offers=1), podjęta dokładnie raz.
 */
class OfferComposeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Oferta: Okulary sportstyle 9193.265',
            'body_html' => '<table><tr><td>Oferta handlowa</td></tr></table>',
            'body_text' => 'OFERTA HANDLOWA',
        ], $overrides);
    }

    public function test_offer_goes_from_the_app_to_the_addon_once(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/offers/compose', $this->payload())
            ->assertCreated()
            ->assertJsonPath('claimed_at', null)
            ->json('id');

        // dodatek 1.24.0+: obiekt z zapytaniami i ofertami, pyta szybko, bo prośba czeka
        $user->forceFill(['last_seen_at' => null])->save();
        $this->getJson('/api/inquiries/queued?with_offers=1')
            ->assertOk()
            ->assertExactJson([
                'inquiries' => [],
                'offers' => [[
                    'id' => $id,
                    'subject' => 'Oferta: Okulary sportstyle 9193.265',
                    'body_html' => '<table><tr><td>Oferta handlowa</td></tr></table>',
                    'body_text' => 'OFERTA HANDLOWA',
                    'requested_at' => OfferComposeRequest::query()->findOrFail($id)->requested_at->toIso8601String(),
                ]],
            ])
            ->assertHeader('X-Poll-After', '5');

        $this->postJson("/api/offers/compose/{$id}/claim")->assertOk()->assertJsonPath('id', $id);
        // drugi Thunderbird na tym samym koncie nie otworzy tej samej oferty
        $this->postJson("/api/offers/compose/{$id}/claim")->assertStatus(409);

        $this->getJson("/api/offers/compose/{$id}")->assertOk()->assertJsonPath('claimed_at', fn ($v) => is_string($v));
        $this->getJson('/api/inquiries/queued?with_offers=1')->assertOk()->assertJsonPath('offers', []);
    }

    public function test_older_addon_keeps_the_plain_array_and_never_sees_offers(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'czeka@poczta.example',
            'source_body' => 'Proszę o wycenę.',
            'analysis' => [],
            'answers' => [],
            'reply_body' => 'Dzień dobry.',
            'send_requested_at' => now(),
        ]);
        $this->postJson('/api/offers/compose', $this->payload())->assertCreated();

        $this->getJson('/api/inquiries/queued')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $inquiry->id);
        // starszy dodatek nie zostawia znacznika — przycisk w oknie oferty się nie pokaże
        $this->getJson('/api/offers/compose/status')->assertOk()->assertJsonPath('addon_ready', false);
        $this->getJson('/api/inquiries/queued?with_offers=1')
            ->assertOk()
            ->assertJsonPath('inquiries.0.id', $inquiry->id)
            ->assertJsonCount(1, 'offers');
    }

    public function test_status_follows_the_new_addon_polls(): void
    {
        $this->freezeSecond();
        $user = User::factory()->withRole('handlowiec')->create();
        // dwie sesje jak na produkcji, prawdziwe tokeny: Sanctum::actingAs trzyma jeden obiekt użytkownika
        // i nie widzi znacznika zapisanego w bazie przez pytanie dodatku
        $addon = $user->createToken('thunderbird')->plainTextToken;
        $spa = $user->createToken('spa')->plainTextToken;
        $status = function () use ($spa) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($spa)->getJson('/api/offers/compose/status')->assertOk();
        };
        $poll = function () use ($addon): void {
            $this->app['auth']->forgetGuards();
            $this->withToken($addon)->getJson('/api/inquiries/queued?with_offers=1')->assertOk();
        };

        $status()->assertExactJson(['addon_ready' => false, 'addon_seen_at' => null]);

        $poll();
        $status()
            ->assertJsonPath('addon_ready', true)
            ->assertJsonPath('addon_seen_at', now()->toIso8601String());

        // znacznik odświeżany najwyżej raz na minutę
        $this->travel(30)->seconds();
        $poll();
        $this->assertTrue($user->fresh()->thunderbird_offers_seen_at->eq(now()->subSeconds(30)));
        $this->travel(31)->seconds();
        $poll();
        $this->assertTrue($user->fresh()->thunderbird_offers_seen_at->eq(now()));

        // granica włącznie: 3 minuty ciszy to jeszcze „dodatek działa”, 3 min i sekunda — już nie
        $this->travel(3)->minutes();
        $status()->assertJsonPath('addon_ready', true);
        $this->travel(1)->seconds();
        $status()->assertJsonPath('addon_ready', false);
    }

    public function test_stale_requests_are_not_opened_later(): void
    {
        $this->freezeSecond();
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/offers/compose', $this->payload())->assertCreated()->json('id');

        $this->travel(OfferComposeRequest::PENDING_MINUTES)->minutes();
        $this->getJson('/api/inquiries/queued?with_offers=1')->assertJsonPath('offers.0.id', $id);
        $this->travel(1)->seconds();
        $this->getJson('/api/inquiries/queued?with_offers=1')->assertJsonPath('offers', []);
    }

    public function test_requests_do_not_leak_between_users(): void
    {
        $mine = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $theirs = OfferComposeRequest::query()->create([
            'user_id' => $other->id,
            'subject' => 'Cudza oferta',
            'body_html' => '<p>cudza</p>',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($mine);

        $this->getJson('/api/inquiries/queued?with_offers=1')->assertOk()->assertJsonPath('offers', []);
        $this->getJson("/api/offers/compose/{$theirs->id}")->assertStatus(403);
        $this->postJson("/api/offers/compose/{$theirs->id}/claim")->assertStatus(403);
        $this->assertNull($theirs->fresh()->claimed_at);
    }

    public function test_store_validates_the_offer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson('/api/offers/compose', $this->payload(['subject' => '', 'body_html' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'body_html']);
        $this->postJson('/api/offers/compose', $this->payload(['body_html' => str_repeat('x', 600_001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body_html']);
        $this->postJson('/api/offers/compose', $this->payload(['product_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_offers_need_the_inquiries_permission(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([]);
        $user->syncPermissions([]);
        Sanctum::actingAs($user);

        $this->postJson('/api/offers/compose', $this->payload())->assertStatus(403);
        $this->getJson('/api/offers/compose/status')->assertStatus(403);
    }
}
