<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerty „Stanu systemu”: jeden otwarty wiersz na incydent (zadanie nocne, konto dostawcy), e-mail do
 * administratora raz na incydent, wyciszanie przez człowieka, zamknięcie przy pierwszym udanym przebiegu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('subject_key', 120);
            $table->string('title', 255);
            $table->timestamp('first_failed_at');
            $table->timestamp('last_failed_at');
            $table->unsignedInteger('failures')->default(1);
            $table->text('last_message')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['subject_key', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
