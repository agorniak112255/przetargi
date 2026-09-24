<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapisany list przeliczany przy otwarciu zapytania — panel liczy pozycje na bieżąco,
 * a list był kopią z chwili zapisu (#73: panel z wyceną według rozmiarów, list bez niej).
 */
final class ClientInquiryReplyRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_BODY = 'List napisany starszą wersją programu.';

    private const OLD_HTML = '<div>stary układ listu</div>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Zapytanie z listem złożonym obecnym kodem; zwraca je razem z tą świeżą treścią.
     *
     * @return array{0: ClientInquiry, 1: string, 2: string}
     */
    private function inquiryWithLetter(User $author): array
    {
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice nitrylowe'],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock) use ($product): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [[
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'manufacturer' => $product->manufacturer,
                        'norms' => 'EN 374',
                        'catalog_price_net' => '2.40',
                        'currency' => 'PLN',
                        'stock' => 80,
                        'ai_match_percent' => 92,
                    ]]],
                    $queries
                )
            );
        });

        Sanctum::actingAs($author);
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n10 szt. rękawice nitrylowe rozmiar 9",
            'tone' => 'handlowy',
        ])->assertCreated();

        $inquiry = ClientInquiry::query()->findOrFail((int) $res->json('id'));
        $body = (string) $inquiry->reply_body;
        $html = (string) $inquiry->reply_html;
        $this->assertStringContainsString('RNITZ-100', $html);

        // list zapisany przed wdrożeniem zmiany — inny niż ten, który złożyłby dziś kod
        $inquiry->timestamps = false;
        $inquiry->forceFill([
            'reply_body' => self::OLD_BODY,
            'reply_html' => self::OLD_HTML,
            'reply_subject' => 'Temat poprawiony przez handlowca',
            'updated_at' => CarbonImmutable::parse('2026-09-20 10:00:00'),
        ])->save();

        return [$inquiry->fresh() ?? $inquiry, $body, $html];
    }

    public function test_author_opening_the_inquiry_gets_the_letter_rebuilt_like_the_panel(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        [$inquiry, $body, $html] = $this->inquiryWithLetter($author);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('reply_body', $body)
            ->assertJsonPath('reply_html', $html)
            // temat mógł poprawić handlowiec — przeliczenie go nie rusza
            ->assertJsonPath('reply_subject', 'Temat poprawiony przez handlowca');

        $saved = $inquiry->fresh();
        $this->assertSame($body, $saved?->reply_body);
        $this->assertSame($html, $saved?->reply_html);
        // przeliczenie to nie praca handlowca — data zmiany zostaje
        $this->assertSame('2026-09-20 10:00:00', $saved?->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_sent_or_queued_letter_stays_as_approved(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        [$inquiry] = $this->inquiryWithLetter($author);

        $inquiry->forceFill(['send_requested_at' => now()])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()->assertJsonPath('reply_body', self::OLD_BODY);

        $inquiry->forceFill(['send_requested_at' => null, 'replied_at' => now()])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()->assertJsonPath('reply_body', self::OLD_BODY);

        $this->assertSame(self::OLD_HTML, $inquiry->fresh()?->reply_html);
    }

    public function test_hand_edited_letter_is_not_overwritten(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        [$inquiry] = $this->inquiryWithLetter($author);

        $this->patchJson("/api/inquiries/{$inquiry->id}", ['reply_body' => 'Dzień dobry, poprawione ręcznie.'])->assertOk();

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('reply_body', 'Dzień dobry, poprawione ręcznie.');
        $this->assertNull($inquiry->fresh()?->reply_html);
    }

    public function test_someone_else_viewing_the_inquiry_does_not_write_to_it(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        [$inquiry] = $this->inquiryWithLetter($author);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->getJson("/api/inquiries/{$inquiry->id}")->assertOk()->assertJsonPath('reply_body', self::OLD_BODY);

        $this->assertSame(self::OLD_HTML, $inquiry->fresh()?->reply_html);
    }
}
