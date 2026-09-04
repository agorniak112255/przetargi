<?php

declare(strict_types=1);

use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enrichment_description_templates')) {
            return;
        }

        Schema::create('enrichment_description_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('kategoria_bhp', 40)->unique();
            $table->text('instructions');
            $table->timestamps();
        });

        foreach (EnrichmentDescriptionTemplates::defaults() as $key => $instructions) {
            DB::table('enrichment_description_templates')->insert([
                'kategoria_bhp' => $key,
                'instructions' => $instructions,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_description_templates');
    }
};
