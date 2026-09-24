<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pochodzenie zapisanego zrozumienia: model, dostawca OpenRoutera i profil, który odpowiedział. Na produkcji 22–24.09.2026
 * część zrozumień przyszła z konfiguracji głównej po awarii profilu i nie dało się ich odróżnić od pozostałych.
 * Wiersze sprzed tej migracji zostają bez pochodzenia (null).
 */
return new class extends Migration
{
    private const COLUMNS = ['model', 'provider', 'profile'];

    public function up(): void
    {
        Schema::table('requirement_understandings', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('requirement_understandings', $column)) {
                    $table->string($column, 191)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('requirement_understandings', $column),
        ));
        if ($present === []) {
            return;
        }
        Schema::table('requirement_understandings', function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }
};
