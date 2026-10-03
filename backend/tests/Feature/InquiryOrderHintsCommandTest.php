<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\InquiryOrderHint;
use App\Models\Product;
use App\Models\User;
use App\Services\ClientInquiryService;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/**
 * inquiries:order-hints — powiązania zapytań z klientami (zawsze) i podpowiedzi „możliwe zamówienie z oferty” z pozycji
 * dokumentów ERP XL (tylko przy włączonym XL). Teraz = sobota 03.10.2026 05:55 w Polsce.
 */
final class InquiryOrderHintsCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    private User $author;

    private int $gid = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 05:55:00', 'Europe/Warsaw'));
        config(['erpxl.enabled' => true, 'bzp.our_company.nip' => '8132283737']);
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
        $this->author = User::factory()->create(['email' => 'handlowiec@supon.pl']);
    }

    public function test_hint_counts_matched_of_offered_items_and_items_without_xl_link(): void
    {
        $client = $this->client(7001);
        [$gloves, $boots, $mask, $apron] = [$this->product('R1'), $this->product('B1'), $this->product('M1'), $this->product('F1')];
        $this->link($gloves, 501, ErpItemLink::STATUS_AUTO);
        $this->link($boots, 502, ErpItemLink::STATUS_CONFIRMED);
        $this->link($mask, 503, ErpItemLink::STATUS_AUTO);
        // fartuch bez powiązania z towarem XL
        $inquiry = $this->repliedInquiry($client, [$gloves, $boots, $mask, $apron], '2026-09-14 10:00');

        $this->xl->documentLineRows = [
            // FS 22.09: rękawice dwa razy (dwie pozycje), buty i obcy towar — 2 z 4 zaoferowanych
            FakeErpXlGateway::documentLine(1842, $this->d('2026-09-22'), 7001, 501, 10, 1200),
            FakeErpXlGateway::documentLine(1842, $this->d('2026-09-22'), 7001, 501, 5, 600),
            FakeErpXlGateway::documentLine(1842, $this->d('2026-09-22'), 7001, 502, 4, 2440),
            FakeErpXlGateway::documentLine(1842, $this->d('2026-09-22'), 7001, 999, 1, 2000),
            // dokument bez towarów oferty — nie jest podpowiedzią
            FakeErpXlGateway::documentLine(1900, $this->d('2026-09-25'), 7001, 999, 1, 50),
        ];

        $this->assertSame(0, Artisan::call('inquiries:order-hints'));

        $hint = InquiryOrderHint::query()->sole();
        $this->assertSame($inquiry->id, $hint->client_inquiry_id);
        $this->assertSame('FS-01H/1842/26/09', $hint->document_number);
        $this->assertSame('2026-09-22', $hint->issued_at->toDateString());
        $this->assertSame(['6240.00', '4240.00'], [(string) $hint->document_net, (string) $hint->matched_net]);
        $this->assertSame([4, 3, 2], [$hint->offered_items, $hint->linked_items, $hint->matched_items]);
        // XL pytany raz, od dnia odpowiedzi, tylko o kontrahenta z zaoferowanymi towarami
        $this->assertSame([['gids' => [7001], 'from' => $this->d('2026-09-14')]], $this->xl->documentLineCalls);
        // podpowiedź to wniosek — wynik zostaje pusty
        $this->assertNull($inquiry->fresh()->outcome);
    }

    public function test_offered_items_are_chosen_products_and_approved_substitutes_not_other_candidates(): void
    {
        $client = $this->client(7008);
        [$chosen, $alternative, $substitute] = [$this->product('W1'), $this->product('W2'), $this->product('Z1')];
        $this->link($alternative, 1101, ErpItemLink::STATUS_AUTO);
        $this->link($substitute, 1102, ErpItemLink::STATUS_AUTO);
        $inquiry = $this->repliedInquiry($client, [$chosen], '2026-09-20 10:00');
        $row = static fn (Product $p, int $score): array => [
            'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'manufacturer' => 'X', 'norms' => null,
            'catalog_price_net' => '10.00', 'currency' => 'PLN', 'catalog_pln' => 10.0, 'offer_pln' => 12.0, 'stock' => 0, 'score' => $score,
        ];
        $analysis = $inquiry->analysis;
        $analysis['matches'][0]['products'] = [$row($chosen, 95), $row($alternative, 90)];
        $analysis['substitutes'] = [$chosen->id => [$row($substitute, 0)]];
        $inquiry->forceFill([
            'analysis' => $analysis,
            'answers' => [...$inquiry->answers, 'substitutes:item_1' => ['option_id' => 'p:'.$substitute->id]],
        ])->save();

        $this->assertSame([$chosen->id, $substitute->id], app(ClientInquiryService::class)->offeredProductIds($inquiry->fresh()));

        $this->xl->documentLineRows = [
            FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7008, 1101, 1, 10), // niewybrana alternatywa
            FakeErpXlGateway::documentLine(2, $this->d('2026-09-22'), 7008, 1102, 1, 20), // zamiennik z listu
        ];
        $this->assertSame(0, Artisan::call('inquiries:order-hints'));
        $hint = InquiryOrderHint::query()->sole();
        $this->assertSame([2, 2, 1, 1], [$hint->document_id, $hint->offered_items, $hint->linked_items, $hint->matched_items]);
    }

    public function test_suggested_and_rejected_links_and_removed_xl_items_are_ignored(): void
    {
        $client = $this->client(7002);
        [$a, $b, $c] = [$this->product('A'), $this->product('B'), $this->product('C')];
        $this->link($a, 601, ErpItemLink::STATUS_SUGGESTED);
        $this->link($b, 602, ErpItemLink::STATUS_REJECTED);
        $this->link($c, 603, ErpItemLink::STATUS_AUTO, removed: true);
        $this->repliedInquiry($client, [$a, $b, $c], '2026-09-20 10:00');
        $this->xl->documentLineRows = [
            FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7002, 601, 1, 10),
            FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7002, 602, 1, 10),
            FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7002, 603, 1, 10),
        ];

        $this->assertSame(0, Artisan::call('inquiries:order-hints'));
        $this->assertSame(0, InquiryOrderHint::query()->count());
        // żaden towar oferty nie ma pewnego powiązania — XL nie jest pytany
        $this->assertSame([], $this->xl->documentLineCalls);
    }

    public function test_window_is_reply_day_to_sixty_days_and_only_recent_replies_without_outcome(): void
    {
        $client = $this->client(7003);
        $gloves = $this->product('R1');
        $this->link($gloves, 701, ErpItemLink::STATUS_AUTO);
        // odpowiedź 31.08 (w ostatnich 60 dniach)
        $recent = $this->repliedInquiry($client, [$gloves], '2026-08-31 16:00');
        // odpowiedź sprzed 60 dni — poza przebiegiem
        $old = $this->repliedInquiry($client, [$gloves], '2026-08-01 10:00');
        // wynik już wpisany — nie liczymy
        $decided = $this->repliedInquiry($client, [$gloves], '2026-09-10 10:00', ['outcome' => 'not_ordered']);
        // bez odpowiedzi — nie liczymy
        $waiting = $this->repliedInquiry($client, [$gloves], null);

        $this->xl->documentLineRows = [
            FakeErpXlGateway::documentLine(10, $this->d('2026-08-30'), 7003, 701, 1, 100), // przed dniem odpowiedzi
            FakeErpXlGateway::documentLine(11, $this->d('2026-08-31'), 7003, 701, 1, 110), // w dniu odpowiedzi
            FakeErpXlGateway::documentLine(12, $this->d('2026-10-03'), 7003, 701, 1, 120), // dziś (+33 dni)
        ];

        $this->assertSame(0, Artisan::call('inquiries:order-hints'));
        $this->assertSame([11, 12], InquiryOrderHint::query()->where('client_inquiry_id', $recent->id)->orderBy('document_id')->pluck('document_id')->all());
        $this->assertSame(0, InquiryOrderHint::query()->whereIn('client_inquiry_id', [$old->id, $decided->id, $waiting->id])->count());
        $this->assertSame([['gids' => [7003], 'from' => $this->d('2026-08-31')]], $this->xl->documentLineCalls);

        // okno to 60 dni od dnia odpowiedzi: dokument z 31.10 (+61) już nie
        $this->travelTo(CarbonImmutable::parse('2026-10-29 05:55:00', 'Europe/Warsaw'));
        $this->xl->documentLineRows[] = FakeErpXlGateway::documentLine(13, $this->d('2026-10-30'), 7003, 701, 1, 130); // +60
        $this->xl->documentLineRows[] = FakeErpXlGateway::documentLine(14, $this->d('2026-10-31'), 7003, 701, 1, 140); // +61
        $this->assertSame(0, Artisan::call('inquiries:order-hints', ['--days' => 90]));
        $this->assertSame([11, 12, 13], InquiryOrderHint::query()->where('client_inquiry_id', $recent->id)->orderBy('document_id')->pluck('document_id')->all());
    }

    public function test_without_certain_xl_client_there_is_no_hint_and_old_hints_go_away(): void
    {
        $gloves = $this->product('R1');
        $this->link($gloves, 801, ErpItemLink::STATUS_AUTO);
        // klient ręczny (bez xl_gid) i zapytanie bez klienta
        $manual = Client::query()->create(['name' => 'Klient spoza XL']);
        $notXl = $this->repliedInquiry($manual, [$gloves], '2026-09-20 10:00');
        $noClient = $this->repliedInquiry(null, [$gloves], '2026-09-20 10:00');
        // stara podpowiedź z czasu, gdy zapytanie było powiązane
        $this->hint($noClient, 55);
        $this->xl->documentLineRows = [FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7004, 801, 1, 10)];

        $this->assertSame(0, Artisan::call('inquiries:order-hints'));
        $this->assertSame(0, InquiryOrderHint::query()->count());
        $this->assertSame([], $this->xl->documentLineCalls);
        $this->assertStringContainsString('bez pewnego klienta z ERP XL: 2', Artisan::output());
        $this->assertNull($notXl->fresh()->outcome);
    }

    public function test_cancelled_document_disappears_but_inquiry_with_outcome_keeps_its_hints(): void
    {
        $client = $this->client(7005);
        $gloves = $this->product('R1');
        $this->link($gloves, 901, ErpItemLink::STATUS_AUTO);
        $inquiry = $this->repliedInquiry($client, [$gloves], '2026-09-20 10:00');
        $this->xl->documentLineRows = [
            FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7005, 901, 1, 10),
            FakeErpXlGateway::documentLine(2, $this->d('2026-09-22'), 7005, 901, 1, 20),
        ];
        Artisan::call('inquiries:order-hints');
        $this->assertSame(2, InquiryOrderHint::query()->count());

        // dokument 2 anulowany w XL
        $this->xl->documentLineRows = [FakeErpXlGateway::documentLine(1, $this->d('2026-09-21'), 7005, 901, 1, 10)];
        Artisan::call('inquiries:order-hints');
        $this->assertSame([1], InquiryOrderHint::query()->pluck('document_id')->all());

        // handlowiec wpisał wynik — zapytanie wypada z przeliczania, podpowiedź zostaje jako ślad
        $inquiry->forceFill(['outcome' => 'ordered'])->save();
        $this->xl->documentLineRows = [];
        Artisan::call('inquiries:order-hints');
        $this->assertSame([1], InquiryOrderHint::query()->pluck('document_id')->all());
        $this->assertSame('ordered', $inquiry->fresh()->outcome);
    }

    public function test_client_linking_runs_without_xl_and_hints_are_skipped(): void
    {
        config(['erpxl.enabled' => false]);
        $acme = Client::query()->create(['name' => 'ACME', 'xl_gid' => 7006, 'emails' => ['zakupy@acme.pl']]);
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $this->author->id, 'source_body' => 'Proszę o ofertę', 'source_channel' => 'web',
            'source_from_email' => 'Zakupy@Acme.pl',
        ]);

        $this->assertSame(0, Artisan::call('inquiries:order-hints'));
        $output = Artisan::output();
        $this->assertStringContainsString('po adresie e-mail: 1', $output);
        $this->assertStringContainsString('podpowiedzi zamówień pomijam', $output);
        $this->assertSame([$acme->id, 'email'], [$inquiry->fresh()->client_id, $inquiry->fresh()->client_link_source]);
        $this->assertSame([], $this->xl->documentLineCalls);
    }

    public function test_xl_failure_ends_with_error_code(): void
    {
        $client = $this->client(7007);
        $gloves = $this->product('R1');
        $this->link($gloves, 1001, ErpItemLink::STATUS_AUTO);
        $this->repliedInquiry($client, [$gloves], '2026-09-20 10:00');
        $this->mock(ErpXlGateway::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('customerDocumentLines')->andThrow(new RuntimeException('serwer XL nie odpowiada'));
        });

        $this->assertSame(1, Artisan::call('inquiries:order-hints'));
        $this->assertStringContainsString('Odczyt dokumentów z ERP XL przerwany: serwer XL nie odpowiada', Artisan::output());
        $this->assertSame(0, InquiryOrderHint::query()->count());
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }

    private function client(int $xlGid): Client
    {
        return Client::query()->create(['name' => 'Klient '.$xlGid, 'xl_gid' => $xlGid]);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create(['sku' => $sku.'-'.$this->gid++, 'name' => 'Wyrób '.$sku, 'manufacturer' => 'X', 'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0]);
    }

    private function link(Product $product, int $xlGid, string $status, bool $removed = false): void
    {
        $item = ErpItem::query()->create([
            'xl_gid' => $xlGid, 'code' => 'T'.$xlGid, 'name' => 'Towar '.$xlGid, 'unit' => 'szt', 'archived' => false,
            'removed_at' => $removed ? '2026-09-30 02:00:00' : null,
        ]);
        ErpItemLink::query()->create(['erp_item_id' => $item->id, 'product_id' => $product->id, 'status' => $status, 'method' => 'manual']);
    }

    /**
     * Zapytanie z wyborem wyrobu przy każdej pozycji (jak po „wybierz”) i odpowiedzią w podanej chwili (czas polski).
     *
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $attrs
     */
    private function repliedInquiry(?Client $client, array $products, ?string $repliedAt, array $attrs = []): ClientInquiry
    {
        $items = [];
        $matches = [];
        $answers = ['price' => ['option_id' => 'none']];
        foreach ($products as $i => $product) {
            $id = 'item_'.($i + 1);
            $items[] = ['id' => $id, 'quote' => $product->name.' - 1 szt', 'qty' => '1', 'unit' => 'szt', 'size' => null, 'query' => $product->name];
            $matches[] = ['query' => $product->name, 'products' => [[
                'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'manufacturer' => 'X', 'norms' => null,
                'catalog_price_net' => '10.00', 'currency' => 'PLN', 'catalog_pln' => 10.0, 'offer_pln' => 12.0, 'stock' => 0, 'score' => 95,
            ]]];
            $answers['product:'.$id] = ['option_id' => 'p:'.$product->id];
        }

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $this->author->id,
            'source_body' => 'Proszę o ofertę',
            'source_channel' => 'web',
            'analysis' => ['line_items' => $items, 'matches' => $matches, 'cards' => []],
            'answers' => $answers,
        ]);
        $inquiry->forceFill([
            'client_id' => $client?->id,
            'client_link_source' => $client !== null ? 'manual' : null,
            'replied_at' => $repliedAt !== null ? CarbonImmutable::parse($repliedAt, 'Europe/Warsaw')->utc() : null,
            ...$attrs,
        ])->save();

        return $inquiry;
    }

    private function hint(ClientInquiry $inquiry, int $documentId): InquiryOrderHint
    {
        return InquiryOrderHint::query()->create([
            'client_inquiry_id' => $inquiry->id, 'document_type' => 2033, 'document_id' => $documentId,
            'document_number' => 'FS-'.$documentId, 'issued_at' => '2026-09-21', 'document_net' => 10, 'matched_net' => 10,
            'offered_items' => 1, 'linked_items' => 1, 'matched_items' => 1, 'computed_at' => now(),
        ]);
    }
}
