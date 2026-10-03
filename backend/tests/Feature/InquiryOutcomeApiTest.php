<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\InquiryOrderHint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PUT /api/inquiries/{id}/outcome i PUT /api/inquiries/{id}/client oraz nowe pola GET /api/inquiries/{id}: wynik wpisuje
 * tylko autor po wysłaniu odpowiedzi; podpowiedź z ERP XL tylko kopiuje dokument wskazany przez człowieka.
 */
final class InquiryOutcomeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
        $this->author = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski']);
    }

    public function test_only_author_saves_outcome_and_only_after_reply(): void
    {
        $inquiry = $this->inquiry(replied: false);
        $manager = User::factory()->withRole('kierownik')->create();

        Sanctum::actingAs($manager);
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'ordered', 'reason' => null])->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'ordered', 'reason' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');
        $this->assertNull($inquiry->fresh()->outcome);

        $inquiry->forceFill(['replied_at' => now()->subDay()])->save();
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'not_ordered', 'reason' => 'price'])
            ->assertOk()
            ->assertJsonPath('outcome', 'not_ordered')
            ->assertJsonPath('reason', 'price')
            ->assertJsonPath('by', ['id' => $this->author->id, 'name' => 'Piotr Wiśniewski'])
            ->assertJsonPath('document', null)
            ->assertJsonPath('can_edit', true);
        $fresh = $inquiry->fresh();
        $this->assertSame([$this->author->id, '2026-10-03 08:00:00'], [$fresh->outcome_by, $fresh->outcome_at?->utc()->format('Y-m-d H:i:s')]);

        // podgląd kierownika: wynik widać, edytować nie może
        Sanctum::actingAs($manager);
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()
            ->assertJsonPath('outcome.outcome', 'not_ordered')
            ->assertJsonPath('outcome.can_edit', false);

        // null czyści wynik razem z autorem i chwilą
        Sanctum::actingAs($this->author);
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => null, 'reason' => null])->assertOk()
            ->assertJsonPath('outcome', null)
            ->assertJsonPath('by', null)
            ->assertJsonPath('at', null);
    }

    public function test_reason_only_with_partial_or_not_ordered_and_values_are_checked(): void
    {
        $inquiry = $this->inquiry();
        Sanctum::actingAs($this->author);

        foreach (['ordered', 'unknown'] as $outcome) {
            $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => $outcome, 'reason' => 'price'])
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'won', 'reason' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'partial', 'reason' => 'weather'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        // brak klucza outcome to błąd — null trzeba wysłać świadomie
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['reason' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');

        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'partial', 'reason' => 'lead_time'])->assertOk();
        // powód bez wyboru też jest dozwolony
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'not_ordered', 'reason' => null])->assertOk()
            ->assertJsonPath('reason', null);
    }

    public function test_confirming_hint_copies_document_and_foreign_hint_is_rejected(): void
    {
        $inquiry = $this->inquiry();
        $other = $this->inquiry();
        $hint = $this->hint($inquiry, 1842, '6240.00');
        $foreign = $this->hint($other, 1900, '50.00');
        Sanctum::actingAs($this->author);

        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'partial', 'reason' => 'price', 'hint_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('hint_id');
        $this->assertNull($inquiry->fresh()->outcome, 'Odrzucone żądanie niczego nie zapisuje.');
        // dokument tylko przy zakupie
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'not_ordered', 'reason' => null, 'hint_id' => $hint->id])
            ->assertUnprocessable()->assertJsonValidationErrors('hint_id');

        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'partial', 'reason' => 'price', 'hint_id' => $hint->id])
            ->assertOk()
            ->assertJsonPath('document', ['number' => 'FS-1842/09/2026', 'date' => '2026-09-22', 'net_value' => '6240.00']);

        // zmiana samego powodu (bez klucza hint_id) zostawia dokument; zmiana na „nie zamówił” go zdejmuje
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'partial', 'reason' => 'lead_time'])
            ->assertOk()->assertJsonPath('document.number', 'FS-1842/09/2026');
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'ordered', 'reason' => null, 'hint_id' => null])
            ->assertOk()->assertJsonPath('document', null);
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'ordered', 'reason' => null, 'hint_id' => $hint->id])->assertOk();
        $this->putJson("/api/inquiries/{$inquiry->id}/outcome", ['outcome' => 'not_ordered', 'reason' => 'bought_elsewhere'])
            ->assertOk()->assertJsonPath('document', null);
        $this->assertNull($inquiry->fresh()->outcome_net_value);
        // podpowiedź zostaje — to ślad wniosku, nie wynik
        $this->assertTrue(InquiryOrderHint::query()->whereKey($hint->id)->exists());
    }

    public function test_detail_shows_validity_link_and_hints_with_reasons(): void
    {
        config(['erpxl.enabled' => true]);
        $client = Client::query()->create(['name' => 'Ciepłownia Wisłok', 'xl_gid' => 7001]);
        $inquiry = $this->inquiry(attrs: [
            'offer_terms' => ['validity' => '14 dni'],
            'replied_at' => CarbonImmutable::parse('2026-09-14 16:00', 'Europe/Warsaw')->utc(),
            'client_id' => $client->id,
            'client_link_source' => 'email',
        ]);
        $this->hint($inquiry, 1842, '6240.00');
        Sanctum::actingAs($this->author);

        $json = $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()->json();
        $this->assertSame('2026-09-28', $json['offer_valid_until']);
        $this->assertSame('14 dni', $json['validity_text']);
        $this->assertSame(['client' => ['id' => $client->id, 'name' => 'Ciepłownia Wisłok'], 'source' => 'email'], $json['client_link']);
        $this->assertSame('ok', $json['order_hints']['status']);
        $this->assertStringContainsString('wniosek', $json['order_hints']['rule']);
        $this->assertSame(['FS-1842/09/2026'], array_column($json['order_hints']['hints'], 'document_number'));
        $this->assertSame([5, 3, 3], [$json['order_hints']['hints'][0]['offered_items'], $json['order_hints']['hints'][0]['linked_items'], $json['order_hints']['hints'][0]['matched_items']]);
        $this->assertTrue($json['outcome']['can_edit']);

        // ERP XL wyłączony — powód zamiast podpowiedzi
        config(['erpxl.enabled' => false]);
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertJsonPath('order_hints.status', 'no_xl')->assertJsonPath('order_hints.hints', []);

        // klient spoza ERP XL i brak klienta
        $manual = Client::query()->create(['name' => 'Klient ręczny']);
        $inquiry->forceFill(['client_id' => $manual->id, 'client_link_source' => 'manual'])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertJsonPath('order_hints.status', 'no_client');
        $inquiry->forceFill(['client_id' => null, 'client_link_source' => null])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertJsonPath('order_hints.status', 'no_client')->assertJsonPath('client_link', null);

        // przed wysłaniem odpowiedzi: bez ważności i bez podpowiedzi
        $inquiry->forceFill(['replied_at' => null])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertJsonPath('order_hints.status', 'not_replied')
            ->assertJsonPath('offer_valid_until', null)
            ->assertJsonPath('outcome.can_edit', false);
    }

    public function test_unreadable_validity_gives_no_date(): void
    {
        $inquiry = $this->inquiry(attrs: ['offer_terms' => ['validity' => 'do wyczerpania zapasów']]);
        Sanctum::actingAs($this->author);

        $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()
            ->assertJsonPath('offer_valid_until', null)
            ->assertJsonPath('validity_text', 'do wyczerpania zapasów');
    }

    public function test_author_links_client_manually_and_nightly_linking_keeps_it(): void
    {
        $acme = Client::query()->create(['name' => 'ACME', 'emails' => ['zakupy@acme.pl']]);
        $beta = Client::query()->create(['name' => 'BETA']);
        $inquiry = $this->inquiry(attrs: ['source_from_email' => 'zakupy@acme.pl']);
        $this->hint($inquiry, 1, '10.00');

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->putJson("/api/inquiries/{$inquiry->id}/client", ['client_id' => $beta->id])->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->putJson("/api/inquiries/{$inquiry->id}/client", ['client_id' => 999999])->assertUnprocessable();
        $this->putJson("/api/inquiries/{$inquiry->id}/client", ['client_id' => $beta->id])
            ->assertOk()
            ->assertJsonPath('client_link', ['client' => ['id' => $beta->id, 'name' => 'BETA'], 'source' => 'manual']);
        // podpowiedzi liczone dla poprzedniego klienta znikają
        $this->assertSame(0, InquiryOrderHint::query()->count());

        // nocne powiązanie po adresie e-mail nie nadpisuje wyboru handlowca
        $this->artisan('inquiries:order-hints')->assertSuccessful();
        $this->assertSame($beta->id, $inquiry->fresh()->client_id);

        // świadomie bez klienta — też ręczne, automat już go nie powiąże
        $this->putJson("/api/inquiries/{$inquiry->id}/client", ['client_id' => null])->assertOk()->assertJsonPath('client_link', null);
        $this->artisan('inquiries:order-hints')->assertSuccessful();
        $this->assertSame([null, 'manual'], [$inquiry->fresh()->client_id, $inquiry->fresh()->client_link_source]);
        $this->assertNotSame($acme->id, $inquiry->fresh()->client_id);
    }

    public function test_client_chosen_at_creation_is_manual_link(): void
    {
        $client = Client::query()->create(['name' => 'ACME']);
        Sanctum::actingAs($this->author);

        $id = $this->postJson('/api/inquiries', ['body' => 'Proszę o ofertę na rękawice nitrylowe 100 szt', 'tone' => 'formal', 'client_id' => $client->id])
            ->assertCreated()->json('id');

        $this->assertSame('manual', ClientInquiry::query()->findOrFail($id)->client_link_source);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function inquiry(bool $replied = true, array $attrs = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $this->author->id,
            'tone' => 'formal',
            'source_channel' => 'web',
            'source_subject' => 'Kombinezony i rękawice',
            'source_body' => 'Proszę o ofertę.',
            'analysis' => [],
        ]);
        $inquiry->forceFill([
            'replied_at' => $replied ? CarbonImmutable::parse('2026-09-14 16:00', 'Europe/Warsaw')->utc() : null,
            ...$attrs,
        ])->save();

        return $inquiry;
    }

    private function hint(ClientInquiry $inquiry, int $documentId, string $net): InquiryOrderHint
    {
        return InquiryOrderHint::query()->create([
            'client_inquiry_id' => $inquiry->id, 'document_type' => 2033, 'document_id' => $documentId,
            'document_number' => 'FS-'.$documentId.'/09/2026', 'issued_at' => '2026-09-22', 'document_net' => $net,
            'matched_net' => $net, 'offered_items' => 5, 'linked_items' => 3, 'matched_items' => 3, 'computed_at' => now(),
        ]);
    }
}
