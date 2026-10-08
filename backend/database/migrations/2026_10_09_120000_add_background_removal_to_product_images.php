<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuwanie tła ze zdjęć kart (09.10.2026, decyzja właściciela: „tylko bez tła”, oryginał do przywrócenia).
 * Po wycięciu `path` wskazuje plik PNG bez tła, a `original_path` — oryginał, który zostaje na dysku. `checksum`
 * zostaje od oryginału: to tożsamość pobranego pliku (dedup synchronizacji, odrzucenia zdjęć, unikat karty).
 *
 * Tabela `jobs_images` — osobna kolejka zadań (połączenie `database_images`, config/queue.php), jak `jobs_embeddings`:
 * MariaDB 10.5 bez SKIP LOCKED zakleszcza workery na wspólnej tabeli `jobs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('original_path', 500)->nullable()->after('path');
            // queued | done | failed | skipped; null = nikt nie zlecał
            $table->string('background_status', 16)->nullable()->after('original_path');
            $table->string('background_note', 255)->nullable()->after('background_status');
            $table->timestamp('background_removed_at')->nullable()->after('background_note');
        });

        if (! Schema::hasTable('jobs_images')) {
            Schema::create('jobs_images', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs_images');

        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn(['original_path', 'background_status', 'background_note', 'background_removed_at']);
        });
    }
};
