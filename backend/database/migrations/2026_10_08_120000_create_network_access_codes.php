<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dostęp spoza sieci lokalnej z kodem e-mailem (tryb `local_code`, zob. NetworkAccessPolicy):
 * - network_access_challenges: jedno logowanie spoza sieci po dobrym haśle; kod (HMAC) i liczniki prób.
 * - network_access_grants: konto + adres (IPv4 albo sieć IPv6 /64) może pracować do expires_at.
 *   Odebranie = expires_at w przeszłości; kto odebrał — dziennik aktywności.
 * Kolumny czasu nullable: produkcja (MariaDB, explicit_defaults_for_timestamp=0, NO_ZERO_DATE) dałaby pierwszemu
 * NOT NULL timestamp ON UPDATE CURRENT_TIMESTAMP, a kolejnym niedozwoloną domyślną datę zerową. Aplikacja zawsze
 * je ustawia, a null przy sprawdzaniu ważności znaczy „nieważne”.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_access_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('ip', 64);
            $table->char('code_hash', 64)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('network_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 64);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_access_grants');
        Schema::dropIfExists('network_access_challenges');
    }
};
