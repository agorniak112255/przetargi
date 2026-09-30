<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Publiczny wypis z mailingu: GET tylko pyta, POST (przycisk albo One-Click RFC 8058) wypisuje. */
final class CampaignUnsubscribeTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private CampaignRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpCampaigns();
        $campaign = $this->campaign($this->sender(), [$this->erpItem('B1')], ['status' => 'sending']);
        $this->recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'Jan.Nowak@Firma.pl', 'source' => 'xl', 'token' => str_repeat('T', 40), 'status' => 'sent',
        ]);
    }

    public function test_get_shows_masked_address_and_does_not_unsubscribe(): void
    {
        $res = $this->get('/api/wypis/'.str_repeat('T', 40))->assertOk();

        $res->assertSee('J***@Firma.pl')->assertSee('Wypisz mnie')->assertDontSee('Jan.Nowak');
        $this->assertStringNotContainsString('<script', (string) $res->getContent());
        $this->assertSame(0, EmailSuppression::query()->count());
        $this->assertNull($this->recipient->fresh()->unsubscribed_at);
    }

    public function test_post_unsubscribes_idempotently_including_one_click(): void
    {
        $url = '/api/wypis/'.str_repeat('T', 40);

        // program pocztowy (RFC 8058) wysyła treść List-Unsubscribe=One-Click
        $this->call('POST', $url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Wypisano');
        $suppression = EmailSuppression::query()->sole();
        $this->assertSame('jan.nowak@firma.pl', $suppression->email);
        $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE, $suppression->reason);
        $this->assertSame($this->recipient->campaign_id, $suppression->campaign_id);
        $first = $this->recipient->fresh()->unsubscribed_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->post($url)->assertOk()->assertSee('Wypisano');
        $this->assertSame(1, EmailSuppression::query()->count());
        $this->assertEquals($first, $this->recipient->fresh()->unsubscribed_at);

        // GET po wypisie: informacja, bez ponownego przycisku
        $this->get($url)->assertOk()->assertSee('jest już wypisany')->assertDontSee('Wypisz mnie</button>', false);
    }

    public function test_parallel_unsubscribe_of_the_same_address_does_not_fail(): void
    {
        // przycisk i List-Unsubscribe-Post z programu pocztowego naraz: drugi wpisuje adres między odczytem a zapisem
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'from "email_suppressions"')) {
                $armed = false;
                DB::table('email_suppressions')->insert([
                    'email' => 'jan.nowak@firma.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE,
                    'campaign_id' => $this->recipient->campaign_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->post('/api/wypis/'.str_repeat('T', 40))->assertOk()->assertSee('Wypisano');

        $this->assertFalse($armed);
        $this->assertSame(1, EmailSuppression::query()->count());
        $this->assertNotNull($this->recipient->fresh()->unsubscribed_at);
    }

    public function test_unknown_token_shows_generic_page_without_details(): void
    {
        foreach (['get', 'post'] as $method) {
            $res = $this->{$method}('/api/wypis/'.str_repeat('X', 40))->assertNotFound();
            $res->assertSee('Nie znaleziono linku')->assertDontSee('@');
        }
        $this->assertSame(0, EmailSuppression::query()->count());
        // zły format tokenu nie trafia do kontrolera
        $this->get('/api/wypis/abc')->assertNotFound();
    }
}
