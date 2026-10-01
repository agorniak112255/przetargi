<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Odbiorcy kampanii: grupy ∪ klienci XL, duplikaty, adresy ogólne, wypisani, limit częstotliwości. */
final class AudienceResolverTest extends TestCase
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

    public function test_pipeline_counts_and_list_wins_over_xl_for_the_same_address(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417');
        $own = $this->mailingList($author, ['Wspolny@Firma.pl', 'jan@alfa.pl', 'zly-adres', 'faktury@beta.pl']);
        $shared = $this->mailingList(User::factory()->create(), ['jan@alfa.pl', 'wypisany@gamma.pl'], shared: true);
        $foreign = $this->mailingList(User::factory()->create(), ['cudzy@obcy.pl']);
        $this->customer('BETA', ['wspolny@firma.pl', 'faktury@beta.pl', 'kontakt@beta.pl'], [$item->id => '2026-05-01']);
        $this->customer('DAWNO', ['dawno@delta.pl'], [$item->id => '2024-01-01']);
        EmailSuppression::query()->create(['email' => 'wypisany@gamma.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);

        // adres, który dostał inną kampanię 5 dni temu — limit częstotliwości; 20 dni temu — już nie
        $other = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(20)]);
        foreach (['kontakt@beta.pl' => 5, 'jan@alfa.pl' => 20] as $email => $days) {
            CampaignRecipient::query()->create([
                'campaign_id' => $other->id, 'email' => $email, 'source' => 'list', 'token' => str_pad((string) $days, 40, 'x'),
                'status' => 'sent', 'sent_at' => now()->subDays($days),
            ]);
        }

        $campaign = $this->campaign($author, [$item], ['audience' => [
            'list_ids' => [$own->id, $shared->id, $foreign->id],
            'xl' => ['mode' => 'items', 'months' => 12, 'only_mine' => false],
        ]]);
        $p = app(AudienceResolver::class)->preview($campaign);

        // cudza prywatna grupa pominięta; klient kupujący 2024 poza 12 mies.
        $this->assertSame(6, $p['lists']['contacts']);
        $this->assertSame(['customers' => 1, 'emails' => 3], $p['xl']);
        $this->assertSame(9, $p['total_raw']);
        // jan@alfa.pl w dwóch grupach, wspolny@ i faktury@beta.pl także w XL
        $this->assertSame(3, $p['duplicates']);
        $this->assertSame(1, $p['invalid']);
        // faktury@ z grupy zostaje (wpisany ręcznie), z XL byłby odrzucony — tu już jest duplikatem
        $this->assertSame(0, $p['excluded_generic']);
        $this->assertSame(1, $p['suppressed']);
        $this->assertSame(1, $p['capped']);
        $this->assertSame(3, $p['final']);
        $this->assertFalse($p['without_mailbox']);
        $sample = collect($p['sample'])->keyBy('email');
        $this->assertSame(['wspolny@firma.pl', 'jan@alfa.pl', 'faktury@beta.pl'], $sample->keys()->all());
        // ten sam adres w grupie i w XL — źródło „list”
        $this->assertSame('list', $sample['wspolny@firma.pl']['source']);
        $this->assertNotContains('cudzy@obcy.pl', $sample->keys()->all());
    }

    public function test_generic_xl_addresses_are_skipped_and_biggest_customer_wins_duplicate(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('S40562');
        $this->customer('MALY', ['biuro@wspolna.pl'], [$item->id => '2026-08-01'], ['sale_documents_24m' => 2]);
        $big = $this->customer('DUZY', ['biuro@wspolna.pl', 'Faktury@duzy.pl', 'ksiegowosc@duzy.pl', 'zakupy@duzy.pl'], [$item->id => '2026-08-01'], ['sale_documents_24m' => 40]);
        $this->customer('ARCH', ['arch@x.pl'], [$item->id => '2026-08-01'], ['archived' => true]);
        $this->customer('USUN', ['usun@x.pl'], [$item->id => '2026-08-01'], ['removed_at' => now()]);

        $campaign = $this->campaign($author, [$item], ['audience' => ['xl' => ['mode' => 'items', 'months' => 24]]]);
        $p = app(AudienceResolver::class)->preview($campaign);

        $this->assertSame(['customers' => 2, 'emails' => 5], $p['xl']);
        $this->assertSame(2, $p['excluded_generic']);
        $this->assertSame(1, $p['duplicates']);
        $this->assertSame(['biuro@wspolna.pl', 'zakupy@duzy.pl'], array_column($p['sample'], 'email'));

        $this->assertSame(2, app(AudienceResolver::class)->materialize($campaign));
        $row = CampaignRecipient::query()->where('email', 'biuro@wspolna.pl')->firstOrFail();
        $this->assertSame($big->id, $row->erp_customer_id);
        $this->assertSame('xl', $row->source);
        $this->assertSame('pending', $row->status);
        $this->assertSame(40, strlen($row->token));
        // drugi raz nie dubluje (insertOrIgnore)
        $this->assertSame(2, app(AudienceResolver::class)->materialize($campaign));
    }

    public function test_frequency_cap_counts_addresses_waiting_in_another_sending_campaign(): void
    {
        $author = $this->sender();
        $other = $this->sender();
        $item = $this->erpItem('B20417');
        // drugi handlowiec wysyła tego samego dnia: jego odbiorcy czekają (pending) albo są w trakcie (sending)
        $sending = $this->campaign($other, [$item], ['status' => 'sending', 'sending_started_at' => now()]);
        // anulowana kampania z niewysłanym odbiorcą nie blokuje
        $cancelled = $this->campaign($other, [$item], ['status' => 'cancelled']);
        $rows = [
            [$sending->id, 'czeka@a.pl', 'pending'], [$sending->id, 'w-trakcie@a.pl', 'sending'], [$sending->id, 'blad@a.pl', 'failed'],
            [$cancelled->id, 'anulowany@a.pl', 'skipped'], [$cancelled->id, 'anulowany2@a.pl', 'pending'],
        ];
        foreach ($rows as $i => [$campaignId, $email, $status]) {
            CampaignRecipient::query()->create([
                'campaign_id' => $campaignId, 'email' => $email, 'source' => 'list', 'token' => str_pad((string) $i, 40, 'y'), 'status' => $status,
            ]);
        }
        $list = $this->mailingList($author, ['czeka@a.pl', 'w-trakcie@a.pl', 'blad@a.pl', 'anulowany@a.pl', 'anulowany2@a.pl', 'nowy@a.pl']);
        $campaign = $this->campaign($author, [$item], ['audience' => ['list_ids' => [$list->id]]]);

        $p = app(AudienceResolver::class)->preview($campaign);

        $this->assertSame(2, $p['capped']);
        $this->assertSame(['blad@a.pl', 'anulowany@a.pl', 'anulowany2@a.pl', 'nowy@a.pl'], array_column($p['sample'], 'email'));

        // przy starcie adresy czekające w tamtej kampanii są zapisane jako odbiorcy warunkowi (suma kontrolna bez nich)
        $checksum = AudienceResolver::checksum(['blad@a.pl', 'anulowany@a.pl', 'anulowany2@a.pl', 'nowy@a.pl']);
        $this->assertSame(6, app(AudienceResolver::class)->materialize($campaign, $checksum));
        $this->assertSame(
            ['czeka@a.pl', 'w-trakcie@a.pl'],
            CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereIn('email', ['czeka@a.pl', 'w-trakcie@a.pl'])->where('status', 'pending')->orderBy('email')->pluck('email')->all(),
        );
    }

    public function test_without_frequency_cap_addresses_waiting_elsewhere_are_ordinary_recipients(): void
    {
        config(['campaigns.frequency_cap_days' => 0]);
        $item = $this->erpItem('B20417');
        $sending = $this->campaign($this->sender(), [$item], ['status' => 'sending', 'sending_started_at' => now()]);
        CampaignRecipient::query()->create([
            'campaign_id' => $sending->id, 'email' => 'czeka@a.pl', 'source' => 'list', 'token' => str_repeat('z', 40), 'status' => 'pending',
        ]);
        $author = $this->sender();
        $campaign = $this->campaign($author, [$item], ['audience' => ['list_ids' => [$this->mailingList($author, ['czeka@a.pl'])->id]]]);

        $p = app(AudienceResolver::class)->preview($campaign);

        $this->assertSame(0, $p['capped']);
        $this->assertSame(['czeka@a.pl'], array_column($p['sample'], 'email'));
    }

    public function test_group_and_mine_modes_and_only_mine_without_operator(): void
    {
        $author = $this->sender(operator: 'JKOWAL');
        $boots = $this->erpItem('B20417');
        $otherBoots = $this->erpItem('B99999');
        $gloves = $this->erpItem('S40562');
        $this->customer('OBUWIE', ['obuwie@a.pl'], [$otherBoots->id => '2026-06-01'], ['main_operator' => 'JKOWAL']);
        $this->customer('REKAWICE', ['rekawice@b.pl'], [$gloves->id => '2026-06-01'], ['main_operator' => 'INNY']);
        $this->customer('MOJ', ['moj@c.pl'], [], ['main_operator' => 'JKOWAL', 'last_sale_at' => '2026-02-01']);
        $this->customer('MOJ-STARY', ['stary@c.pl'], [], ['main_operator' => 'JKOWAL', 'last_sale_at' => '2025-01-01']);

        $resolver = app(AudienceResolver::class);
        $group = $this->campaign($author, [$boots], ['audience' => ['xl' => ['mode' => 'group', 'months' => 24]]]);
        $this->assertSame(['obuwie@a.pl'], array_column($resolver->preview($group)['sample'], 'email'));

        $mine = $this->campaign($author, [$boots], ['audience' => ['xl' => ['mode' => 'mine', 'months' => 12]]]);
        $this->assertSame(['obuwie@a.pl', 'moj@c.pl'], array_column($resolver->preview($mine)['sample'], 'email'));

        $onlyMine = $this->campaign($author, [$boots, $gloves], ['audience' => ['xl' => ['mode' => 'group', 'months' => 24, 'only_mine' => true]]]);
        $this->assertSame(['obuwie@a.pl'], array_column($resolver->preview($onlyMine)['sample'], 'email'));

        // autor bez operatora XL: 0 z XL i ostrzeżenie
        $noOperator = $this->sender();
        $campaign = $this->campaign($noOperator, [$boots], ['audience' => ['xl' => ['mode' => 'items', 'months' => 24, 'only_mine' => true]]]);
        $p = $resolver->preview($campaign);
        $this->assertSame(0, $p['final']);
        $this->assertSame(['customers' => 0, 'emails' => 0], $p['xl']);
        $this->assertNotEmpty($p['warnings']);
    }
}
