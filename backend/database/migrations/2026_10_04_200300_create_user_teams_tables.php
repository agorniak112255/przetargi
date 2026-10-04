<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zespoły (04.10.2026, Administracja → Role): kto komu podlega. Kierownik zespołu (is_leader) widzi w raporcie
 * „Wynik kampanii” kampanie członków swoich zespołów. Niezależne od ról uprawnień (każdy ma jedną rolę).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('user_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('user_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_leader')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_team_members');
        Schema::dropIfExists('user_teams');
    }
};
