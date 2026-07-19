<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }
        if (! Schema::hasColumn('ai_settings', 'web_search_enabled')) {
            return;
        }

        DB::table('ai_settings')->update(['web_search_enabled' => false]);
    }

    public function down(): void
    {
        // no-op — nie przywracamy drogiego trybu automatycznie
    }
};
