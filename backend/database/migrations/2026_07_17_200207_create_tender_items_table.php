<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->text('requirement');
            $table->foreignId('main_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedTinyInteger('ai_match_percent')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->string('status', 32)->default('matched');
            $table->timestamps();

            $table->unique(['tender_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_items');
    }
};
