<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_groups', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer', 100);
            $table->string('name', 150);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_global')->default(false);
            $table->timestamps();

            $table->unique(['manufacturer', 'name']);
            $table->index(['manufacturer', 'is_global']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('assortment_group_id')
                ->nullable()
                ->after('category')
                ->constrained('assortment_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assortment_group_id');
        });
        Schema::dropIfExists('assortment_groups');
    }
};
