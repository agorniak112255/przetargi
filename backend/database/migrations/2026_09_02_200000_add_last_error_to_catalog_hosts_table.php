<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_hosts', function (Blueprint $table): void {
            $table->string('last_error', 500)->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_hosts', function (Blueprint $table): void {
            $table->dropColumn('last_error');
        });
    }
};
