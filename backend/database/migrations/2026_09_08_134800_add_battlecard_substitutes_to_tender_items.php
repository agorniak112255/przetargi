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
            $table->json('battlecard_substitutes')->nullable()->after('match_source');
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropColumn('battlecard_substitutes');
        });
    }
};
