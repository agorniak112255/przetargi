<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oferty dla klientów (05.10.2026): lekka oferta z wybranymi produktami i ceną, wysyłana ze skrzynki autora
 * (osobny mail na adres) albo kopiowana do Thunderbirda. Oferta zawsze edytowalna — dowodem tego, co dostał klient,
 * jest zapis każdej wysyłki (offer_sends: dokładny HTML i tekst) z wynikiem dla każdego adresu (offer_recipients).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            // bez kaskady: konta z ofertami nie usuwa się (Admin/UserController::destroy)
            $table->foreignId('user_id')->constrained('users');
            // „OF-0001” — nadawany po zapisie (Offer::booted), stąd nullable
            $table->string('code', 20)->nullable()->unique();
            $table->string('subject', 200)->default('');
            $table->text('intro')->nullable();
            // jeden z Campaign::LAYOUTS
            $table->string('layout', 20)->default('grid3');
            $table->date('valid_until')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_copied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('offer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('erp_item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // null = cena do uzupełnienia (wysyłka i kopiowanie wymagają ceny)
            $table->decimal('price_net', 12, 2)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'position']);
        });

        Schema::create('offer_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            // dokładnie to, co wyszło do klientów (bez kopii dla nadawcy)
            $table->string('subject', 255);
            $table->mediumText('html');
            $table->mediumText('text');
            $table->timestamps();
        });

        Schema::create('offer_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('offer_send_id')->constrained('offer_sends')->cascadeOnDelete();
            $table->string('email', 254);
            // sent | failed | skipped
            $table->string('status', 10);
            $table->string('error', 500)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('offer_id');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_recipients');
        Schema::dropIfExists('offer_sends');
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offers');
    }
};
