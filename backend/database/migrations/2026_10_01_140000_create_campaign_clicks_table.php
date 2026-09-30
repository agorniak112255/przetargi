<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kliknięcia w linki kampanii (przekierowanie przez aplikację): „Zapytaj o ofertę” (offer) i strona produktu
 * (product). Kliknięcia skanerów poczty (zaraz po doręczeniu, automaty po nagłówku przeglądarki) zapisane osobno
 * jako suspected_bot i nie liczą się do zainteresowania odbiorcy. Bez adresu IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();
            $table->foreignId('campaign_item_id')->nullable()->constrained('campaign_items')->nullOnDelete();
            $table->string('kind', 10);
            $table->boolean('suspected_bot')->default(false);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('clicked_at');
            $table->timestamps();

            $table->index(['campaign_id', 'suspected_bot']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            // tylko kliknięcia ludzi (bez suspected_bot)
            $table->timestamp('first_clicked_at')->nullable()->after('unsubscribed_at');
            $table->unsignedInteger('clicks')->default(0)->after('first_clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn(['first_clicked_at', 'clicks']);
        });
        Schema::dropIfExists('campaign_clicks');
    }
};
