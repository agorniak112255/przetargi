<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, int|bool> */
    private const COLUMNS = [
        'match_apply_score' => 40,
        'match_substitute_score' => 55,
        'match_min_score' => 65,
        'match_allow_catalog_rows' => false,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $default) {
                if (Schema::hasColumn('ai_settings', $column)) {
                    continue;
                }
                if (is_bool($default)) {
                    $table->boolean($column)->default($default);

                    continue;
                }
                $table->unsignedTinyInteger($column)->default($default);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        $drop = array_values(array_filter(
            array_keys(self::COLUMNS),
            static fn (string $column): bool => Schema::hasColumn('ai_settings', $column)
        ));
        if ($drop === []) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) use ($drop): void {
            $table->dropColumn($drop);
        });
    }
};
