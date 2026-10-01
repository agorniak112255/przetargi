<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\Campaigns\CampaignBlocks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Szablony maili kampanii: własne i wspólne (jak grupy odbiorców), zapis bloków, usuwanie, podgląd. */
final class CampaignTemplateApiTest extends TestCase
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

    public function test_crud_row_shape_and_lenient_blocks(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        Sanctum::actingAs($user);

        $this->postJson('/api/campaign-templates', ['name' => '', 'blocks' => CampaignBlocks::standard()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/campaign-templates', ['name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('blocks');
        $this->postJson('/api/campaign-templates', ['name' => 'X', 'blocks' => [['type' => 'text', 'text' => 'bez produktów']]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks');
        $this->postJson('/api/campaign-templates', ['name' => 'X', 'blocks' => CampaignBlocks::standard(), 'brand_color' => '#ff00ff'])
            ->assertUnprocessable()->assertJsonValidationErrors('brand_color');

        // niekompletny przycisk wolno zapisać w szablonie
        $blocks = [...CampaignBlocks::standard(), ['type' => 'button', 'label' => '', 'url' => '']];
        $res = $this->postJson('/api/campaign-templates', ['name' => ' Jesień ', 'blocks' => $blocks, 'brand_color' => '#1f5fa8'])->assertCreated();
        $id = $res->json('id');
        $this->assertSame(['id', 'name', 'is_shared', 'owner', 'can_edit', 'brand_color', 'blocks', 'updated_at'], array_keys($res->json()));
        $res->assertJsonPath('name', 'Jesień')
            ->assertJsonPath('is_shared', false)
            ->assertJsonPath('owner', ['id' => $user->id, 'name' => 'Anna'])
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('brand_color', '#1f5fa8')
            ->assertJsonPath('blocks.5', ['type' => 'button', 'label' => '', 'url' => ''])
            ->assertJsonPath('updated_at', '2026-09-30T10:00:00+00:00');

        $this->patchJson("/api/campaign-templates/{$id}", ['name' => 'Jesień 2026', 'brand_color' => null, 'blocks' => [['type' => 'products', 'layout' => 'list']]])
            ->assertOk()
            ->assertJsonPath('name', 'Jesień 2026')
            ->assertJsonPath('brand_color', null)
            ->assertJsonPath('blocks', [['type' => 'products', 'layout' => 'list']]);
        $this->patchJson("/api/campaign-templates/{$id}", ['blocks' => [['type' => 'heading', 'text' => str_repeat('a', 201)], ['type' => 'products']]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks.0.text');
        $this->assertSame([['type' => 'products', 'layout' => 'list']], CampaignTemplate::query()->findOrFail($id)->blocks);
        // niedokończony adres wolno zapisać (autozapis); do maila trafia tylko poprawny link
        $this->patchJson("/api/campaign-templates/{$id}", ['blocks' => [['type' => 'button', 'label' => 'X', 'url' => 'javascript:alert(1)'], ['type' => 'products']]])
            ->assertOk()->assertJsonPath('blocks.0.url', 'javascript:alert(1)');
        $html = $this->postJson('/api/campaign-templates/preview', ['blocks' => CampaignTemplate::query()->findOrFail($id)->blocks])->assertOk()->json('html');
        $this->assertStringNotContainsString('javascript:', $html);

        $this->getJson('/api/campaign-templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_visibility_foreign_private_404_and_shared_only_for_manage(): void
    {
        $a = User::factory()->withRole('handlowiec')->create();
        $b = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create(['name' => 'Admin']);
        $mineA = $this->template($a, 'B własny A');
        $mineB = $this->template($b, 'Prywatny B');
        $shared = $this->template($admin, 'Wspólny', shared: true);

        Sanctum::actingAs($a);
        $rows = $this->getJson('/api/campaign-templates')->assertOk()->json('data');
        // wspólne pierwsze, potem nazwa; cudzy prywatny niewidoczny
        $this->assertSame([$shared->id, $mineA->id], array_column($rows, 'id'));
        $this->assertSame([false, true], array_column($rows, 'can_edit'));
        $this->assertSame(['id' => $admin->id, 'name' => 'Admin'], $rows[0]['owner']);

        $this->patchJson("/api/campaign-templates/{$mineB->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/campaign-templates/{$mineB->id}")->assertNotFound();
        $this->patchJson("/api/campaign-templates/{$shared->id}", ['name' => 'X'])->assertForbidden()
            ->assertJsonPath('message', 'Wspólne szablony prowadzi administrator.');
        $this->deleteJson("/api/campaign-templates/{$shared->id}")->assertForbidden();
        $this->postJson('/api/campaign-templates', ['name' => 'Mój wspólny', 'blocks' => CampaignBlocks::standard(), 'is_shared' => true])
            ->assertForbidden()->assertJsonPath('message', 'Wspólne szablony prowadzi administrator.');
        $this->patchJson("/api/campaign-templates/{$mineA->id}", ['is_shared' => true])->assertForbidden();
        // is_shared bez zmiany wolno odesłać
        $this->patchJson("/api/campaign-templates/{$mineA->id}", ['is_shared' => false, 'name' => 'A2'])->assertOk();
        $this->assertSame(1, CampaignTemplate::query()->where('is_shared', true)->count());

        Sanctum::actingAs($admin);
        $this->patchJson("/api/campaign-templates/{$shared->id}", ['name' => 'Wspólny 2'])->assertOk()->assertJsonPath('can_edit', true);
        $res = $this->postJson('/api/campaign-templates', ['name' => 'Drugi wspólny', 'blocks' => CampaignBlocks::standard(), 'is_shared' => true])->assertCreated();
        $res->assertJsonPath('is_shared', true);
        // administrator nie widzi cudzych prywatnych
        $this->getJson('/api/campaign-templates')->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson("/api/campaign-templates/{$mineA->id}", ['name' => 'X'])->assertNotFound();

        // po usunięciu autora wspólny zostaje bez właściciela
        $admin2 = User::factory()->withRole('admin')->create();
        $orphan = $this->template($admin2, 'Po odejściu', shared: true);
        $admin2->delete();
        Sanctum::actingAs($a);
        $row = collect($this->getJson('/api/campaign-templates')->json('data'))->firstWhere('id', $orphan->id);
        $this->assertNull($row['owner']);
    }

    public function test_delete_keeps_campaign_blocks_and_clears_template_id(): void
    {
        $user = $this->sender();
        $template = $this->template($user, 'Mój', blocks: [['type' => 'products', 'layout' => 'grid2'], ['type' => 'footer', 'text' => 'Stopka']]);
        $campaign = $this->campaign($user, [], ['blocks' => $template->blocks, 'template_id' => $template->id]);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/campaign-templates/{$template->id}")->assertNoContent();
        $this->assertNull(CampaignTemplate::query()->find($template->id));
        $campaign->refresh();
        $this->assertNull($campaign->template_id);
        $this->assertSame([['type' => 'products', 'layout' => 'grid2'], ['type' => 'footer', 'text' => 'Stopka']], $campaign->blocks);
    }

    public function test_template_with_removed_layout_is_shown_and_applied_with_replacement(): void
    {
        $user = $this->sender();
        $template = $this->template($user, 'Stary', blocks: [['type' => 'products', 'layout' => 'sale']]);
        $campaign = $this->campaign($user, []);

        Sanctum::actingAs($user);
        $row = collect($this->getJson('/api/campaign-templates')->assertOk()->json('data'))->firstWhere('id', $template->id);
        $this->assertSame([['type' => 'products', 'layout' => 'grid2']], $row['blocks']);
        $this->assertSame('sale', $template->fresh()->blocks[0]['layout']);

        $this->postJson("/api/campaigns/{$campaign->id}/template", ['template_id' => $template->id])->assertOk();
        $this->assertSame([['type' => 'products', 'layout' => 'grid2']], $campaign->fresh()->blocks);
    }

    public function test_preview_renders_sample_products_without_saving(): void
    {
        $user = $this->sender();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/campaign-templates/preview', [
            'blocks' => [
                ['type' => 'heading', 'text' => '<b>Nowości</b>'],
                ['type' => 'products', 'layout' => 'grid2'],
                ['type' => 'button', 'label' => 'Katalog', 'url' => 'https://supon.pl/katalog'],
            ],
            'brand_color' => '#b3261e',
        ])->assertOk();

        $this->assertSame(['subject', 'html', 'text'], array_keys($res->json()));
        $this->assertSame('', $res->json('subject'));
        $html = $res->json('html');
        $this->assertSame(3, substr_count($html, 'Przykładowy produkt'));
        $this->assertStringContainsString('Kod PRZYKLAD3', $html);
        $this->assertStringContainsString("99,00\u{00A0}zł", $html);
        $this->assertStringContainsString('&lt;b&gt;Nowości&lt;/b&gt;', $html);
        $this->assertStringContainsString('bgcolor="#b3261e"', $html);
        $this->assertStringContainsString('href="https://supon.pl/katalog"', $html);
        $this->assertStringContainsString('mailto:jan@supon.example.pl?subject='.rawurlencode('Zapytanie K-0000 PRZYKLAD1'), $html);
        // podpis oglądającego i linia wypisu zawsze
        $this->assertStringContainsString('Jan Handlowiec<br>', $html);
        $this->assertStringContainsString('Wypisz mnie z mailingu', $html);
        $this->assertStringContainsString('Katalog: https://supon.pl/katalog', $res->json('text'));
        $this->assertSame(0, CampaignTemplate::query()->count());

        $this->postJson('/api/campaign-templates/preview', ['blocks' => [['type' => 'text', 'text' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('blocks');
    }

    public function test_templates_require_campaigns_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/campaign-templates')->assertForbidden();
        $this->postJson('/api/campaign-templates/preview', ['blocks' => CampaignBlocks::standard()])->assertForbidden();
    }

    /** @param  list<array<string, mixed>>|null  $blocks */
    private function template(User $owner, string $name, bool $shared = false, ?array $blocks = null): CampaignTemplate
    {
        return CampaignTemplate::query()->create([
            'user_id' => $owner->id, 'name' => $name, 'is_shared' => $shared, 'blocks' => $blocks ?? CampaignBlocks::standard(),
        ]);
    }
}
