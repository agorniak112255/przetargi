<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpCustomer;
use App\Models\ErpCustomerItem;
use App\Models\ErpItem;
use App\Services\Erp\ErpCustomerSync;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** Klienci ERP XL do kampanii: e-maile z karty i adresów, główny operator, zakupy z 24 mies., znikanie z XL. */
final class ErpCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_saves_customers_with_normalized_emails_main_operator_and_items(): void
    {
        $shoes = $this->item(7, 'BSNSL46');
        $this->xl->customers = [
            // kilka adresów w jednym polu, zły format, powtórzenie adresu z karty w adresach
            FakeErpXlGateway::customer(10, 'ACME', " Biuro@Acme.pl; handel@acme.pl , zly-adres  <Jan@Acme.PL>\u{00A0}HANDEL@acme.pl"),
            FakeErpXlGateway::customer(20, 'STARY', 'stary@firma.pl', archived: true),
            FakeErpXlGateway::customer(30, 'BEZMAILA'),
        ];
        $this->xl->addressEmails = [
            ['gid' => 10, 'email' => 'biuro@acme.pl'],
            ['gid' => 10, 'email' => 'Magazyn@Acme.pl;faktury@acme.pl'],
            ['gid' => 30, 'email' => 'nie-mail'],
            // adres kontrahenta, którego XL nie zwrócił w kartach — pomijany
            ['gid' => 99, 'email' => 'obcy@firma.pl'],
        ];
        $this->xl->customerOperatorRows = [
            FakeErpXlGateway::customerOperator(10, 'NOMA', 5),
            FakeErpXlGateway::customerOperator(10, 'TAIZ', 7, 'Izabela Tarsała'),
            // dokumenty bez znanego operatora liczą się do sumy, ale nie do wyboru operatora
            FakeErpXlGateway::customerOperator(10, '', 9, null),
            // remis — alfabetycznie pierwszy
            FakeErpXlGateway::customerOperator(20, 'BBB', 3),
            FakeErpXlGateway::customerOperator(20, 'AAA', 3),
            // ident z XL po trim i wielkich literach
            FakeErpXlGateway::customerOperator(30, ' taiz ', 1),
        ];
        $this->xl->customerSaleRows = [
            FakeErpXlGateway::customerSale(10, 7, $this->d('2026-09-01'), 3, 12.5),
            // towar spoza erp_items — bez pozycji, ale data ostatniej sprzedaży klienta się liczy
            FakeErpXlGateway::customerSale(10, 999, $this->d('2026-09-20'), 1, 2),
            FakeErpXlGateway::customerSale(20, 7, $this->d('2025-01-10'), 1, 1),
            // sprzed 24 mies.
            FakeErpXlGateway::customerSale(30, 7, $this->d('2024-01-01'), 1, 1),
        ];

        $this->assertSame(['customers' => 3, 'with_email' => 2, 'items' => 2], app(ErpCustomerSync::class)->run());
        $this->assertSame($this->d('2024-09-30'), $this->xl->customerSalesFrom);

        $acme = ErpCustomer::query()->where('xl_gid', 10)->sole();
        $this->assertSame(['biuro@acme.pl', 'handel@acme.pl', 'jan@acme.pl', 'magazyn@acme.pl', 'faktury@acme.pl'], $acme->emails);
        $this->assertSame(['ACME', 'Firma ACME', 'Rzeszów'], [$acme->acronym, $acme->name, $acme->city]);
        $this->assertSame(['TAIZ', 21], [$acme->main_operator, $acme->sale_documents_24m]);
        $this->assertSame('2026-09-20', $acme->last_sale_at->toDateString());
        $this->assertFalse($acme->archived);
        $this->assertNull($acme->removed_at);
        $this->assertNotNull($acme->synced_at);

        $old = ErpCustomer::query()->where('xl_gid', 20)->sole();
        $this->assertTrue($old->archived);
        $this->assertSame(['stary@firma.pl'], $old->emails);
        $this->assertSame(['AAA', 6], [$old->main_operator, $old->sale_documents_24m]);
        $this->assertSame('2025-01-10', $old->last_sale_at->toDateString());

        $none = ErpCustomer::query()->where('xl_gid', 30)->sole();
        $this->assertNull($none->emails);
        $this->assertSame(['TAIZ', 1], [$none->main_operator, $none->sale_documents_24m]);
        $this->assertNull($none->last_sale_at);
        $this->assertSame(3, ErpCustomer::query()->count());

        $row = ErpCustomerItem::query()->where('erp_customer_id', $acme->id)->sole();
        $this->assertSame($shoes->id, $row->erp_item_id);
        $this->assertSame(['2026-09-01', 3, '12.500'], [$row->last_sale_at->toDateString(), $row->documents, $row->quantity]);
        $this->assertSame(1, ErpCustomerItem::query()->where('erp_customer_id', $old->id)->count());

        $this->assertSame(
            ['AAA' => 'Osoba AAA', 'BBB' => 'Osoba BBB', 'NOMA' => 'Osoba NOMA', 'TAIZ' => 'Izabela Tarsała'],
            Cache::get(ErpCustomerSync::OPERATORS_CACHE_KEY),
        );
    }

    public function test_second_run_updates_in_place_marks_missing_customer_and_rebuilds_items(): void
    {
        $shoes = $this->item(7, 'BSNSL46');
        $gloves = $this->item(8, 'RRDEX9');
        $this->xl->customers = [
            FakeErpXlGateway::customer(10, 'ACME', 'biuro@acme.pl'),
            FakeErpXlGateway::customer(20, 'BETA', 'beta@beta.pl'),
        ];
        $this->xl->customerOperatorRows = [FakeErpXlGateway::customerOperator(10, 'NOMA', 2)];
        $this->xl->customerSaleRows = [
            FakeErpXlGateway::customerSale(10, 7, $this->d('2026-09-01')),
            FakeErpXlGateway::customerSale(20, 7, $this->d('2026-08-01')),
        ];
        app(ErpCustomerSync::class)->run();
        $acmeId = ErpCustomer::query()->where('xl_gid', 10)->value('id');

        // BETA zniknęła z XL, ACME zmieniła adres, operatora i zakupy
        $this->travelTo(CarbonImmutable::parse('2026-10-01 03:10'));
        $this->xl->customers = [FakeErpXlGateway::customer(10, 'ACME', 'nowy@acme.pl')];
        $this->xl->customerOperatorRows = [FakeErpXlGateway::customerOperator(10, 'TAIZ', 4)];
        $this->xl->customerSaleRows = [FakeErpXlGateway::customerSale(10, 8, $this->d('2026-09-28'), 2, 5)];

        $this->assertSame(['customers' => 1, 'with_email' => 1, 'items' => 1], app(ErpCustomerSync::class)->run());

        $this->assertSame(2, ErpCustomer::query()->count());
        $acme = ErpCustomer::query()->where('xl_gid', 10)->sole();
        $this->assertSame($acmeId, $acme->id);
        $this->assertSame(['nowy@acme.pl'], $acme->emails);
        $this->assertSame(['TAIZ', 4], [$acme->main_operator, $acme->sale_documents_24m]);
        $this->assertSame('2026-09-28', $acme->last_sale_at->toDateString());
        $this->assertNull($acme->removed_at);

        $beta = ErpCustomer::query()->where('xl_gid', 20)->sole();
        $this->assertSame('2026-10-01 03:10:00', $beta->removed_at->toDateTimeString());
        // dane znikniętego klienta zostają dosłownie, zakupy znikają z przebudowanej tabeli
        $this->assertSame(['beta@beta.pl'], $beta->emails);
        $this->assertSame('2026-08-01', $beta->last_sale_at->toDateString());

        $items = ErpCustomerItem::query()->get();
        $this->assertCount(1, $items);
        $this->assertSame([$acme->id, $gloves->id], [$items[0]->erp_customer_id, $items[0]->erp_item_id]);
        $this->assertNotSame($shoes->id, $items[0]->erp_item_id);

        // klient wrócił do XL
        $this->xl->customers[] = FakeErpXlGateway::customer(20, 'BETA', 'beta@beta.pl');
        app(ErpCustomerSync::class)->run();
        $this->assertNull(ErpCustomer::query()->where('xl_gid', 20)->value('removed_at'));
        $this->assertSame(2, ErpCustomer::query()->count());
    }

    public function test_pages_customers_by_number(): void
    {
        config(['erpxl.batch' => 2]);
        foreach ([5, 1, 4, 2, 3] as $gid) {
            $this->xl->customers[] = FakeErpXlGateway::customer($gid, 'K'.$gid, 'k'.$gid.'@firma.pl');
        }

        $this->assertSame(['customers' => 5, 'with_email' => 5, 'items' => 0], app(ErpCustomerSync::class)->run());
        $this->assertSame([1, 2, 3, 4, 5], ErpCustomer::query()->orderBy('xl_gid')->pluck('xl_gid')->all());
    }

    public function test_empty_xl_answer_stops_without_marking_customers_removed(): void
    {
        $this->item(7, 'BSNSL46');
        $this->xl->customers = [FakeErpXlGateway::customer(10, 'ACME', 'biuro@acme.pl')];
        $this->xl->customerSaleRows = [FakeErpXlGateway::customerSale(10, 7, $this->d('2026-09-01'))];
        app(ErpCustomerSync::class)->run();

        $this->xl->customers = [];
        try {
            app(ErpCustomerSync::class)->run();
            $this->fail('Pusta odpowiedź XL powinna przerwać odczyt.');
        } catch (RuntimeException) {
        }

        $this->assertNull(ErpCustomer::query()->where('xl_gid', 10)->value('removed_at'));
        $this->assertSame(1, ErpCustomerItem::query()->count());
    }

    public function test_command_skips_without_connection_and_reports_counts(): void
    {
        $this->xl->isConfigured = false;
        $this->assertSame(0, Artisan::call('erp:customers'));
        $this->assertStringContainsString('pomijam', Artisan::output());
        $this->assertSame(0, ErpCustomer::query()->count());

        $this->xl->isConfigured = true;
        $this->xl->customers = [FakeErpXlGateway::customer(10, 'ACME', 'biuro@acme.pl')];
        $this->assertSame(0, Artisan::call('erp:customers'));
        $this->assertStringContainsString('Kontrahentów: 1, z e-mailem: 1', Artisan::output());

        $this->xl->customers = [];
        $this->assertSame(1, Artisan::call('erp:customers'));
    }

    private function item(int $gid, string $code): ErpItem
    {
        return ErpItem::query()->create(['xl_gid' => $gid, 'code' => $code, 'name' => 'Towar '.$code, 'unit' => 'szt', 'archived' => false, 'stock_trade' => 0, 'stock_total' => 0]);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }
}
