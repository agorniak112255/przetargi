<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\EmailSuppression;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\MailingList;
use App\Models\Product;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** API kampanii: dostęp, walidacja, pozycje, duplikat, start i anulowanie (usługi podmienione atrapami). */
final class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    /** @var list<array{0: string, 1: mixed}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        self::$calls = [];
        config(['campaigns.public_url' => 'https://przetargi.example.pl']);

        $this->app->instance(CampaignItemPresenter::class, new class extends CampaignItemPresenter
        {
            // bez zależności rodzica — atrapa ich nie używa
            public function __construct() {}

            public function presentMany(iterable $items, User $author): array
            {
                $out = [];
                foreach ($items as $item) {
                    $out[] = ['id' => $item->id, 'position' => $item->position, 'erp_item_id' => $item->erp_item_id, 'product_id' => $item->product_id, 'author' => $author->id];
                }

                return $out;
            }
        });
        $this->app->instance(CampaignRenderer::class, new class extends CampaignRenderer
        {
            // bez zależności rodzica — atrapa ich nie używa
            public function __construct() {}

            public function render(Campaign $campaign, ?CampaignRecipient $recipient = null, ?User $sender = null, ?string $notice = null): array
            {
                CampaignApiTest::$calls[] = ['render', [$campaign->id, $recipient?->id, $sender?->id]];

                return ['subject' => 'Temat: '.$campaign->subject, 'html' => '<p>html</p>', 'text' => 'text'];
            }

            public function renderBlocks(Campaign $campaign, array $blocks, ?string $brandColor, ?CampaignRecipient $recipient = null, ?User $sender = null, ?string $notice = null): array
            {
                CampaignApiTest::$calls[] = ['renderBlocks', [$campaign->id, $blocks, $brandColor, $sender?->id]];

                return ['subject' => 'Temat: '.$campaign->subject, 'html' => '<p>projekt</p>', 'text' => 'text'];
            }
        });
        $this->app->instance(AudienceResolver::class, new class extends AudienceResolver
        {
            // bez zależności rodzica — atrapa ich nie używa
            public function __construct() {}

            public function preview(Campaign $campaign): array
            {
                return ['final' => 7, 'campaign' => $campaign->id];
            }
        });
        $this->app->instance(CampaignSender::class, new class extends CampaignSender
        {
            // bez zależności rodzica — atrapa ich nie używa
            public function __construct() {}

            public function sendTest(Campaign $campaign, string $to): void
            {
                CampaignApiTest::$calls[] = ['sendTest', [$campaign->id, $to]];
            }

            public function start(Campaign $campaign, User $actor, ?string $expectedChecksum = null): Campaign
            {
                CampaignApiTest::$calls[] = $expectedChecksum === null
                    ? ['start', [$campaign->id, $actor->id]]
                    : ['start', [$campaign->id, $actor->id, $expectedChecksum]];
                $campaign->update(['status' => Campaign::STATUS_SENDING, 'sending_started_at' => now()]);

                return $campaign;
            }

            public function cancel(Campaign $campaign): Campaign
            {
                CampaignApiTest::$calls[] = ['cancel', [$campaign->id]];
                $campaign->update(['status' => Campaign::STATUS_CANCELLED]);

                return $campaign;
            }
        });
    }

    public function test_access_author_or_manage_and_scope_all_only_for_manage(): void
    {
        $a = User::factory()->withRole('handlowiec')->create();
        $b = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create();
        $campaign = $this->campaign($a);
        $other = $this->campaign($b);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/campaigns')->assertForbidden();

        Sanctum::actingAs($b);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertNotFound();
        $this->patchJson("/api/campaigns/{$campaign->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/campaigns/{$campaign->id}")->assertNotFound();
        $this->getJson("/api/campaigns/{$campaign->id}/audience")->assertNotFound();
        $this->getJson('/api/campaigns?scope=all')->assertForbidden();
        $this->assertSame([$other->id], array_column($this->getJson('/api/campaigns')->assertOk()->json('data'), 'id'));

        Sanctum::actingAs($admin);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('author.id', $a->id)
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('audience', ['list_ids' => [], 'list_exclusions' => [], 'xl' => ['mode' => null, 'months' => 24, 'only_mine' => false, 'customer_ids' => null]]);
        $this->assertSame([], $this->getJson('/api/campaigns')->json('data'));
        $this->assertEqualsCanonicalizing([$campaign->id, $other->id], array_column($this->getJson('/api/campaigns?scope=all')->json('data'), 'id'));
        $this->getJson("/api/campaigns/{$campaign->id}/audience")->assertOk()->assertExactJson(['final' => 7, 'campaign' => $campaign->id]);
    }

    public function test_store_with_items_default_name_code_and_dedupe(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $x = $this->item('X1');
        $y = $this->item('Y1');
        $linked = $this->item('L1');
        $card = $this->product('SKU-1');
        $novelty = $this->product('NEW-1');
        ErpItemLink::query()->create(['erp_item_id' => $linked->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/campaigns', ['erp_item_ids' => [$x->id, $y->id], 'product_ids' => [$card->id, $novelty->id]])
            ->assertCreated()
            ->assertJsonPath('name', 'Kampania 30.09.2026')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('subject', '');
        $id = $res->json('id');
        $this->assertSame('K-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT), $res->json('code'));
        // karta z pewnym powiązaniem dostaje towar XL; nowość bez XL zostaje samą kartą
        $this->assertSame([
            [1, $x->id, null], [2, $y->id, null], [3, $linked->id, $card->id], [4, null, $novelty->id],
        ], array_map(fn (array $i): array => [$i['position'], $i['erp_item_id'], $i['product_id']], $res->json('items')));
        $this->assertSame($user->id, $res->json('items.0.author'));

        // już obecne pomijane (także karta, której towar XL już jest w kampanii)
        $this->postJson("/api/campaigns/{$id}/items", ['erp_item_ids' => [$x->id, $linked->id], 'product_ids' => [$novelty->id, $card->id]])->assertOk();
        $this->assertSame(4, CampaignItem::query()->where('campaign_id', $id)->count());
    }

    public function test_items_validation_and_max_items(): void
    {
        config(['campaigns.max_items' => 3]);
        $user = User::factory()->withRole('handlowiec')->create();
        $removed = $this->item('GONE');
        $removed->update(['removed_at' => now()]);
        $items = [$this->item('A1'), $this->item('A2'), $this->item('A3'), $this->item('A4')];

        Sanctum::actingAs($user);
        $this->postJson('/api/campaigns', ['erp_item_ids' => [$removed->id]])->assertUnprocessable()->assertJsonValidationErrors('erp_item_ids.0');
        $this->postJson('/api/campaigns', ['product_ids' => [999]])->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');
        $this->assertSame(0, Campaign::query()->count());

        $id = $this->postJson('/api/campaigns', ['name' => 'Rękawice', 'erp_item_ids' => [$items[0]->id, $items[1]->id]])->assertCreated()->json('id');
        $this->postJson("/api/campaigns/{$id}/items", ['erp_item_ids' => [$items[2]->id, $items[3]->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('erp_item_ids');
        $this->assertSame(2, CampaignItem::query()->where('campaign_id', $id)->count());
        $this->postJson("/api/campaigns/{$id}/items", ['erp_item_ids' => [$items[2]->id]])->assertOk()->assertJsonCount(3, 'items');
    }

    public function test_update_fields_audience_and_draft_only(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $mine = MailingList::query()->create(['user_id' => $user->id, 'name' => 'Moja']);
        $shared = MailingList::query()->create(['user_id' => $other->id, 'name' => 'Wspólna', 'is_shared' => true]);
        $foreign = MailingList::query()->create(['user_id' => $other->id, 'name' => 'Cudza']);
        $campaign = $this->campaign($user);

        Sanctum::actingAs($user);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['subject' => "Temat\r\nBcc: x@y.pl"])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['layout' => 'mosaic'])->assertUnprocessable()->assertJsonValidationErrors('layout');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$foreign->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.list_ids');

        $this->patchJson("/api/campaigns/{$campaign->id}", [
            'subject' => 'Wyprzedaż rękawic', 'preheader' => 'Tylko do końca miesiąca', 'layout' => 'list', 'valid_until' => '2026-10-31',
            'audience' => ['list_ids' => [$mine->id, $shared->id], 'xl' => ['mode' => 'items', 'months' => 12]],
        ])->assertOk()
            ->assertJsonPath('subject', 'Wyprzedaż rękawic')
            ->assertJsonPath('layout', 'list')
            ->assertJsonPath('valid_until', '2026-10-31')
            ->assertJsonPath('audience', ['list_ids' => [$mine->id, $shared->id], 'list_exclusions' => [], 'xl' => ['mode' => 'items', 'months' => 12, 'only_mine' => false, 'customer_ids' => null]]);
        // częściowa zmiana odbiorców zostawia resztę
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['xl' => ['only_mine' => true]]])->assertOk()
            ->assertJsonPath('audience', ['list_ids' => [$mine->id, $shared->id], 'list_exclusions' => [], 'xl' => ['mode' => 'items', 'months' => 12, 'only_mine' => true, 'customer_ids' => null]]);

        $campaign->update(['status' => Campaign::STATUS_SENDING]);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['name' => 'X'])->assertUnprocessable()
            ->assertJsonPath('message', 'Kampania została już wysłana — zduplikuj ją, żeby zmienić');
        $this->postJson("/api/campaigns/{$campaign->id}/items", ['erp_item_ids' => [$this->item('Z')->id]])->assertUnprocessable();
        $this->deleteJson("/api/campaigns/{$campaign->id}")->assertUnprocessable();
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('warnings', []);
    }

    public function test_item_update_move_remove_and_item_of_other_campaign(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user);
        [$a, $b, $c] = [$this->addItem($campaign, 1), $this->addItem($campaign, 2), $this->addItem($campaign, 3)];
        $foreignItem = $this->addItem($this->campaign($user), 1);
        $card = $this->product('CARD');

        Sanctum::actingAs($user);
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$foreignItem->id}", ['note' => 'x'])->assertNotFound();
        $this->deleteJson("/api/campaigns/{$campaign->id}/items/{$foreignItem->id}")->assertNotFound();
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$a->id}", ['promo_price_net' => -1])->assertUnprocessable();

        $res = $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [
            'promo_price_net' => 89, 'price_before_net' => 120.5, 'note' => ' ostatnie sztuki ', 'product_id' => $card->id, 'position' => 1,
        ])->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], array_column($res->json('items'), 'id'));
        $this->assertSame([1, 2, 3], array_column($res->json('items'), 'position'));
        $c->refresh();
        $this->assertSame(['89.00', '120.50', 'ostatnie sztuki', $card->id], [$c->promo_price_net, $c->price_before_net, $c->note, $c->product_id]);
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['product_id' => null])->assertOk();
        $this->assertNull($c->refresh()->product_id);
        // krótki opis w mailu: przycięty, pusty = wraca wycinek karty
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['description' => '  Lekkie trzewiki S3  '])->assertOk();
        $this->assertSame('Lekkie trzewiki S3', $c->refresh()->description);
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['description' => str_repeat('x', 301)])->assertUnprocessable();
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['description' => '   '])->assertOk();
        $this->assertNull($c->refresh()->description);
        // drugi przycisk: link https, nazwa i kolor z palety razem; pusty link usuwa wszystkie trzy
        $link = ['link_url' => ' https://sklep.supon.pl/p/1 ', 'link_label' => ' Kup w sklepie ', 'link_color' => '#1f5fa8'];
        $saved = fn (): array => array_values($c->refresh()->only(['link_url', 'link_label', 'link_color']));
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", $link)->assertOk();
        $this->assertSame(['https://sklep.supon.pl/p/1', 'Kup w sklepie', '#1f5fa8'], $saved());
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_url' => 'http://sklep.supon.pl'])->assertUnprocessable()->assertJsonValidationErrors('link_url');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('link_url');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_label' => ''])->assertUnprocessable()->assertJsonValidationErrors('link_label');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_label' => str_repeat('x', 41)])->assertUnprocessable()->assertJsonValidationErrors('link_label');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_label' => "a\nb"])->assertUnprocessable()->assertJsonValidationErrors('link_label');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", [...$link, 'link_color' => '#ffffff'])->assertUnprocessable()->assertJsonValidationErrors('link_color');
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['link_url' => 'https://inny.pl'])->assertUnprocessable()->assertJsonValidationErrors(['link_label', 'link_color']);
        // inne pola pozycji nie ruszają linku; odrzucone zmiany niczego nie zapisały
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['note' => 'x'])->assertOk();
        $this->assertSame(['https://sklep.supon.pl/p/1', 'Kup w sklepie', '#1f5fa8'], $saved());
        $this->patchJson("/api/campaigns/{$campaign->id}/items/{$c->id}", ['link_url' => null, 'link_label' => null, 'link_color' => null])->assertOk();
        $this->assertSame([null, null, null], $saved());

        $res = $this->deleteJson("/api/campaigns/{$campaign->id}/items/{$a->id}")->assertOk();
        $this->assertSame([[$c->id, 1], [$b->id, 2]], array_map(fn (array $i): array => [$i['id'], $i['position']], $res->json('items')));
    }

    public function test_duplicate_copies_content_and_items_not_snapshots(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create();
        $authorList = MailingList::query()->create(['user_id' => $author->id, 'name' => 'Autora']);
        $shared = MailingList::query()->create(['user_id' => $author->id, 'name' => 'Wspólna', 'is_shared' => true]);
        $campaign = $this->campaign($author, [
            'subject' => 'Temat', 'intro' => 'Wstęp', 'layout' => 'grid2', 'status' => Campaign::STATUS_SENT, 'valid_until' => '2026-09-01',
            'sent_at' => now(), 'totals' => ['recipients' => 5, 'sent' => 5, 'failed' => 0, 'skipped' => 0],
            'audience' => ['list_ids' => [$authorList->id, $shared->id], 'xl' => ['mode' => 'group', 'months' => 12, 'only_mine' => true]],
        ]);
        $item = $this->addItem($campaign, 1, [
            'promo_price_net' => 10, 'note' => 'N', 'snap_name' => 'Stara', 'snap_stock' => 5, 'stock_after_7d' => 2,
            'description' => 'Opis', 'link_url' => 'https://sklep.pl/p/1', 'link_label' => 'Kup', 'link_color' => '#1f5fa8',
        ]);
        $this->recipient($campaign, 'a@x.pl', CampaignRecipient::STATUS_SENT);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/campaigns/{$campaign->id}/duplicate")->assertNotFound();

        Sanctum::actingAs($admin);
        $res = $this->postJson("/api/campaigns/{$campaign->id}/duplicate")->assertCreated()
            ->assertJsonPath('author.id', $admin->id)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('subject', 'Temat')
            ->assertJsonPath('layout', 'grid2')
            ->assertJsonPath('valid_until', null)
            ->assertJsonPath('totals', null)
            ->assertJsonPath('sent_at', null)
            // lista prywatna autora niewidoczna dla nowego autora — zostaje tylko wspólna
            ->assertJsonPath('audience', ['list_ids' => [$shared->id], 'list_exclusions' => [], 'xl' => ['mode' => 'group', 'months' => 12, 'only_mine' => true, 'customer_ids' => null]]);
        $copy = Campaign::query()->findOrFail($res->json('id'));
        $this->assertSame($campaign->id, $copy->duplicated_from_id);
        $this->assertNotSame($campaign->code, $copy->code);
        $this->assertSame(0, $copy->recipients()->count());
        $copied = $copy->items()->firstOrFail();
        $this->assertSame([$item->erp_item_id, '10.00', 'N', null, null, null], [$copied->erp_item_id, $copied->promo_price_net, $copied->note, $copied->snap_name, $copied->snap_stock, $copied->stock_after_7d]);
        // opis i drugi przycisk to treść pozycji — kopia je zachowuje
        $this->assertSame(['Opis', 'https://sklep.pl/p/1', 'Kup', '#1f5fa8'], [$copied->description, $copied->link_url, $copied->link_label, $copied->link_color]);
    }

    public function test_send_only_author_and_cancel(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create();
        $campaign = $this->campaign($author);

        Sanctum::actingAs($admin);
        $this->postJson("/api/campaigns/{$campaign->id}/send")->assertForbidden();
        $this->postJson("/api/campaigns/{$campaign->id}/cancel")->assertUnprocessable();
        $this->assertSame([], self::$calls);

        Sanctum::actingAs($author);
        $this->postJson("/api/campaigns/{$campaign->id}/send")->assertOk()->assertJsonPath('status', 'sending')->assertJsonPath('can_edit', false);
        $this->assertSame([['start', [$campaign->id, $author->id]]], self::$calls);
        $this->postJson("/api/campaigns/{$campaign->id}/send")->assertUnprocessable();
        $this->assertCount(1, self::$calls);

        // anulować może też administrator
        Sanctum::actingAs($admin);
        $this->postJson("/api/campaigns/{$campaign->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(['cancel', [$campaign->id]], self::$calls[1]);
    }

    public function test_preview_and_test_mail(): void
    {
        $author = User::factory()->withRole('handlowiec')->create(['email' => 'konto@supon.pl']);
        $campaign = $this->campaign($author, ['subject' => 'Rękawice', 'preheader' => 'Zajawka']);

        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}/preview")->assertOk()
            ->assertExactJson(['subject' => 'Temat: Rękawice', 'preheader' => 'Zajawka', 'from' => null, 'html' => '<p>html</p>']);
        $this->assertSame(['render', [$campaign->id, null, $author->id]], self::$calls[0]);

        // bez skrzynki test idzie na e-mail konta
        $this->postJson("/api/campaigns/{$campaign->id}/test")->assertOk();
        $this->assertSame(['sendTest', [$campaign->id, 'konto@supon.pl']], self::$calls[1]);

        $this->mailAccount($author, 'jan@supon.pl');
        $this->getJson("/api/campaigns/{$campaign->id}/preview")->assertOk()->assertJsonPath('from', ['name' => 'Jan Nowak', 'address' => 'jan@supon.pl']);
        $this->postJson("/api/campaigns/{$campaign->id}/test")->assertOk();
        $this->assertSame(['sendTest', [$campaign->id, 'jan@supon.pl']], self::$calls[3]);
        $this->postJson("/api/campaigns/{$campaign->id}/test", ['email' => 'klient@firma.pl'])->assertOk();
        $this->assertSame(['sendTest', [$campaign->id, 'klient@firma.pl']], self::$calls[4]);
        $this->postJson("/api/campaigns/{$campaign->id}/test", ['email' => 'nie-mail'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_warnings_mailbox_public_url_and_orphan_items(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($author);
        $this->addItem($campaign, 1, ['erp_item_id' => null]);

        Sanctum::actingAs($author);
        $this->assertCount(2, $this->getJson("/api/campaigns/{$campaign->id}")->json('warnings'));

        config(['campaigns.public_url' => '']);
        $warnings = $this->getJson("/api/campaigns/{$campaign->id}")->json('warnings');
        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('Moja poczta', $warnings[0]);

        $this->mailAccount($author, 'jan@supon.pl');
        config(['campaigns.public_url' => 'https://przetargi.example.pl']);
        $this->addItem($campaign, 2);
        CampaignItem::query()->where('campaign_id', $campaign->id)->whereNull('erp_item_id')->delete();
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->assertJsonPath('warnings', []);
    }

    public function test_index_rows_counts_value_and_result(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $sent = $this->campaign($user, ['status' => Campaign::STATUS_SENT, 'sent_at' => now()->subDays(40)]);
        $this->addItem($sent, 1, ['snap_stock' => 100, 'stock_after_7d' => 80, 'stock_after_30d' => 40], stock: 40, value: 400);
        $this->addItem($sent, 2, ['snap_stock' => 100, 'stock_after_7d' => 90, 'stock_after_30d' => 60], stock: 60, value: 600);
        $this->recipient($sent, 'a@x.pl', CampaignRecipient::STATUS_SENT);
        $this->recipient($sent, 'b@x.pl', CampaignRecipient::STATUS_SENT);
        $this->recipient($sent, 'c@x.pl', CampaignRecipient::STATUS_FAILED);
        $week = $this->campaign($user, ['status' => Campaign::STATUS_SENT, 'sent_at' => now()->subDays(10)]);
        $this->addItem($week, 1, ['snap_stock' => 50, 'stock_after_7d' => 45]);
        $draft = $this->campaign($user);
        $this->addItem($draft, 1, ['erp_item_id' => null]);

        Sanctum::actingAs($user);
        $rows = collect($this->getJson('/api/campaigns')->assertOk()->assertJsonPath('meta.total', 3)->json('data'))->keyBy('id');

        $row = $rows[$sent->id];
        $this->assertSame([2, 3, 2, 1], [$row['items_count'], $row['recipients_total'], $row['sent'], $row['failed']]);
        $this->assertEquals(1000, $row['stock_value']);
        $this->assertEquals(['stock_at_send' => 200, 'stock_after_7d' => 170, 'stock_after_30d' => 100, 'drop_percent' => 50], $row['result']);
        // bez stanu po 30 dniach spadek z 7 dni
        $this->assertEquals(['stock_at_send' => 50, 'stock_after_7d' => 45, 'stock_after_30d' => null, 'drop_percent' => 10], $rows[$week->id]['result']);
        $this->assertNull($rows[$draft->id]['result']);
        $this->assertNull($rows[$draft->id]['stock_value']);
        $this->assertSame($user->name, $row['author']['name']);

        $this->assertSame([$draft->id], array_column($this->getJson('/api/campaigns?status=draft')->json('data'), 'id'));
    }

    public function test_result_weights_items_by_value_and_does_not_add_different_units(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $sent = ['status' => Campaign::STATUS_SENT, 'sent_at' => now()->subDays(40)];

        // 100 szt → 50 (koszt 10 zł) i 10 par → 9 (koszt 100 zł): ta sama wartość zapasu, spadek 50% i 10% → 30%
        // (suma sztuk z parami dałaby 46,4%)
        $mixed = $this->campaign($user, $sent);
        $this->addItem($mixed, 1, ['snap_stock' => 100, 'snap_unit' => 'szt', 'stock_after_30d' => 50], stock: 50, value: 500);
        $this->addItem($mixed, 2, ['snap_stock' => 10, 'snap_unit' => 'para', 'stock_after_30d' => 9], stock: 9, value: 900);
        // karta bez towaru XL nie ma stanu — pomijana; pozycja bez stanu przy wysyłce nie ma procentu
        $this->addItem($mixed, 3, ['erp_item_id' => null, 'snap_stock' => 5, 'snap_unit' => 'kpl', 'stock_after_30d' => 0]);
        $this->addItem($mixed, 4, ['snap_stock' => 0, 'snap_unit' => 'szt', 'stock_after_30d' => 0], stock: 0, value: 0);

        // brak kosztu jednej pozycji → zwykła średnia procentów: (10% + 100%) / 2
        $noCost = $this->campaign($user, $sent);
        $this->addItem($noCost, 1, ['snap_stock' => 100, 'snap_unit' => 'szt', 'stock_after_30d' => 90], stock: 90, value: 900);
        $this->addItem($noCost, 2, ['snap_stock' => 10, 'snap_unit' => 'szt', 'stock_after_30d' => 0], stock: 0);

        // stan po 30 dniach znany tylko dla części pozycji — spadek z nich, suma „po 30 dniach” pusta
        $partial = $this->campaign($user, $sent);
        $this->addItem($partial, 1, ['snap_stock' => 100, 'stock_after_7d' => 80, 'stock_after_30d' => 60], stock: 60, value: 600);
        $this->addItem($partial, 2, ['snap_stock' => 100, 'stock_after_7d' => 50], stock: 50, value: 500);

        // wzrost stanu (dostawa) to 0%, nie ujemny spadek
        $growth = $this->campaign($user, $sent);
        $this->addItem($growth, 1, ['snap_stock' => 10, 'snap_unit' => 'szt', 'stock_after_7d' => 30], stock: 30, value: 300);

        Sanctum::actingAs($user);
        $rows = collect($this->getJson('/api/campaigns')->assertOk()->json('data'))->keyBy('id');

        $this->assertEquals(['stock_at_send' => null, 'stock_after_7d' => null, 'stock_after_30d' => null, 'drop_percent' => 30], $rows[$mixed->id]['result']);
        $this->assertEquals(['stock_at_send' => 110, 'stock_after_7d' => null, 'stock_after_30d' => 90, 'drop_percent' => 55], $rows[$noCost->id]['result']);
        $this->assertEquals(['stock_at_send' => 200, 'stock_after_7d' => 130, 'stock_after_30d' => null, 'drop_percent' => 40], $rows[$partial->id]['result']);
        $this->assertEquals(['stock_at_send' => 10, 'stock_after_7d' => 30, 'stock_after_30d' => null, 'drop_percent' => 0], $rows[$growth->id]['result']);
    }

    public function test_deleting_sent_campaign_needs_permission_and_keeps_unsubscribes(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $sent = $this->campaign($user, ['status' => Campaign::STATUS_SENT, 'sending_started_at' => now(), 'sent_at' => now()]);
        $this->addItem($sent, 1);
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $sent->id, 'email' => 'klient@alfa.pl', 'source' => 'list', 'token' => Str::random(40), 'status' => 'sent',
        ]);
        DB::table('campaign_replies')->insert([
            'campaign_id' => $sent->id, 'campaign_recipient_id' => $recipient->id, 'user_id' => $user->id, 'from_email' => 'klient@alfa.pl',
            'subject' => 'Zapytanie', 'matched_by' => 'code', 'message_id' => 'm1', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $suppression = EmailSuppression::query()->create(['email' => 'wypisany@alfa.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE, 'campaign_id' => $sent->id]);
        Sanctum::actingAs($user);

        // bez uprawnienia: nie widać przycisku i serwer odmawia
        $this->getJson("/api/campaigns/{$sent->id}")->assertOk()->assertJsonPath('can_delete', false);
        $this->deleteJson("/api/campaigns/{$sent->id}")->assertForbidden();
        $this->assertNotNull($sent->fresh());

        $user->givePermissionTo('campaigns.delete');
        // zaplanowanej i w wysyłce nie usuwamy nawet z uprawnieniem
        $scheduled = $this->campaign($user, ['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $this->deleteJson("/api/campaigns/{$scheduled->id}")->assertUnprocessable()
            ->assertJsonPath('message', 'Kampania jest zaplanowana — najpierw zdejmij ją z planu.');
        $this->getJson("/api/campaigns/{$scheduled->id}")->assertJsonPath('can_delete', false);
        // cudza wysłana — bez „wszystkie kampanie” nie do znalezienia
        $foreign = $this->campaign(User::factory()->withRole('handlowiec')->create(), ['status' => Campaign::STATUS_SENT, 'sent_at' => now()]);
        $this->deleteJson("/api/campaigns/{$foreign->id}")->assertNotFound();

        $this->getJson("/api/campaigns/{$sent->id}")->assertJsonPath('can_delete', true);
        $this->deleteJson("/api/campaigns/{$sent->id}")->assertOk();
        $this->assertNull($sent->fresh());
        $this->assertSame(0, CampaignRecipient::query()->where('campaign_id', $sent->id)->count());
        $this->assertSame(0, CampaignItem::query()->where('campaign_id', $sent->id)->count());
        $this->assertSame(0, DB::table('campaign_replies')->where('campaign_id', $sent->id)->count());
        // wypis z mailingu zostaje, bez powiązania z kampanią
        $this->assertNull($suppression->fresh()->campaign_id);

        $cancelled = $this->campaign($user, ['status' => Campaign::STATUS_CANCELLED, 'sending_started_at' => now()]);
        $this->deleteJson("/api/campaigns/{$cancelled->id}")->assertOk();
    }

    public function test_changes_are_refused_when_sending_started_meanwhile(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user);
        $item = $this->addItem($campaign, 1);
        $id = $campaign->id;
        Sanctum::actingAs($user);

        $requests = [
            'update' => fn () => $this->patchJson("/api/campaigns/{$id}", ['name' => 'Nowa nazwa']),
            'updateItem' => fn () => $this->patchJson("/api/campaigns/{$id}/items/{$item->id}", ['note' => 'zmiana']),
            'removeItem' => fn () => $this->deleteJson("/api/campaigns/{$id}/items/{$item->id}"),
            'destroy' => fn () => $this->deleteJson("/api/campaigns/{$id}"),
        ];
        foreach ($requests as $action => $send) {
            $armed = true;
            // start() drugiego żądania wchodzi między sprawdzenie statusu a zapis (tuż przed transakcją zmiany)
            Event::listen(TransactionBeginning::class, static function () use (&$armed, $id): void {
                if ($armed) {
                    $armed = false;
                    DB::table('campaigns')->where('id', $id)->update(['status' => Campaign::STATUS_SENDING]);
                }
            });

            $send()->assertStatus(422)->assertJsonPath('message', 'Kampania została już wysłana — zduplikuj ją, żeby zmienić');
            $this->assertFalse($armed, $action.': zmiana musi iść w transakcji');
            DB::table('campaigns')->where('id', $id)->update(['status' => Campaign::STATUS_DRAFT]);
        }

        $this->assertSame('Kampania', $campaign->fresh()->name);
        $this->assertNull($item->fresh()->note);
    }

    public function test_user_with_campaigns_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $author = User::factory()->withRole('handlowiec')->create();
        $this->campaign($author);

        $this->deleteJson("/api/admin/users/{$author->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Użytkownik ma kampanie — nie można go usunąć.');
        $this->assertNotNull($author->fresh());

        $other = User::factory()->withRole('handlowiec')->create();
        $this->deleteJson("/api/admin/users/{$other->id}")->assertOk();
        $this->assertNull($other->fresh());
    }

    public function test_recipients_list_with_status_filter(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user, ['status' => Campaign::STATUS_SENDING]);
        $this->recipient($campaign, 'a@x.pl', CampaignRecipient::STATUS_SENT);
        $this->recipient($campaign, 'b@x.pl', CampaignRecipient::STATUS_PENDING);

        Sanctum::actingAs($user);
        $this->getJson("/api/campaigns/{$campaign->id}/recipients")->assertOk()->assertJsonPath('meta.total', 2);
        $res = $this->getJson("/api/campaigns/{$campaign->id}/recipients?status=sent")->assertOk();
        $this->assertSame(['a@x.pl'], array_column($res->json('data'), 'email'));
        $this->assertSame(['id', 'email', 'name', 'source', 'status', 'error', 'sent_at', 'unsubscribed_at', 'first_clicked_at', 'clicks', 'replied_at', 'created_at'], array_keys($res->json('data.0')));
        $this->getJson("/api/campaigns/{$campaign->id}/recipients?status=zly")->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/campaigns/{$campaign->id}/recipients")->assertNotFound();
    }

    public function test_destroy_draft(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user);
        $this->addItem($campaign, 1);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/campaigns/{$campaign->id}")->assertOk();
        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(0, CampaignItem::query()->count());
    }

    public function test_new_campaign_has_standard_blocks_and_old_one_legacy_blocks(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/campaigns', [])->assertCreated()
            ->assertJsonPath('blocks', CampaignBlocks::standard())
            ->assertJsonPath('brand_color', null)
            ->assertJsonPath('template_id', null)
            ->assertJsonPath('template_name', null);
        $keys = array_keys($res->json());
        foreach (['heading', 'intro', 'layout', 'template_id', 'template_name', 'blocks', 'brand_color'] as $key) {
            $this->assertContains($key, $keys);
        }

        // kampania sprzed szablonów: bloki z heading, intro i layout (nic nie zapisane)
        $old = $this->campaign($user, ['heading' => 'Końcówki', 'intro' => 'Wstęp', 'layout' => 'list']);
        $this->getJson("/api/campaigns/{$old->id}")->assertOk()->assertJsonPath('blocks', [
            ['type' => 'header', 'logo' => null],
            ['type' => 'heading', 'text' => 'Końcówki'],
            ['type' => 'text', 'text' => 'Wstęp'],
            ['type' => 'products', 'layout' => 'list'],
            ['type' => 'footer', 'text' => ''],
        ]);
        $this->assertNull($old->fresh()->blocks);
        // stary front bez bloków zmienia pola jak dotąd
        $this->patchJson("/api/campaigns/{$old->id}", ['heading' => 'Nowy'])->assertOk()->assertJsonPath('blocks.1.text', 'Nowy');
    }

    public function test_patch_blocks_lenient_color_and_ignores_old_fields(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user, ['heading' => 'Stary', 'layout' => 'grid3']);
        $blocks = [
            ['type' => 'heading', 'text' => 'Nowości'],
            ['type' => 'products', 'layout' => 'grid2'],
            // adres w trakcie pisania (autozapis) — sprawdza go dopiero wysyłka
            ['type' => 'button', 'label' => 'Katalog', 'url' => 'htt'],
        ];

        Sanctum::actingAs($user);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['blocks' => [['type' => 'text', 'text' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['blocks' => [['type' => 'products'], ['type' => 'image', 'url' => 'https://x.pl/'.str_repeat('a', 500)]]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.1.url');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['brand_color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('brand_color');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['blocks' => null])->assertUnprocessable()->assertJsonValidationErrors('blocks');

        $this->patchJson("/api/campaigns/{$campaign->id}", ['blocks' => $blocks, 'brand_color' => '#5b3fa0', 'heading' => 'Ignorowany', 'layout' => 'list', 'subject' => 'Temat'])
            ->assertOk()
            ->assertJsonPath('blocks', $blocks)
            ->assertJsonPath('brand_color', '#5b3fa0')
            ->assertJsonPath('subject', 'Temat')
            ->assertJsonPath('heading', 'Stary')
            ->assertJsonPath('layout', 'grid3');
        $this->patchJson("/api/campaigns/{$campaign->id}", ['brand_color' => null])->assertOk()->assertJsonPath('brand_color', null)->assertJsonPath('blocks', $blocks);
    }

    public function test_apply_template_own_shared_foreign_and_standard(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $own = CampaignTemplate::query()->create(['user_id' => $user->id, 'name' => 'Mój', 'brand_color' => '#c25e00', 'blocks' => [['type' => 'products', 'layout' => 'list'], ['type' => 'footer', 'text' => 'Moja stopka']]]);
        $shared = CampaignTemplate::query()->create(['user_id' => $other->id, 'name' => 'Wspólny', 'is_shared' => true, 'blocks' => [['type' => 'heading', 'text' => 'Wspólny'], ['type' => 'products', 'layout' => 'grid2']]]);
        $foreign = CampaignTemplate::query()->create(['user_id' => $other->id, 'name' => 'Cudzy', 'blocks' => CampaignBlocks::standard()]);
        $campaign = $this->campaign($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/campaigns/{$campaign->id}/template", [])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => $foreign->id])->assertNotFound();
        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => 99999])->assertNotFound();
        $this->assertNull($campaign->fresh()->blocks);

        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => $own->id])->assertOk()
            ->assertJsonPath('template_id', $own->id)
            ->assertJsonPath('template_name', 'Mój')
            ->assertJsonPath('brand_color', '#c25e00')
            ->assertJsonPath('blocks', $own->blocks);
        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => $shared->id])->assertOk()
            ->assertJsonPath('template_name', 'Wspólny')
            ->assertJsonPath('brand_color', null)
            ->assertJsonPath('blocks', $shared->blocks);
        // zmiana szablonu nie zmienia kampanii — ma kopię
        $shared->update(['blocks' => CampaignBlocks::standard()]);
        $this->assertSame([['type' => 'heading', 'text' => 'Wspólny'], ['type' => 'products', 'layout' => 'grid2']], $campaign->fresh()->blocks);

        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => null])->assertOk()
            ->assertJsonPath('template_id', null)
            ->assertJsonPath('brand_color', null)
            ->assertJsonPath('blocks', CampaignBlocks::standard());

        // cudza kampania — 404, wysłana — 422 jak każda zmiana
        Sanctum::actingAs($other);
        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => null])->assertNotFound();
        Sanctum::actingAs($user);
        $campaign->update(['status' => Campaign::STATUS_SENT]);
        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => $own->id])->assertUnprocessable()
            ->assertJsonPath('message', 'Kampania została już wysłana — zduplikuj ją, żeby zmienić');
        $this->assertSame(CampaignBlocks::standard(), $campaign->fresh()->blocks);
    }

    public function test_duplicate_copies_blocks_color_and_template(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $template = CampaignTemplate::query()->create(['user_id' => $user->id, 'name' => 'Mój', 'blocks' => CampaignBlocks::standard()]);
        $blocks = [['type' => 'products', 'layout' => 'grid2'], ['type' => 'footer', 'text' => 'Stopka']];
        $campaign = $this->campaign($user, ['blocks' => $blocks, 'brand_color' => '#2f3a40', 'template_id' => $template->id]);
        $old = $this->campaign($user, ['heading' => 'Stary nagłówek', 'layout' => 'list']);

        Sanctum::actingAs($user);
        $this->postJson("/api/campaigns/{$campaign->id}/duplicate")->assertCreated()
            ->assertJsonPath('blocks', $blocks)
            ->assertJsonPath('brand_color', '#2f3a40')
            ->assertJsonPath('template_id', $template->id)
            ->assertJsonPath('template_name', 'Mój');
        // kopia starej kampanii dostaje bloki wyliczone z jej pól
        $id = $this->postJson("/api/campaigns/{$old->id}/duplicate")->assertCreated()->assertJsonPath('blocks.1.text', 'Stary nagłówek')->json('id');
        $this->assertSame('list', Campaign::query()->findOrFail($id)->blocks[3]['layout']);
    }

    public function test_preview_draft_renders_unsaved_blocks_without_saving(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $campaign = $this->campaign($user, ['subject' => 'Rękawice', 'preheader' => 'Zajawka']);
        $blocks = [['type' => 'products', 'layout' => 'list'], ['type' => 'button', 'label' => '', 'url' => '']];

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/campaigns/{$campaign->id}/preview-draft", ['blocks' => $blocks])->assertNotFound();

        Sanctum::actingAs($user);
        $this->postJson("/api/campaigns/{$campaign->id}/preview-draft", ['blocks' => [['type' => 'button', 'label' => 'x', 'url' => 'https://x.pl']]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks');
        $this->postJson("/api/campaigns/{$campaign->id}/preview-draft", ['blocks' => $blocks, 'brand_color' => '#1f5fa8'])->assertOk()
            ->assertExactJson(['subject' => 'Temat: Rękawice', 'preheader' => 'Zajawka', 'from' => null, 'html' => '<p>projekt</p>']);
        $this->assertSame(['renderBlocks', [$campaign->id, $blocks, '#1f5fa8', $user->id]], self::$calls[0]);

        $fresh = $campaign->fresh();
        $this->assertNull($fresh->blocks);
        $this->assertNull($fresh->brand_color);
        $this->assertEquals($campaign->updated_at, $fresh->updated_at);
    }

    /** @param array<string, mixed> $attrs */
    private function campaign(User $user, array $attrs = []): Campaign
    {
        return Campaign::query()->create(['user_id' => $user->id, 'name' => 'Kampania', 'status' => Campaign::STATUS_DRAFT, ...$attrs]);
    }

    /** @param array<string, mixed> $attrs */
    private function addItem(Campaign $campaign, int $position, array $attrs = [], float $stock = 5, ?float $value = null): CampaignItem
    {
        $erpId = array_key_exists('erp_item_id', $attrs) ? $attrs['erp_item_id'] : $this->item('C'.$this->gid, $stock, $value)->id;

        return $campaign->items()->create([...$attrs, 'position' => $position, 'erp_item_id' => $erpId]);
    }

    private function item(string $code, float $stock = 5, ?float $value = null): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => 'Towar '.$code, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'synced_at' => now(),
        ]);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => 'UVEX', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
    }

    private function recipient(Campaign $campaign, string $email, string $status): CampaignRecipient
    {
        return CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'source' => CampaignRecipient::SOURCE_LIST,
            'token' => Str::random(40), 'status' => $status,
        ]);
    }

    private function mailAccount(User $user, string $from): UserMailAccount
    {
        return UserMailAccount::query()->create([
            'user_id' => $user->id, 'from_name' => 'Jan Nowak', 'from_address' => $from, 'host' => 'smtp.supon.pl',
            'port' => 587, 'scheme' => 'smtp', 'username' => $from, 'password' => 'tajne',
        ]);
    }
}
