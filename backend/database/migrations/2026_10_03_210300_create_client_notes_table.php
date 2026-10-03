<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notatki handlowców na karcie klienta z opcjonalnym przypomnieniem (remind_on — dzień w Polsce). Przypomina
 * crm:remind autorowi notatki (zdarzenie client_note_reminder); reminded_at = kiedy przypomnienie wyszło.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->date('remind_on')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index('remind_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};
