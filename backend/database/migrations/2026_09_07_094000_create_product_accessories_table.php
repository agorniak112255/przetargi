<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_accessories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('source', 16);
            $table->string('link_key', 80);
            $table->unsignedInteger('presta_parent_id')->nullable();
            $table->unsignedInteger('presta_related_id')->nullable();
            $table->string('related_sku', 128)->nullable();
            $table->string('related_ean', 32)->nullable();
            $table->string('related_name', 255)->nullable();
            $table->string('related_manufacturer', 128)->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('method', 32)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'link_key']);
            $table->index(['product_id', 'source']);
            $table->index('related_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_accessories');
    }
};
