<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ten sam mail od klienta trafia do kilku handlowców (wysyłka na kilka adresów
 * albo przekierowanie ze skrzynki ogólnej). System ma o tym uprzedzić, zanim
 * druga osoba zacznie pisać drugą ofertę.
 */
final class ClientInquiryDuplicateApiTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL = "Dzień dobry,\n\nproszę o wycenę 10 szt. rękawic nitrylowych rozmiar 9\noraz 4 par butów roboczych S3 rozmiar 43.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function mockAnalysis(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->andReturn([]);
        });
    }

    public function test_second_person_with_the_same_mail_is_warned_instead_of_duplicating_work(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        Sanctum::actingAs($anna);
        $first = $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertCreated();

        Sanctum::actingAs($piotr);
        $conflict = $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertStatus(409);

        $conflict
            ->assertJsonPath('duplicate.id', $first->json('id'))
            ->assertJsonPath('duplicate.user.name', 'Anna Kowalska')
            ->assertJsonPath('duplicate.match', 'message_id')
            ->assertJsonPath('duplicate.replied_at', null);
        $this->assertStringContainsString('Anna Kowalska', (string) $conflict->json('message'));

        // drugie zapytanie NIE powstało
        $this->assertSame(1, ClientInquiry::query()->count());
    }

    public function test_manually_forwarded_mail_is_recognised_by_its_content(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        Sanctum::actingAs($anna);
        $first = $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<oryginal@poczta.example>',
        ])->assertCreated();

        // kolega przekazał tego maila ręcznie: inny identyfikator i notka u góry
        Sanctum::actingAs($piotr);
        $this->postJson('/api/inquiries', [
            'body' => "zapytanie:\n\n".self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<przekazane@poczta.example>',
        ])
            ->assertStatus(409)
            ->assertJsonPath('duplicate.id', $first->json('id'))
            ->assertJsonPath('duplicate.match', 'fingerprint');

        $this->assertSame(1, ClientInquiry::query()->count());
    }

    public function test_own_second_click_still_opens_the_same_inquiry(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($anna);

        $payload = [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<moj@poczta.example>',
        ];

        $first = $this->postJson('/api/inquiries', $payload)->assertCreated();
        $this->postJson('/api/inquiries', $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));

        $this->assertSame(1, ClientInquiry::query()->count());
    }

    public function test_force_creates_a_linked_copy_and_both_sides_see_each_other(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        Sanctum::actingAs($anna);
        $annaId = (int) $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($piotr);
        $piotrId = (int) $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
            'force' => true,
        ])->assertCreated()->json('id');

        $this->assertNotSame($annaId, $piotrId);

        // kopia wie, czyja jest kopią
        $this->getJson("/api/inquiries/{$piotrId}")
            ->assertOk()
            ->assertJsonPath('duplicate_of.id', $annaId)
            ->assertJsonPath('duplicate_of.user.name', 'Anna Kowalska')
            ->assertJsonPath('duplicates.0.id', $annaId);

        // a oryginał widzi, że ktoś jeszcze robi to samo
        Sanctum::actingAs($anna);
        $this->getJson("/api/inquiries/{$annaId}")
            ->assertOk()
            ->assertJsonPath('duplicate_of', null)
            ->assertJsonPath('duplicates.0.id', $piotrId)
            ->assertJsonPath('duplicates.0.user.name', 'Piotr Nowak');
    }

    public function test_warning_says_when_the_customer_already_got_an_answer(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($anna);
        $annaId = (int) $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertCreated()->json('id');
        $this->postJson("/api/inquiries/{$annaId}/replied", ['replied' => true])->assertOk();

        Sanctum::actingAs($piotr);
        $conflict = $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertStatus(409);

        $this->assertNotNull($conflict->json('duplicate.replied_at'));
    }

    public function test_different_mail_is_not_treated_as_duplicate(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create();
        $piotr = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($anna);
        $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<pierwszy@poczta.example>',
        ])->assertCreated();

        Sanctum::actingAs($piotr);
        $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry,\n\nproszę o ofertę na 25 szt. kasków budowlanych białych z regulacją.",
            'tone' => 'formal',
            'source_message_id' => '<drugi@poczta.example>',
        ])->assertCreated();

        $this->assertSame(2, ClientInquiry::query()->count());
    }

    public function test_sales_role_can_list_inquiries_of_the_whole_team(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        Sanctum::actingAs($anna);
        $annaId = (int) $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($piotr);
        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonPath('meta.can_view_all', true)
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/inquiries?scope=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $annaId)
            ->assertJsonPath('data.0.user.name', 'Anna Kowalska')
            ->assertJsonPath('data.0.duplicates_count', 0);
    }

    public function test_list_shows_how_many_people_have_the_same_mail(): void
    {
        $this->mockAnalysis();

        $anna = User::factory()->withRole('handlowiec')->create();
        $piotr = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($anna);
        $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
        ])->assertCreated();

        Sanctum::actingAs($piotr);
        $this->postJson('/api/inquiries', [
            'body' => self::MAIL,
            'tone' => 'formal',
            'source_message_id' => '<wspolny@poczta.example>',
            'force' => true,
        ])->assertCreated();

        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.duplicates_count', 1);
    }
}
