<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_items', function (Blueprint $table) {
            $table->decimal('margin_percent', 8, 2)->nullable()->change();
        });

        Schema::table('tenders', function (Blueprint $table) {
            $table->decimal('margin_percent', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table) {
            $table->decimal('margin_percent', 5, 2)->nullable()->change();
        });

        Schema::table('tenders', function (Blueprint $table) {
            $table->decimal('margin_percent', 5, 2)->nullable()->change();
        });
    }
};
