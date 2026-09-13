<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiSettingsService;
use App\Support\CatalogSlangDictionary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kopia słownika żargonu w `ai_settings.catalog_slang` była zapisana z `jargon=true`
 * dla każdego wpisu (stare `normalize()` ignorowało flagę). Migracja odtwarza flagę
 * z domyślnych wpisów, własne wpisy admina zostawia żargonem, a usunięty wpis
 * „półmaska → półmaska wielorazowa” zdejmuje także z kopii.
 */
final class CatalogSlangJargonFlagMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_13_090000_restore_catalog_slang_jargon_flags.php';

    public function test_migration_restores_jargon_flags_from_defaults_and_drops_removed_entry(): void
    {
        $this->seedLegacyCopy();

        $this->migration()->up();

        $entries = $this->freshSettings()->catalogSlang();
        $byTerm = $this->indexByTerm($entries);

        // Domyślne wpisy odzyskują flagę z config: klasa obuwia i cecha to nie żargon, wampirki tak.
        $this->assertFalse($byTerm['s5']['jargon']);
        $this->assertFalse($byTerm['antyprzebiciowe']['jargon']);
        $this->assertFalse($byTerm['chemiczne']['jargon']);
        $this->assertTrue($byTerm['wampirki']['jargon']);
        // Wpis domyślny zmieniony przez admina: zostaje jego treść, flaga z domyślnych.
        $this->assertSame('moja notatka o klasach', $byTerm['s5']['note']);
        // Własny wpis admina — żargon.
        $this->assertTrue($byTerm['łapki']['jargon']);
        $this->assertSame(['rękawice robocze'], $byTerm['łapki']['phrases']);
        // Niezmieniony wpis domyślny podmieniony na aktualny (nowe terminy „z zaworem”).
        $this->assertArrayHasKey('z zaworem', $byTerm);
        // Wpis „półmaska → półmaska wielorazowa” znika z kopii.
        foreach ($entries as $entry) {
            $this->assertNotContains('półmaska wielorazowa', $entry['phrases']);
        }
        $this->assertArrayNotHasKey('półmaska', $byTerm);
    }

    public function test_migration_leaves_installations_without_saved_copy_untouched(): void
    {
        AiSetting::query()->create($this->settingsRow(null));

        $this->migration()->up();

        $this->assertNull(AiSetting::query()->firstOrFail()->catalog_slang);
        $this->assertSame(CatalogSlangDictionary::defaults(), $this->freshSettings()->catalogSlang());
    }

    public function test_migration_down_restores_legacy_flags_and_entry(): void
    {
        $this->seedLegacyCopy();
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $entries = $this->freshSettings()->catalogSlang();
        foreach ($entries as $entry) {
            $this->assertTrue($entry['jargon'], implode(', ', $entry['terms']));
        }
        $this->assertArrayHasKey('półmaska', $this->indexByTerm($entries));
    }

    private function seedLegacyCopy(): void
    {
        $legacy = [];
        foreach (CatalogSlangDictionary::defaults() as $entry) {
            if (in_array('z zaworem', $entry['terms'], true)) {
                // Stara postać wpisu sprzed rozszerzenia terminów.
                $entry['terms'] = ['z zaworkiem'];
            }
            if (in_array('S5', $entry['terms'], true)) {
                $entry['note'] = 'moja notatka o klasach';
            }
            $entry['jargon'] = true;
            $legacy[] = $entry;
        }
        $legacy[] = ['category' => 'oddech', 'terms' => ['półmaska'], 'phrases' => ['półmaska wielorazowa'], 'note' => '', 'jargon' => true, 'keywords' => [], 'tags' => []];
        $legacy[] = ['category' => 'rece', 'terms' => ['łapki'], 'phrases' => ['rękawice robocze'], 'note' => '', 'jargon' => true, 'keywords' => [], 'tags' => []];

        AiSetting::query()->create($this->settingsRow($legacy));
    }

    /**
     * @param  list<array<string, mixed>>|null  $slang
     * @return array<string, mixed>
     */
    private function settingsRow(?array $slang): array
    {
        return [
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'catalog_slang' => $slang,
        ];
    }

    private function migration(): Migration
    {
        $migration = require database_path(self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function freshSettings(): AiSettingsService
    {
        return $this->app->make(AiSettingsService::class);
    }

    /**
     * @param  list<array{terms: list<string>}>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function indexByTerm(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            foreach ($entry['terms'] as $term) {
                $out[mb_strtolower($term)] = $entry;
            }
        }

        return $out;
    }
}
