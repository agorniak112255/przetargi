<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozmowy głosowe i wideo w czacie (LiveKit): rozmowa w pokoju `call-{id}` i stan każdej osoby. Stan „joined”
 * potwierdza wyłącznie webhook LiveKit (livekit_sid = sesja, która się połączyła — starsze sesje po zmianie
 * urządzenia są ignorowane).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // audio | video
            $table->string('kind', 8);
            // ringing | active | ended | missed (dwa ostatnie są końcowe)
            $table->string('status', 8);
            // wiadomość kind=call w rozmowie — bez klucza obcego, jak chat_conversations.last_message_id
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
            $table->index('status');
        });

        Schema::create('chat_call_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // invited | connecting | declined | joined | left
            $table->string('state', 12);
            $table->string('livekit_sid', 64)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['call_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_members');
        Schema::dropIfExists('chat_calls');
    }
};
