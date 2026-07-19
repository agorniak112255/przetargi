<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained('tender_items')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_activities');
    }
};
