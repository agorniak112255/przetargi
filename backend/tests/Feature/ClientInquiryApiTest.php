<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClientInquiryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_handlowiec_analyzes_inquiry_and_strips_purchase_price(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $client = Client::query()->create(['name' => 'Firma Test', 'owner_id' => $user->id]);
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'norms' => 'EN 374',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);
        $other = Product::query()->create([
            'sku' => 'RNITZ-200',
            'name' => 'Rękawice nitrylowe grubsze',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 3.10,
            'purchase_price' => 1.80,
            'stock' => 20,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice nitrylowe',
                'questions' => ['Czy macie rękawice nitrylowe?'],
                'product_queries' => ['rękawice nitrylowe'],
                'cards' => [[
                    'id' => 'sizes',
                    'title' => 'Rozmiar',
                    'prompt' => 'Klient nie podał rozmiarów',
                    'options' => [
                        ['id' => 'ask', 'label' => 'Dopytaj'],
                        ['id' => 'skip', 'label' => 'Nie dopytuj'],
                    ],
                    'allow_custom' => false,
                ]],
            ]);
        });

        $this->mock(ProductInquirySearch::class, function ($mock) use ($product, $other): void {
            $mock->shouldReceive('findMany')->once()->andReturn([[
                'query' => 'rękawice nitrylowe',
                'products' => [
                    [
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'manufacturer' => $product->manufacturer,
                        'norms' => $product->norms,
                        'catalog_price_net' => '2.40',
                        'purchase_price' => '1.10',
                        'currency' => 'PLN',
                        'stock' => 80,
                        'ai_match_percent' => 91,
                    ],
                    [
                        'id' => $other->id,
                        'sku' => $other->sku,
                        'name' => $other->name,
                        'manufacturer' => $other->manufacturer,
                        'catalog_price_net' => '3.10',
                        'purchase_price' => '1.80',
                        'currency' => 'PLN',
                        'stock' => 20,
                        'ai_match_percent' => 70,
                    ],
                ],
            ]]);
        });

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawice nitrylowe do laboratorium.',
            'tone' => 'handlowy',
            'client_id' => $client->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('client.name', 'Firma Test')
            ->assertJsonPath('cards.0.id', 'product')
            // mail opisowy: jedna pseudo-pozycja bez cytatu, list gotowy od razu
            ->assertJsonPath('items.0.id', 'item_1')
            ->assertJsonPath('items.0.quote', null)
            ->assertJsonPath('items.0.answer_key', 'product:item_1')
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.chosen', 'p:'.$product->id)
            ->assertJsonPath('items.0.candidates.0.sku', 'RNITZ-100')
            ->assertJsonPath('items.0.substitute_key', null)
            ->assertJsonPath('global_cards.0.id', 'sizes')
            ->assertJsonPath('answers.product:item_1.option_id', 'p:'.$product->id)
            ->assertJsonPath('price.mode', 'none')
            ->assertJsonPath('attention_count', 0)
            ->assertJsonPath('replied_at', null);

        $json = $res->json();
        $this->assertStringContainsString('SKU RNITZ-100', (string) $json['reply_body']);
        $this->assertStringNotContainsString('purchase_price', json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('1.10', json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('client_inquiries', [
            'user_id' => $user->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_analyze_keeps_product_on_each_line_without_substitute_card(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'G10',
            'name' => 'Rękawice chemoodporne',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 12.00,
            'purchase_price' => 5.00,
            'stock' => 40,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Oferta BHP',
                'questions' => [],
                'product_queries' => ['rękawice chemoodporne'],
                'line_items' => [],
                'cards' => [],
            ]);
        });

        $this->mock(ProductInquirySearch::class, function ($mock) use ($product): void {
            $row = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'manufacturer' => $product->manufacturer,
                'norms' => '',
                'catalog_price_net' => '12.00',
                'currency' => 'PLN',
                'stock' => 40,
                'ai_match_percent' => 86,
            ];
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [$row]],
                    $queries
                )
            );
        });

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n30szt Rękawice chemoodporne rozmiar 10\n\n30szt Rękawice chemoodporne rozmiar 9",
            'tone' => 'handlowy',
        ]);

        // bez zatwierdzonych zamienników nie ma kart zamienników ani substitute_key
        $res->assertCreated()
            ->assertJsonPath('cards.0.id', 'product:item_1')
            ->assertJsonPath('cards.1.id', 'product:item_2')
            ->assertJsonPath('cards.0.quote', '30szt Rękawice chemoodporne rozmiar 10')
            ->assertJsonPath('cards.1.quote', '30szt Rękawice chemoodporne rozmiar 9')
            ->assertJsonPath('items.0.quote', '30szt Rękawice chemoodporne rozmiar 10')
            ->assertJsonPath('items.0.qty', '30')
            ->assertJsonPath('items.0.unit', 'szt')
            ->assertJsonPath('items.0.size', '10')
            ->assertJsonPath('items.0.substitute_key', null)
            ->assertJsonPath('items.1.id', 'item_2')
            ->assertJsonPath('items.1.chosen', 'p:'.$product->id)
            ->assertJsonPath('answers.product:item_2.option_id', 'p:'.$product->id)
            ->assertJsonPath('attention_count', 0);

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString("Poz. 1 — ilość: 30 szt, rozmiar z zapytania: 10\n30szt Rękawice chemoodporne rozmiar 10\nProdukt: Rękawice chemoodporne (SKU G10), Supon", $body);
        $this->assertStringNotContainsString('Cena:', $body);
    }

    public function test_store_uses_price_preferences_from_last_inquiry(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_body' => 'Poprzednie zapytanie o rękawice.',
            'analysis' => [],
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '25']],
            // warunki z poprzedniej oferty mają się podpowiedzieć przy nowej
            'offer_terms' => ['lead_time' => '3 dni robocze', 'payment' => 'przelew 30 dni'],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries/preferences')
            ->assertOk()
            ->assertExactJson(['tone' => 'handlowy', 'price_mode' => 'catalog_margin', 'margin' => 25]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Kalosze',
                'questions' => ['Jaki termin dostawy?'],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [[
                        'id' => 22,
                        'sku' => 'FW94',
                        'name' => 'Kalosze S4',
                        'manufacturer' => 'Portwest',
                        'norms' => 'S4',
                        'catalog_price_net' => '55.46',
                        'purchase_price' => '40.00',
                        'currency' => 'PLN',
                        'stock' => 0,
                        'ai_match_percent' => 46,
                        'ai_match_reason' => 'Ten sam rodzaj w katalogu (nieoceniony)',
                    ]]],
                    $queries
                )
            );
        });

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n4szt Kalosze chemoodporne rozmiar 43",
            'tone' => 'formal',
        ]);

        $res->assertCreated()
            ->assertJsonPath('price.mode', 'catalog_margin')
            ->assertJsonPath('price.margin', 25)
            ->assertJsonPath('answers.price.option_id', 'catalog_margin')
            ->assertJsonPath('answers.price.custom', '25')
            ->assertJsonPath('questions.0', 'Jaki termin dostawy?')
            ->assertJsonPath('terms.lead_time', '3 dni robocze')
            ->assertJsonPath('terms.payment', 'przelew 30 dni')
            ->assertJsonPath('terms.delivery', null)
            ->assertJsonPath('items.0.confidence', 'none')
            ->assertJsonPath('items.0.chosen', 'check')
            ->assertJsonPath('items.0.flags', ['low_score'])
            ->assertJsonPath('items.0.candidates.0.score', 46)
            ->assertJsonPath('items.0.candidates.0.reason', 'Ten sam rodzaj w katalogu (nieoceniony)')
            ->assertJsonPath('attention_count', 1);

        // pozycja „none”: bez SKU i bez ceny; pytania klienta nie wchodzą do listu
        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('Pozycję potwierdzimy po weryfikacji dostępności i wrócimy z propozycją.', $body);
        $this->assertStringNotContainsString('FW94', $body);
        $this->assertStringNotContainsString('Cena:', $body);
        $this->assertStringNotContainsString('termin dostawy', $body);
        $this->assertSame(25.0, (float) $res->json('answers.price.custom'));
    }

    public function test_store_from_thunderbird_saves_source_and_reuses_existing_inquiry(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        // „once” pilnuje, że powtórne wysłanie tego samego maila nie uruchamia drugiej analizy
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturn([]);
        });

        $payload = [
            'body' => "Dzień dobry\n\n10 szt. rękawice nitrylowe rozmiar 9",
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => '<abc-123@poczta.example>',
        ];

        $first = $this->postJson('/api/inquiries', $payload)
            ->assertCreated()
            ->assertJsonPath('source_channel', 'thunderbird')
            // nawiasy „< >” obcinamy, żeby porównanie nie zależało od zapisu
            ->assertJsonPath('source_message_id', 'abc-123@poczta.example');

        $second = $this->postJson('/api/inquiries', $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, ClientInquiry::query()->where('user_id', $user->id)->count());
    }

    public function test_store_keeps_whole_mail_but_analyzes_it_without_footer(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        // model nie zwraca pozycji, więc decyduje parser tekstowy — i to on
        // wcześniej robił pozycję z adresu w stopce
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturn([]);
        });

        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            '10 szt. rękawice nitrylowe rozmiar 9',
            '',
            'Pozdrawiam',
            'Mateusz Baniak',
            'tel. 17 785 22 46,   Al. gen. L. Okulickiego 18,  35-206 Rzeszów',
        ]);

        $res = $this->postJson('/api/inquiries', ['body' => $mail, 'tone' => 'formal'])
            ->assertCreated()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.quote', '10 szt. rękawice nitrylowe rozmiar 9');

        // cały mail zostaje w bazie — obcięta jest tylko wersja robocza dla analizy
        $this->assertSame($mail, $res->json('source_body'));
        $this->assertStringNotContainsString('35-206', json_encode($res->json('items'), JSON_THROW_ON_ERROR));
    }

    public function test_store_saves_sender_and_contact_from_mail_footer(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturn([]);
        });

        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 10 szt. rękawic nitrylowych rozmiar 9.',
            '',
            'Pozdrawiam,',
            'Mateusz Baniak',
            'pomoc@proferis.pl<mailto:pomoc@proferis.pl>',
            '| PROFERIS',
            'tel. 17 785 22 46,   Al. gen. L. Okulickiego 18,  35-206 Rzeszów',
        ]);

        $res = $this->postJson('/api/inquiries', [
            'body' => $mail,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => '<stopka-1@poczta.example>',
            'source_from' => 'Mateusz Baniak <pomoc@proferis.pl>',
            'source_sent_at' => 'Tue, 4 Aug 2026 13:09:32 +0200',
        ]);

        $res->assertCreated()
            ->assertJsonPath('source_from_name', 'Mateusz Baniak')
            ->assertJsonPath('source_from_email', 'pomoc@proferis.pl')
            ->assertJsonPath('contact.person', 'Mateusz Baniak')
            ->assertJsonPath('contact.company', 'PROFERIS')
            ->assertJsonPath('contact.emails.0', 'pomoc@proferis.pl')
            ->assertJsonPath('contact.phones.0', '17 785 22 46')
            ->assertJsonPath('contact.address', 'Al. gen. L. Okulickiego 18, 35-206 Rzeszów')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', $user->name);

        // strefa z maila (+0200) jest przeliczana, a nie gubiona
        $this->assertSame('2026-08-04T11:09:32+00:00', $res->json('source_sent_at'));
        $this->assertTrue(
            CarbonImmutable::parse((string) $res->json('source_sent_at'))
                ->equalTo(CarbonImmutable::parse('Tue, 4 Aug 2026 13:09:32 +0200')),
        );

        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $this->assertSame('Mateusz Baniak', $inquiry->source_from_name);
        $this->assertSame('pomoc@proferis.pl', $inquiry->source_from_email);
        // surowy blok stopki zostaje jako ślad źródła
        $this->assertStringContainsString('| PROFERIS', (string) $inquiry->contact['raw']);
    }

    public function test_reply_has_html_table_matching_text_and_manual_edit_drops_it(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice nitrylowe'],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock) use ($product): void {
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
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

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n10 szt. rękawice nitrylowe rozmiar 9",
            'tone' => 'handlowy',
        ])->assertCreated();

        $html = (string) $res->json('reply_html');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Pozycja z zapytania', $html);
        $this->assertStringContainsString('Nasza propozycja', $html);
        // cytat klienta i nasza odpowiedź stoją w jednym wierszu tabeli
        $this->assertStringContainsString('10 szt. rękawice nitrylowe rozmiar 9', $html);
        $this->assertStringContainsString('RNITZ-100', $html);
        // tabela nie może mówić czegoś innego niż wersja tekstowa
        $this->assertStringContainsString('RNITZ-100', (string) $res->json('reply_body'));

        $id = (int) $res->json('id');
        $this->patchJson("/api/inquiries/{$id}", ['reply_body' => 'Dzień dobry, oferta w załączeniu.'])
            ->assertOk()
            ->assertJsonPath('reply_html', null);
        $this->assertNull(ClientInquiry::query()->find($id)?->reply_html);
    }

    public function test_older_reply_without_stored_html_still_gets_the_table(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice nitrylowe'],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock) use ($product): void {
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
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

        Sanctum::actingAs($user);

        $id = (int) $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n10 szt. rękawice nitrylowe rozmiar 9",
            'tone' => 'handlowy',
        ])->assertCreated()->json('id');

        // list napisany, zanim tabela w ogóle powstawała
        ClientInquiry::query()->where('id', $id)->update(['reply_html' => null]);

        $this->getJson("/api/inquiries/{$id}")
            ->assertOk()
            ->assertJsonPath('id', $id);

        $html = (string) $this->getJson("/api/inquiries/{$id}")->json('reply_html');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('RNITZ-100', $html);

        // ale po ręcznej poprawce treści tabeli już nie odtwarzamy
        $this->patchJson("/api/inquiries/{$id}", ['reply_body' => 'Dzień dobry, oferta w załączeniu.'])->assertOk();
        $this->getJson("/api/inquiries/{$id}")->assertOk()->assertJsonPath('reply_html', null);
    }

    public function test_html_reply_escapes_text_from_the_customer(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Zapytanie',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturn([]);
        });

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n5 szt. <script>alert(1)</script> & rękawice",
            'tone' => 'formal',
        ])->assertCreated();

        $html = (string) $res->json('reply_html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function test_author_deletes_own_inquiry_and_nobody_else_can(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('kierownik')->create();

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $author->id,
            'tone' => 'formal',
            'source_body' => 'Zapytanie do skasowania.',
            'analysis' => [],
            'answers' => [],
        ]);

        // kierownik widzi cudze zapytania na liście, ale kasować ich nie może
        Sanctum::actingAs($other);
        $this->deleteJson("/api/inquiries/{$inquiry->id}")->assertForbidden();
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry->id]);

        Sanctum::actingAs($author);
        $this->deleteJson("/api/inquiries/{$inquiry->id}")->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('client_inquiries', ['id' => $inquiry->id]);

        $this->getJson("/api/inquiries/{$inquiry->id}")->assertNotFound();
    }

    public function test_deleting_an_inquiry_leaves_the_copy_of_the_same_mail_alone(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();
        $piotr = User::factory()->withRole('handlowiec')->create();

        $origin = ClientInquiry::query()->create([
            'user_id' => $anna->id,
            'tone' => 'formal',
            'source_message_id' => 'wspolny@poczta.example',
            'source_body' => 'Ten sam mail.',
            'analysis' => [],
            'answers' => [],
        ]);
        $copy = ClientInquiry::query()->create([
            'user_id' => $piotr->id,
            'duplicate_of_id' => $origin->id,
            'tone' => 'formal',
            'source_message_id' => 'wspolny@poczta.example',
            'source_body' => 'Ten sam mail.',
            'analysis' => [],
            'answers' => [],
        ]);

        Sanctum::actingAs($anna);
        $this->deleteJson("/api/inquiries/{$origin->id}")->assertOk();

        Sanctum::actingAs($piotr);
        $this->getJson("/api/inquiries/{$copy->id}")
            ->assertOk()
            ->assertJsonPath('duplicate_of', null)
            ->assertJsonPath('duplicates', []);
    }

    public function test_reply_can_be_queued_for_thunderbird_and_picked_up(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'abc-123@poczta.example',
            'source_body' => 'Proszę o wycenę rękawic.',
            'analysis' => [],
            'answers' => [],
            'reply_subject' => 'Oferta — rękawice',
            'reply_body' => 'Dzień dobry, w załączeniu oferta.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries/queued')->assertOk()->assertExactJson([]);

        $this->postJson("/api/inquiries/{$inquiry->id}/queue-reply", ['queued' => true])
            ->assertOk()
            ->assertJsonPath('id', $inquiry->id);
        $this->assertNotNull($inquiry->fresh()->send_requested_at);

        $this->getJson('/api/inquiries/queued')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $inquiry->id)
            ->assertJsonPath('0.source_message_id', 'abc-123@poczta.example')
            ->assertJsonPath('0.reply_body', 'Dzień dobry, w załączeniu oferta.');

        // dodatek podjął prośbę i ją kasuje
        $this->postJson("/api/inquiries/{$inquiry->id}/queue-reply", ['queued' => false])->assertOk();
        $this->assertNull($inquiry->fresh()->send_requested_at);
        $this->getJson('/api/inquiries/queued')->assertOk()->assertExactJson([]);
    }

    public function test_reply_without_mail_or_body_cannot_be_queued(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $pasted = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Wklejone w przeglądarce.',
            'analysis' => [],
            'answers' => [],
            'reply_body' => 'Gotowa treść.',
        ]);
        $empty = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'pusty@poczta.example',
            'source_body' => 'Z maila.',
            'analysis' => [],
            'answers' => [],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/inquiries/{$pasted->id}/queue-reply", ['queued' => true])->assertStatus(422);
        $this->postJson("/api/inquiries/{$empty->id}/queue-reply", ['queued' => true])->assertStatus(422);
        $this->assertNull($pasted->fresh()->send_requested_at);
        $this->assertNull($empty->fresh()->send_requested_at);
    }

    public function test_queued_list_does_not_leak_other_users(): void
    {
        $mine = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $theirs = ClientInquiry::query()->create([
            'user_id' => $other->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'cudze@poczta.example',
            'source_body' => 'Cudze zapytanie.',
            'analysis' => [],
            'answers' => [],
            'reply_body' => 'Treść.',
            'send_requested_at' => now(),
        ]);

        Sanctum::actingAs($mine);

        $this->getJson('/api/inquiries/queued')->assertOk()->assertExactJson([]);
        $this->postJson("/api/inquiries/{$theirs->id}/queue-reply", ['queued' => true])->assertStatus(403);
    }

    public function test_store_without_source_stays_web(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Kalosze',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturn([]);
        });

        $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n4 szt. kalosze chemoodporne rozmiar 43",
            'tone' => 'formal',
        ])
            ->assertCreated()
            ->assertJsonPath('source_channel', 'web')
            ->assertJsonPath('source_message_id', null);
    }

    public function test_store_rejects_unknown_source_channel(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n4 szt. kalosze chemoodporne rozmiar 43",
            'tone' => 'formal',
            'source_channel' => 'outlook',
        ])->assertStatus(422);
    }

    public function test_compose_merges_partial_answers_and_marks_missing_price(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_subject' => 'Rękawice i kalosze',
            'source_body' => "30szt Rękawice chemoodporne rozmiar 10\n4 pary Kalosze chemoodporne rozmiar 43",
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => '30szt Rękawice chemoodporne rozmiar 10', 'qty' => '30', 'unit' => 'szt', 'size' => '10', 'query' => 'Rękawice chemoodporne'],
                    ['id' => 'item_2', 'quote' => '4 pary Kalosze chemoodporne rozmiar 43', 'qty' => '4', 'unit' => 'pary', 'size' => '43', 'query' => 'Kalosze chemoodporne'],
                ],
                'matches' => [
                    ['query' => 'Rękawice chemoodporne', 'products' => [
                        ['id' => 11, 'sku' => '37900VP', 'name' => 'AlphaTec 37900VP', 'manufacturer' => 'Ansell', 'norms' => 'EN ISO 374-1', 'catalog_price_net' => '19.85', 'currency' => 'PLN', 'catalog_pln' => 19.85, 'offer_pln' => 22.15, 'stock' => 0, 'score' => 90],
                        ['id' => 12, 'sku' => '37675', 'name' => 'AlphaTec 37675', 'manufacturer' => 'Ansell', 'norms' => 'EN ISO 374-1', 'catalog_price_net' => '15.00', 'currency' => 'PLN', 'catalog_pln' => 15.0, 'offer_pln' => 17.7, 'stock' => 5, 'score' => 70],
                    ]],
                    ['query' => 'Kalosze chemoodporne', 'products' => [
                        // brak ceny zakupu → brak ceny oferty
                        ['id' => 22, 'sku' => 'FW94', 'name' => 'Kalosze S4', 'manufacturer' => 'Portwest', 'norms' => 'S4', 'catalog_price_net' => '55.46', 'currency' => 'PLN', 'catalog_pln' => 55.46, 'offer_pln' => null, 'stock' => 0, 'score' => 88],
                    ]],
                ],
                'substitutes' => [
                    11 => [['id' => 13, 'sku' => 'SUB1', 'name' => 'Zamiennik AlphaTec', 'manufacturer' => 'Ansell', 'norms' => '', 'catalog_price_net' => '18.00', 'currency' => 'PLN', 'catalog_pln' => 18.0, 'offer_pln' => 20.0, 'stock' => 9, 'score' => 0]],
                ],
                'cards' => [],
            ],
            'answers' => [
                'product:item_1' => ['option_id' => 'p:11'],
                'substitutes:item_1' => ['option_id' => 'no'],
                'product:item_2' => ['option_id' => 'p:22'],
                'price' => ['option_id' => 'none', 'custom' => '18'],
            ],
            'extra_note' => 'Dopisek zostaje',
        ]);

        Sanctum::actingAs($user);

        // częściowe answers: tylko tryb ceny — reszta z zapisanych; extra_note nie przysłany → zostaje
        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '18']],
        ]);

        $res->assertOk()
            ->assertJsonPath('answers.product:item_1.option_id', 'p:11')
            ->assertJsonPath('answers.substitutes:item_1.option_id', 'no')
            ->assertJsonPath('answers.price.option_id', 'catalog_margin')
            ->assertJsonPath('extra_note', 'Dopisek zostaje')
            ->assertJsonPath('price.mode', 'catalog_margin')
            ->assertJsonPath('items.0.substitute_key', 'substitutes:item_1')
            ->assertJsonPath('items.0.substitutes.0.sku', 'SUB1')
            ->assertJsonPath('items.0.substitutes.0.score', null)
            ->assertJsonPath('items.0.flags', [])
            ->assertJsonPath('items.1.confidence', 'high')
            ->assertJsonPath('items.1.flags', ['no_price'])
            ->assertJsonPath('attention_count', 1);

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString("Poz. 1 — ilość: 30 szt, rozmiar z zapytania: 10\n", $body);
        $this->assertStringContainsString('Cena: 22,15 zł netto', $body);
        // jednostka z maila klienta stoi w nagłówku pozycji, nigdy przy naszej cenie
        $this->assertStringNotContainsString('netto / szt', $body);
        $this->assertStringContainsString("Poz. 2 — ilość: 4 pary, rozmiar z zapytania: 43\n", $body);
        $this->assertStringContainsString("SKU FW94), Portwest\nNormy: S4\nCena: do potwierdzenia", $body);
        $this->assertStringContainsString("\nDopisek zostaje\n", $body);
        $this->assertStringNotContainsString('SUB1', $body);

        // zmiana towaru na drugiego kandydata i zamiennik przy pozycji
        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'product:item_1' => ['option_id' => 'p:12'],
                'substitutes:item_1' => ['option_id' => 'p:13'],
            ],
            'extra_note' => '',
        ]);

        $res->assertOk()
            ->assertJsonPath('items.0.chosen', 'p:12')
            ->assertJsonPath('extra_note', null)
            ->assertJsonPath('price.mode', 'catalog_margin');
        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('SKU 37675', $body);
        $this->assertStringContainsString("Zamiennik: Zamiennik AlphaTec (SKU SUB1), Ansell\nCena: 20,00 zł netto", $body);
        $this->assertStringNotContainsString('37900VP', $body);
        $this->assertStringNotContainsString('Dopisek zostaje', $body);
    }

    public function test_patch_saves_manual_reply_edits(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych XL.',
            'analysis' => [],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Stara treść',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/inquiries/{$inquiry->id}", ['reply_body' => 'Nowa treść'])
            ->assertOk()
            ->assertJsonPath('reply_subject', 'Oferta')
            ->assertJsonPath('reply_body', 'Nowa treść')
            ->assertJsonPath('items', []);

        $this->patchJson("/api/inquiries/{$inquiry->id}", ['reply_subject' => str_repeat('x', 256)])
            ->assertUnprocessable();

        $this->assertSame('Nowa treść', $inquiry->fresh()->reply_body);
        $this->assertSame('Oferta', $inquiry->fresh()->reply_subject);
    }

    public function test_replied_flag_is_idempotent_and_reversible(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych XL.',
            'analysis' => [],
            'reply_body' => 'Treść',
        ]);

        Sanctum::actingAs($user);

        $first = $this->postJson("/api/inquiries/{$inquiry->id}/replied", ['replied' => true])
            ->assertOk()
            ->json('replied_at');
        $this->assertNotNull($first);

        $second = $this->postJson("/api/inquiries/{$inquiry->id}/replied", ['replied' => true])
            ->assertOk()
            ->json('replied_at');
        $this->assertSame($first, $second);

        $this->getJson('/api/inquiries')
            ->assertOk()
            // lista jest stronicowana: wiersze siedzą pod „data”
            ->assertJsonPath('data.0.id', $inquiry->id)
            ->assertJsonPath('data.0.replied_at', $first)
            ->assertJsonPath('data.0.has_reply', true)
            ->assertJsonPath('data.0.attention_count', 0)
            ->assertJsonPath('meta.total', 1);

        $this->postJson("/api/inquiries/{$inquiry->id}/replied", ['replied' => false])
            ->assertOk()
            ->assertJsonPath('replied_at', null);
        $this->postJson("/api/inquiries/{$inquiry->id}/replied", ['replied' => 'tak'])
            ->assertUnprocessable();
    }

    public function test_candidate_prices_follow_the_chosen_margin(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_subject' => 'Rekawice',
            'source_body' => '30szt Rekawice chemoodporne',
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => '30szt Rekawice chemoodporne', 'qty' => '30', 'unit' => 'szt', 'query' => 'Rekawice chemoodporne'],
                ],
                'matches' => [
                    // offer_pln zapisane przy marzy domyslnej (18%)
                    ['query' => 'Rekawice chemoodporne', 'products' => [
                        ['id' => 11, 'sku' => '37900VP', 'name' => 'AlphaTec 37900VP', 'manufacturer' => 'Ansell', 'norms' => '', 'catalog_price_net' => '19.85', 'currency' => 'PLN', 'catalog_pln' => 19.85, 'offer_pln' => 22.15, 'stock' => 0, 'score' => 90],
                    ]],
                ],
                'substitutes' => [
                    11 => [['id' => 13, 'sku' => 'SUB1', 'name' => 'Zamiennik AlphaTec', 'manufacturer' => 'Ansell', 'norms' => '', 'catalog_price_net' => '18.00', 'currency' => 'PLN', 'catalog_pln' => 18.0, 'offer_pln' => 20.0, 'stock' => 9, 'score' => 0]],
                ],
                'cards' => [],
            ],
            'answers' => [
                'product:item_1' => ['option_id' => 'p:11'],
                'substitutes:item_1' => ['option_id' => 'no'],
                'price' => ['option_id' => 'catalog_margin', 'custom' => '18'],
            ],
        ]);

        Sanctum::actingAs($user);

        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '30']],
        ]);

        // panel i list licza z tej samej marzy: 22,15 / 1,18 * 1,30
        $res->assertOk()
            ->assertJsonPath('items.0.candidates.0.offer_pln', 24.4)
            ->assertJsonPath('items.0.substitutes.0.offer_pln', 22.03);
        $this->assertStringContainsString('Cena: 24,40 zl netto', str_replace(['ł', 'ę'], ['l', 'e'], (string) $res->json('reply_body')));
    }

    public function test_margin_outside_the_range_is_rejected_instead_of_silently_trimmed(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => '10 szt. rekawice nitrylowe',
            'analysis' => ['line_items' => [], 'matches' => [], 'cards' => []],
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '18']],
        ]);

        Sanctum::actingAs($user);

        // 200% bylo przycinane do 99% bez slowa, litery cofaly cene do marzy domyslnej
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '200']],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.price.custom');

        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => 'osiemnascie']],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.price.custom');

        // odrzucona marza niczego nie zapisuje
        $this->assertSame('18', (string) $inquiry->fresh()->answers['price']['custom']);

        // przecinek i znak procentu to normalny zapis, nie blad
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '12,5%']],
        ])->assertOk()->assertJsonPath('price.margin', 12.5)->assertJsonPath('price.margin_max', 99);
    }

    public function test_preferences_default_without_history(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        // Domyślny szablon to pełna specyfikacja — taki list dostawali klienci,
        // zanim doszły szablony „bez SKU” i „oficjalny”.
        $this->getJson('/api/inquiries/preferences')
            ->assertOk()
            ->assertExactJson(['tone' => 'handlowy', 'price_mode' => 'none', 'margin' => 18]);
    }

    public function test_offer_terms_go_to_the_letter_and_come_back_in_the_view(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_subject' => 'Buty robocze',
            'source_body' => 'BUTY UVEX 8543.8 S1 SRC ROZMIAR 44',
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'BUTY UVEX 8543.8 S1 SRC ROZMIAR 44', 'qty' => '1', 'unit' => null, 'size' => '44', 'query' => 'buty uvex 8543.8'],
                ],
                'matches' => [
                    ['query' => 'buty uvex 8543.8', 'products' => [
                        ['id' => 31, 'sku' => '8543/8/35', 'name' => 'Półbut Uvex 1 8543/8', 'manufacturer' => 'UVEX', 'norms' => 'EN ISO 20345 S1 SRC', 'catalog_price_net' => '304.50', 'currency' => 'PLN', 'catalog_pln' => 304.5, 'offer_pln' => 359.31, 'stock' => 1, 'score' => 95],
                    ]],
                ],
                'substitutes' => [],
                'cards' => [],
            ],
            'answers' => ['product:item_1' => ['option_id' => 'p:31']],
        ]);

        Sanctum::actingAs($user);

        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [],
            'terms' => [
                'lead_time' => '3 dni robocze od zamówienia',
                'delivery' => 'kurier, 25 zł netto',
                // puste pole nie jest warunkiem i do listu nie idzie
                'payment' => '   ',
                'validity' => '14 dni',
            ],
        ]);

        $res->assertOk()
            ->assertJsonPath('terms.lead_time', '3 dni robocze od zamówienia')
            ->assertJsonPath('terms.delivery', 'kurier, 25 zł netto')
            ->assertJsonPath('terms.payment', null)
            ->assertJsonPath('terms.validity', '14 dni');

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString("Warunki:\nTermin realizacji: 3 dni robocze od zamówienia\nDostawa: kurier, 25 zł netto\nWażność oferty: 14 dni", $body);
        $this->assertStringNotContainsString('Płatność:', $body);

        $html = (string) $res->json('reply_html');
        $this->assertStringContainsString('Termin realizacji', $html);
        $this->assertStringContainsString('3 dni robocze od zamówienia', $html);

        // brak klucza „terms” w kolejnym żądaniu zostawia zapisane warunki
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", ['answers' => []])
            ->assertOk()
            ->assertJsonPath('terms.lead_time', '3 dni robocze od zamówienia');
    }

    public function test_other_user_cannot_edit_or_mark_inquiry(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $owner->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych XL.',
        ]);

        Sanctum::actingAs($other);

        $this->patchJson("/api/inquiries/{$inquiry->id}", ['reply_body' => 'x'])->assertForbidden();
        $this->postJson("/api/inquiries/{$inquiry->id}/replied", ['replied' => true])->assertForbidden();
        $this->assertNull($inquiry->fresh()->replied_at);
    }

    public function test_compose_saves_reply_from_answers(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych do laboratorium.',
            'analysis' => [
                'questions' => ['Czy macie rękawice?'],
                'matches' => [[
                    'query' => 'rękawice nitrylowe',
                    'products' => [[
                        'id' => $product->id,
                        'sku' => 'RNITZ-100',
                        'name' => 'Rękawice nitrylowe',
                        'manufacturer' => 'Supon',
                        'norms' => 'EN 374',
                        'catalog_price_net' => '2.40',
                        'currency' => 'PLN',
                        'stock' => 80,
                        'score' => 91,
                    ]],
                ]],
                'cards' => [[
                    'id' => 'price',
                    'title' => 'Ceny',
                    'prompt' => 'Czy podać cenę?',
                    'options' => [
                        ['id' => 'none', 'label' => 'Bez ceny'],
                        ['id' => 'catalog', 'label' => 'Cena katalogowa'],
                    ],
                    'allow_custom' => false,
                ]],
            ],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'price' => ['option_id' => 'none'],
            ],
            'extra_note' => 'Nie podawaj terminu',
        ])
            ->assertOk()
            ->assertJsonPath('extra_note', 'Nie podawaj terminu');

        $body = (string) $inquiry->fresh()->reply_body;
        $this->assertStringContainsString('RNITZ-100', $body);
        $this->assertStringNotContainsString('1.10', $body);
        $this->assertStringNotContainsString('magazyn', mb_strtolower($body));
        $this->assertStringNotContainsString('Stan magazynowy', $body);
    }

    public function test_compose_letter_quotes_pln_offer_not_eur_catalog(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_subject' => 'Rękawice i kalosze',
            'source_body' => "30szt Rękawice chemoodporne rozmiar 10\n4szt Kalosze chemoodporne rozmiar 43",
            'analysis' => [
                'line_items' => [
                    [
                        'id' => 'item_1',
                        'quote' => '30szt Rękawice chemoodporne rozmiar 10',
                        'qty' => '30 szt.',
                        'size' => '10',
                        'query' => 'Rękawice chemoodporne',
                    ],
                    [
                        'id' => 'item_2',
                        'quote' => '4szt Kalosze chemoodporne rozmiar 43',
                        'qty' => '4 szt.',
                        'size' => '43',
                        'query' => 'Kalosze chemoodporne',
                    ],
                ],
                'matches' => [[
                    'query' => 'Rękawice chemoodporne',
                    'products' => [[
                        'id' => 11,
                        'sku' => '37900VP',
                        'name' => 'AlphaTec 37900VP',
                        'manufacturer' => 'Ansell',
                        'norms' => 'EN ISO 374-1',
                        'catalog_price_net' => '19.85',
                        'currency' => 'PLN',
                        'catalog_pln' => 19.85,
                        'offer_pln' => 22.15,
                        'stock' => 0,
                        'score' => 90,
                    ]],
                ], [
                    'query' => 'Kalosze chemoodporne',
                    'products' => [[
                        'id' => 22,
                        'sku' => 'FW94',
                        'name' => 'Kalosze S4',
                        'manufacturer' => 'Portwest',
                        'norms' => 'S4',
                        'catalog_price_net' => '55.46',
                        'currency' => 'PLN',
                        'catalog_pln' => 55.46,
                        'offer_pln' => 65.44,
                        'stock' => 0,
                        'score' => 88,
                    ]],
                ]],
                'cards' => [],
            ],
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'price' => ['option_id' => 'catalog_margin'],
            ],
        ])->assertOk();

        $body = (string) $inquiry->fresh()->reply_body;
        $this->assertStringContainsString('30 szt., rozmiar z zapytania: 10', $body);
        $this->assertStringContainsString('SKU 37900VP', $body);
        $this->assertStringContainsString('22,15 zł netto', $body);
        $this->assertStringContainsString('65,44 zł netto', $body);
        $this->assertStringNotContainsString('EUR', $body);
        $this->assertStringNotContainsString('Stan magazynowy', $body);
        $this->assertStringNotContainsString('4.67', $body);
        $this->assertSame('Oferta — Rękawice i kalosze', $inquiry->fresh()->reply_subject);
    }

    public function test_other_user_cannot_open_inquiry(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $owner->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych XL.',
        ]);

        Sanctum::actingAs($other);

        $this->getJson("/api/inquiries/{$inquiry->id}")->assertForbidden();
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [],
        ])->assertForbidden();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/inquiries', [
            'body' => 'Proszę o informację o rękawicach nitrylowych do laboratorium.',
            'tone' => 'formal',
        ])->assertForbidden();
    }

    public function test_ai_task_catalog_includes_client_inquiry(): void
    {
        $keys = AiTask::keys();
        $this->assertContains('client_inquiry', $keys);
    }
}
