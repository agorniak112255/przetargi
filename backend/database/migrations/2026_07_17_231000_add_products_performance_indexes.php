<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index('name');
            $table->index('manufacturer');
            $table->index('category');
        });

        Schema::table('product_substitutes', function (Blueprint $table): void {
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['name']);
            $table->dropIndex(['manufacturer']);
            $table->dropIndex(['category']);
        });

        Schema::table('product_substitutes', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
        });
    }
};
