<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa połączeń (plan łączenia kart, „Wersja uzgodniona C2 + C3”, pomysł użytkownika 24.09.2026): wiersz = kod ze
 * źródła (konto B2B albo cennik z pliku + kod pozycji) → karta, na którą ma trafiać po decyzji człowieka (połączenie,
 * łączenie rozmiarów, rozdzielenie). Synchronizacja B2B i import pliku mają najpierw sprawdzać mapę (kolejne kroki
 * planu) — mapa ma pierwszeństwo przed powiązaniem.
 *
 * source_key („b2b:{id}”, „file:{id}”) jest NOT NULL i to on jest w UNIQUE (jak product_identifiers). Usunięta karta
 * docelowa → product_id null, wiersz zostaje jako „decyzja bez karty”; target_snapshot to ślad karty z chwili decyzji.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 40);
            // B2B: remote_id powiązania; plik: position_key identyfikatora (kod wiersza)
            $table->string('position_key', 64);
            $table->foreignId('b2b_account_id')->nullable()->constrained('b2b_accounts')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('reason', 20);
            // pozycja wiodąca karty modelu (etap C3) — na razie zawsze false
            $table->boolean('is_anchor')->default(false);
            $table->string('position_label', 120)->nullable();
            $table->string('remote_sku')->nullable();
            $table->json('target_snapshot')->nullable();
            $table->foreignId('card_match_candidate_id')->nullable()->constrained('card_match_candidates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_key', 'position_key'], 'card_redirects_source_unique');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_redirects');
    }
};
