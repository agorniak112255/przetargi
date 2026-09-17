<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EnrichmentDescriptionTemplate;
use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * `ensureSeeded()` dopisuje tylko brakujące klucze, więc nowy szablon obuwia dociera do
 * działającej instalacji dopiero migracją. Migracji wolno podmienić wyłącznie wiersz ze
 * starą treścią domyślną — instrukcja zmieniona ręcznie w panelu zostaje nietknięta.
 */
final class ObuwieDescriptionTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_18_093000_refresh_obuwie_description_template.php';

    public function test_migration_replaces_previous_default_instructions(): void
    {
        $this->storeInstructions($this->previousDefault());

        $this->migration()->up();

        $this->assertSame(
            EnrichmentDescriptionTemplates::defaultInstructions('obuwie'),
            $this->storedInstructions()
        );
    }

    public function test_migration_leaves_manually_edited_instructions_untouched(): void
    {
        $custom = "To obuwie ochronne — moja własna instrukcja.\nZbieraj wyłącznie klasę ochrony.";
        $this->storeInstructions($custom);

        $this->migration()->up();

        $this->assertSame($custom, $this->storedInstructions());
    }

    public function test_migration_down_restores_previous_default_instructions(): void
    {
        $this->storeInstructions($this->previousDefault());
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame($this->previousDefault(), $this->storedInstructions());
    }

    public function test_migration_down_leaves_manually_edited_instructions_untouched(): void
    {
        $custom = "To obuwie ochronne — moja własna instrukcja.\nZbieraj wyłącznie klasę ochrony.";
        $this->storeInstructions($custom);

        $this->migration()->down();

        $this->assertSame($custom, $this->storedInstructions());
    }

    private function storeInstructions(string $instructions): void
    {
        EnrichmentDescriptionTemplate::query()->updateOrCreate(
            ['kategoria_bhp' => 'obuwie'],
            ['instructions' => $instructions],
        );
    }

    private function storedInstructions(): string
    {
        return (string) EnrichmentDescriptionTemplate::query()
            ->where('kategoria_bhp', 'obuwie')
            ->value('instructions');
    }

    /** Stara treść domyślna jest zamrożona w migracji — bierzemy ją stamtąd, bez kopiowania. */
    private function previousDefault(): string
    {
        $value = (new ReflectionClass($this->migration()))->getConstant('PREVIOUS_DEFAULT');
        $this->assertIsString($value);

        return $value;
    }

    private function migration(): Migration
    {
        $migration = require database_path(self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
