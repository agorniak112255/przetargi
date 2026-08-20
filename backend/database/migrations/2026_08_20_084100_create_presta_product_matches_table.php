<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presta_product_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('presta_id');
            $table->string('method', 32);
            $table->unsignedTinyInteger('score');
            $table->string('status', 24);
            $table->string('presta_url', 500)->nullable();
            $table->string('presta_reference', 128)->nullable();
            $table->string('presta_name', 255)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'presta_id']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presta_product_matches');
    }
};
