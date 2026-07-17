<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->unsignedInteger('prices_changed')->default(0)->after('products_updated');
            $table->json('price_changes')->nullable()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn(['prices_changed', 'price_changes']);
        });
    }
};
