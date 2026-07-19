<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->json('ai_match_reasons')->nullable()->after('ai_match_percent');
            $table->string('match_source', 32)->nullable()->after('ai_match_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropColumn(['ai_match_reasons', 'match_source']);
        });
    }
};
