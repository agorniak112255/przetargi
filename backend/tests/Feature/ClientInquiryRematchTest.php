<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Produkcja 24.09.2026: zapytania przeanalizowane, gdy model nie odpowiadał albo wyszukiwarka
 * miała błąd, zostały z „brak w katalogu”. inquiries:rematch szuka jeszcze raz tymi samymi frazami.
 */
final class ClientInquiryRematchTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'rękawice spawalnicze RS SPLIT KEV';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_apply_finds_product_missed_at_analysis_and_rewrites_letter(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);
        $this->assertSame('check', $inquiry->answers['product:item_1']['option_id']);
        $this->assertStringNotContainsString('RS-SPLIT-KEV', (string) $inquiry->reply_body);

        $this->searchReturns([$this->row($product, 92)]);
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id], '--apply' => true])
            ->expectsOutputToContain('#'.$inquiry->id.' — zapisane')
            ->doesntExpectOutputToContain('Nic nie zapisano')
            ->assertSuccessful();

        $after = $inquiry->fresh();
        $this->assertSame('p:'.$product->id, $after->answers['product:item_1']['option_id']);
        $this->assertStringContainsString('SKU RS-SPLIT-KEV', (string) $after->reply_body);
        $this->assertNotNull($after->analysis['rematched_at'] ?? null);
        $this->assertSame([self::QUERY], $after->analysis['product_queries']);
        $this->assertArrayNotHasKey('model_failed', $after->analysis['matches'][0]);
    }

    public function test_report_shows_before_and_after_for_the_item(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);

        $this->searchReturns([$this->row($product, 92)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry, true);

        $this->assertNull($report['skipped']);
        $this->assertSame('item_1', $report['items'][0]['id']);
        $this->assertNull($report['items'][0]['before']);
        $this->assertSame(['sku' => 'RS-SPLIT-KEV', 'name' => 'Rękawice spawalnicze RS SPLIT KEV', 'score' => 92], $report['items'][0]['after']);
        $this->assertSame('check', $report['items'][0]['answer_before']);
        $this->assertSame('p:'.$product->id, $report['items'][0]['answer_after']);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);
        $snapshot = $this->state($inquiry);

        $this->searchReturns([$this->row($product, 92)]);
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id]])
            ->expectsOutputToContain('#'.$inquiry->id.' — podgląd')
            ->expectsOutputToContain('Nic nie zapisano')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
        $this->searchReturns([$this->row($product, 92)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), false);
        // podgląd pokazuje to, co zrobiłby zapis
        $this->assertSame('p:'.$product->id, $report['items'][0]['answer_after']);
        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_replied_inquiry_is_skipped_and_untouched(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['replied_at' => now()])->save();
        $snapshot = $this->state($inquiry->fresh());

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id], '--apply' => true])
            ->expectsOutputToContain('pominięte — odpowiedź już wysłana')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_inquiry_waiting_to_be_sent_is_skipped(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['send_requested_at' => now()])->save();

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('list czeka na wysłanie', $report['skipped']);
        $this->assertSame([], $report['items']);
    }

    /** Ręczna poprawka treści listu (bez tabeli HTML) — zapis złożyłby list od nowa i poprawki by przepadły. */
    public function test_hand_edited_letter_is_skipped_and_untouched(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['reply_body' => 'List poprawiony ręcznie przez handlowca.', 'reply_html' => null])->save();
        $snapshot = $this->state($inquiry->fresh());

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertStringStartsWith('list poprawiony ręcznie', (string) $report['skipped']);
        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_unknown_id_is_reported(): void
    {
        $this->artisan('inquiries:rematch', ['ids' => [999999]])
            ->expectsOutputToContain('#999999: nie ma takiego zapytania')
            ->assertSuccessful();
    }

    public function test_product_chosen_by_salesperson_is_kept(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $other = $this->product('RS-SPLIT-2', 'Rękawice spawalnicze RS SPLIT 2');
        $inquiry = $this->analyzed([$this->row($best, 90), $this->row($other, 72)]);
        $this->assertSame('p:'.$best->id, $inquiry->answers['product:item_1']['option_id']);
        // handlowiec wybrał drugi wyrób
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'p:'.$other->id];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95), $this->row($other, 75)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('p:'.$other->id, $report['items'][0]['answer_before']);
        $this->assertSame('p:'.$other->id, $report['items'][0]['answer_after']);
        $this->assertSame('p:'.$other->id, $inquiry->fresh()->answers['product:item_1']['option_id']);
        $this->assertStringContainsString('SKU RS-SPLIT-2', (string) $inquiry->fresh()->reply_body);
    }

    public function test_check_on_item_that_had_candidates_is_kept(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([$this->row($best, 90)]);
        // handlowiec miał kandydata i świadomie zostawił pozycję do sprawdzenia
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'check'];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('check', $report['items'][0]['answer_after']);
        $this->assertSame('check', $inquiry->fresh()->answers['product:item_1']['option_id']);
    }

    public function test_warns_when_chosen_product_drops_out_of_candidates(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $other = $this->product('RS-SPLIT-2', 'Rękawice spawalnicze RS SPLIT 2');
        $inquiry = $this->analyzed([$this->row($best, 90), $this->row($other, 72)]);
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'p:'.$other->id];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), false);

        $this->assertSame('p:'.$best->id, $report['items'][0]['answer_after']);
        $this->assertCount(1, $report['warnings']);
        $this->assertStringContainsString('p:'.$other->id, $report['warnings'][0]);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function analyzed(array $products): ClientInquiry
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice spawalnicze',
                'questions' => [],
                'product_queries' => [self::QUERY],
                'cards' => [],
            ]);
        });
        // pusta lista po awarii modelu — tak wyglądały zapytania #64, #65, #67
        $this->searchReturns($products, $products === [] ? 'unavailable' : null);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $id = (int) $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawice spawalnicze RS SPLIT KEV.',
            'tone' => 'handlowy',
        ])->assertCreated()->json('id');

        return ClientInquiry::query()->findOrFail($id);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function searchReturns(array $products, ?string $modelState = null): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock) use ($products, $modelState): void {
            $group = ['query' => self::QUERY, 'products' => $products];
            if ($modelState !== null) {
                $group['model_state'] = $modelState;
            }
            $mock->shouldReceive('findMany')->once()->andReturn([$group]);
        });
    }

    private function product(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Supon',
            'catalog_price_net' => 12.50,
            'purchase_price' => 8.00,
            'stock' => 40,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, int $score): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'catalog_price_net' => '12.50',
            'purchase_price' => '8.00',
            'currency' => 'PLN',
            'stock' => 40,
            'ai_match_percent' => $score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(ClientInquiry $inquiry): array
    {
        return [
            'analysis' => $inquiry->analysis,
            'answers' => $inquiry->answers,
            'reply_subject' => $inquiry->reply_subject,
            'reply_body' => $inquiry->reply_body,
            'reply_html' => $inquiry->reply_html,
            'updated_at' => (string) $inquiry->updated_at,
        ];
    }
}
