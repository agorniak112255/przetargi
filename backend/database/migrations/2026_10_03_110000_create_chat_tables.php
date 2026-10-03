<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Czat firmowy: rozmowy (kanały i 1:1), uczestnicy z miejscem przeczytania i wiadomości.
 * Kanał „Ogólny” (everyone) obejmuje każdego z uprawnieniem `chat` — wiersze uczestników powstają leniwie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16);
            $table->string('name', 100)->nullable();
            $table->boolean('everyone')->default(false);
            // rozmowa 1:1: „mniejszeId:większeId” — jedna rozmowa na parę osób
            $table->string('direct_key', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // bez klucza obcego: wiadomość wskazuje rozmowę, więc klucz w obie strony zablokowałby kasowanie
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            // konto usunięte: wiadomość zostaje bez autora („konto usunięte”)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->string('client_uuid', 36)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_uuid']);
            $table->index(['conversation_id', 'id']);
        });

        $now = now();
        DB::table('chat_conversations')->insert([
            'type' => 'channel',
            'name' => 'Ogólny',
            'everyone' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
    }
};
