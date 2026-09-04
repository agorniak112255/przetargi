<?php

declare(strict_types=1);

use App\Support\EnrichmentDescriptionLayouts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enrichment_description_templates')
            && ! Schema::hasColumn('enrichment_description_templates', 'layout')) {
            Schema::table('enrichment_description_templates', function (Blueprint $table): void {
                $table->json('layout')->nullable()->after('instructions');
            });
        }

        if (! Schema::hasTable('enrichment_description_templates')) {
            return;
        }

        $exists = DB::table('enrichment_description_templates')
            ->where('kategoria_bhp', EnrichmentDescriptionLayouts::DEFAULT_KEY)
            ->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('enrichment_description_templates')->insert([
            'kategoria_bhp' => EnrichmentDescriptionLayouts::DEFAULT_KEY,
            'instructions' => 'Układ wizualny karty i eksportu — nie jest wysyłany do modelu.',
            'layout' => json_encode(EnrichmentDescriptionLayouts::defaultStoredLayout(), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrichment_description_templates')) {
            return;
        }

        DB::table('enrichment_description_templates')
            ->where('kategoria_bhp', EnrichmentDescriptionLayouts::DEFAULT_KEY)
            ->delete();

        if (Schema::hasColumn('enrichment_description_templates', 'layout')) {
            Schema::table('enrichment_description_templates', function (Blueprint $table): void {
                $table->dropColumn('layout');
            });
        }
    }
};
