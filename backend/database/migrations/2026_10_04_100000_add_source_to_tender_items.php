<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pochodzenie pozycji przetargu: 'notice_text' (towar z treści ogłoszenia), 'document' (odczyt dokumentu
        // postępowania), null — pozycja dodana ręcznie albo przed wdrożeniem. source_ref — np. numer ogłoszenia albo id
        // dokumentu. Dokument uzupełnia tylko pozycje z ogłoszenia (source = 'notice_text'), zamiast je dublować.
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->string('source', 32)->nullable()->after('status');
            $table->string('source_ref', 191)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
