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
            $table->string('custom_name', 500)->nullable()->after('match_source');
            $table->string('custom_url', 2048)->nullable()->after('custom_name');
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropColumn(['custom_name', 'custom_url']);
        });
    }
};
