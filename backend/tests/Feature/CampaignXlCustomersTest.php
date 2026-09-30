<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Okno „Pokaż / wybierz” klientów XL: lista kategorii, zapis wybranych, zawężenie odbiorców do zaznaczonych. */
final class CampaignXlCustomersTest extends TestCase
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

    public function test_lists_category_with_email_flags_ids_search_and_sort(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417');
        $other = $this->erpItem('A11873');
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl', 'faktury@alfa.pl'], [$item->id => '2026-05-01'], ['sale_documents_24m' => 3]);
        $beta = $this->customer('BETA', ['jan@beta.pl'], [$item->id => '2026-08-01', $other->id => '2026-08-01'], ['sale_documents_24m' => 9, 'city' => 'Rzeszów']);
        $this->customer('DAWNO', ['dawno@delta.pl'], [$item->id => '2023-01-01']);
        $this->customer('INNY', ['inny@gamma.pl'], [$other->id => '2026-08-01']);
        EmailSuppression::query()->create(['email' => 'jan@beta.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);
        $campaign = $this->campaign($author, [$item]);
        Sanctum::actingAs($author);

        $res = $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items&months=24")->assertOk();
        // domyślnie od największej liczby dokumentów; klient sprzed 24 mies. i kupujący inny towar poza kategorią
        $this->assertSame(['BETA', 'ALFA'], array_column($res->json('data'), 'acronym'));
        $this->assertEqualsCanonicalizing([$alfa->id, $beta->id], $res->json('ids'));
        $this->assertNull($res->json('selected_ids'));
        $this->assertSame(1, $res->json('data.0.matched_items'));
        $this->assertSame([['email' => 'jan@beta.pl', 'skipped' => 'suppressed']], $res->json('data.0.emails'));
        $this->assertSame([
            ['email' => 'zakupy@alfa.pl', 'skipped' => null],
            ['email' => 'faktury@alfa.pl', 'skipped' => 'generic'],
        ], $res->json('data.1.emails'));

        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items&search=rzesz")
            ->assertOk()->assertJsonPath('data.0.acronym', 'BETA')->assertJsonCount(1, 'data');
        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items&sort=acronym&dir=asc")
            ->assertOk()->assertJsonPath('data.0.acronym', 'ALFA');
        // grupa B (obuwie): BETA kupowała też A — i tak jest, INNY kupował tylko A — nie
        $group = $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=group")->assertOk();
        $this->assertEqualsCanonicalizing([$alfa->id, $beta->id], $group->json('ids'));
    }

    public function test_saved_selection_narrows_recipients_and_resets_when_category_changes(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417');
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl'], [$item->id => '2026-05-01']);
        $this->customer('BETA', ['jan@beta.pl'], [$item->id => '2026-08-01']);
        $campaign = $this->campaign($author, [$item]);
        Sanctum::actingAs($author);

        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['xl' => [
            'mode' => 'items', 'months' => 24, 'only_mine' => false, 'customer_ids' => [$alfa->id],
        ]]])->assertOk()->assertJsonPath('audience.xl.customer_ids', [$alfa->id]);

        $this->getJson("/api/campaigns/{$campaign->id}/audience")
            ->assertOk()->assertJsonPath('xl.customers', 1)->assertJsonPath('final', 1)->assertJsonPath('sample.0.email', 'zakupy@alfa.pl');
        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items&months=24")
            ->assertOk()->assertJsonPath('selected_ids', [$alfa->id]);
        // inna kategoria w oknie — zapisany wybór jej nie dotyczy
        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items&months=12")
            ->assertOk()->assertJsonPath('selected_ids', null);

        // zmiana okresu bez nowego wyboru = znowu cała kategoria
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['xl' => ['months' => 12]]])
            ->assertOk()->assertJsonPath('audience.xl.customer_ids', null);
        $this->getJson("/api/campaigns/{$campaign->id}/audience")->assertOk()->assertJsonPath('final', 2);

        // pusty wybór = nikt z XL
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['xl' => ['customer_ids' => []]]])->assertOk();
        $this->getJson("/api/campaigns/{$campaign->id}/audience")->assertOk()->assertJsonPath('final', 0);
    }

    public function test_other_users_campaign_is_hidden_and_mode_is_required(): void
    {
        $campaign = $this->campaign($this->sender(), [$this->erpItem('B20417')]);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers?mode=items")->assertNotFound();

        Sanctum::actingAs($campaign->user);
        $this->getJson("/api/campaigns/{$campaign->id}/xl-customers")->assertUnprocessable();
    }
}
