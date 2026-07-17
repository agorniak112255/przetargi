<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_substitutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('substitute_product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('type', ['preferowany', 'tanszy', 'premium', 'awaryjny']);
            $table->unsignedTinyInteger('match_percent')->default(0);
            $table->boolean('norms_ok')->default(true);
            $table->boolean('certs_ok')->default(true);
            $table->text('reason')->nullable();
            $table->string('approval_status', 32)->default('oczekuje');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['main_product_id', 'substitute_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_substitutes');
    }
};
