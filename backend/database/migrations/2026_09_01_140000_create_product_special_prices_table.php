<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_special_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');
            $table->decimal('price', 12, 4);
            $table->string('currency', 3)->default('EUR');
            $table->date('valid_from')->nullable();
            $table->string('contract_ref', 80)->default('');
            $table->string('source', 180)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'client_name', 'contract_ref'], 'product_special_prices_unique');
            $table->index(['client_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_special_prices');
    }
};
