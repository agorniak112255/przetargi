<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('tender_document_id')->nullable()->constrained('tender_documents')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('category', 64)->nullable();
            $table->text('content');
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->index(['tender_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_conditions');
    }
};
