<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prośba „Otwórz w Thunderbirdzie” z okna oferty dla klienta: przeglądarka nie sięgnie do poczty
        // na komputerze, więc treść czeka tu, aż dodatek do Thunderbirda ją podejmie (claimed_at).
        Schema::create('offer_compose_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->mediumText('body_html');
            $table->text('body_text')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'claimed_at', 'requested_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Ostatnie pytanie dodatku, który umie otwierać oferty (1.24.0+) — po nim okno oferty
            // wie, czy pokazać „Otwórz w Thunderbirdzie”.
            $table->timestamp('thunderbird_offers_seen_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('thunderbird_offers_seen_at');
        });
        Schema::dropIfExists('offer_compose_requests');
    }
};
