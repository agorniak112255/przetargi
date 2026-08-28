<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('tone', 20)->default('formal');
            $table->string('source_subject', 255)->nullable();
            $table->longText('source_body');
            $table->json('analysis')->nullable();
            $table->json('answers')->nullable();
            $table->string('extra_note', 1000)->nullable();
            $table->string('reply_subject', 255)->nullable();
            $table->longText('reply_body')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_inquiries');
    }
};
