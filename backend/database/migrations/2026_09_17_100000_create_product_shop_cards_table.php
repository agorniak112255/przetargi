<?php

declare(strict_types=1);

use App\Models\ProductShopCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karta wyrobu u dostawcy: wiersze nazwa→wartość pokazywane w sklepie B2B (np. zakładka „Informacje o produkcie”
 * w b2b.anro.net.pl — dział towarowy, kod, jednostka, parametry techniczne, klasyfikacja).
 *
 * Decyzja użytkownika 17.09.2026: te dane NIE idą do products.description. Karta bez prozy u dostawcy ma nadal
 * czekać na opis (Product::isDescriptionText, brama App\Services\B2b\B2bDescriptionSource), a dane ze sklepu mają
 * być widoczne osobno, z zachowaną proweniencją (konto B2B, adres karty, czas pobrania).
 *
 * Jeden wiersz na parę (karta, konto B2B), wiersze tabelki w kolumnie JSON — jak product_variants.attributes:
 * etykiety u dostawców powtarzają się w obrębie karty (Protekt podaje „Materiał” osobno dla każdego podzespołu,
 * UVEX ma wiele wierszy „Protection Level”), więc klucz unikalny po nazwie wiersza cicho gubiłby dane, a
 * porównanie utf8mb4_unicode_ci sklejałoby jeszcze „Kolor” z „kolor”. JSON zachowuje kolejność i powtórzenia
 * ze źródła, a zapis jest jedną atomową podmianą.
 *
 * b2b_account_id kasuje się kaskadą (inaczej niż slot ceny w product_source_prices, który po usunięciu konta
 * zostaje jako ostatnia znana cena): treść karty bez konta traci źródło i nie ma jak jej odświeżyć.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_shop_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            // adres karty u dostawcy w chwili pobrania — źródło wierszy, niezależne od products.shop_source_url
            $table->string('source_url', 2000)->nullable();
            /** @see ProductShopCard::$fields kształt: [{"section": "...", "rows": [{"name": "...", "value": "..."}]}] */
            $table->json('fields');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['product_id', 'b2b_account_id']);
            $table->index('b2b_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shop_cards');
    }
};
