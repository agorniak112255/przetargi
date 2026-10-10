<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pliki cenników zapisane na dysku (10.10.2026). Dotąd import czytał tylko plik tymczasowy uploadu — importer
 * per cennik powstaje PO dodaniu pliku, a naprawy (products:repair-price-list-codes) potrzebują oryginału.
 * Ścieżka zależy od sha256, nie od id (ten sam plik ma tę samą nazwę lokalnie i na produkcji).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->char('sha256', 64);
            $table->string('disk', 20)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size');
            $table->string('mime', 100)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // new | imported | failed | superseded
            $table->string('status', 16)->default('new');
            $table->text('error')->nullable();
            $table->string('importer_key', 60)->nullable();
            $table->unsignedSmallInteger('importer_version')->nullable();
            $table->foreignId('price_list_import_id')->nullable()->constrained('price_list_imports')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'sha256'], 'price_list_files_list_sha_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_files');
    }
};
