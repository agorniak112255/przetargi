<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moduł „Przeglądy” (06.10.2026, plan SUPON_AI_Plan_Przegladow_2026-10-06.md): terminy przeglądów u klientów z ERP XL.
 *
 * - erp_services — usługi XL (Twr_Typ 4, np. „PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6”); osobno od erp_items, bo erp_items
 *   zasila łączenie z kartami, Zapasy i wyszukiwarki, a usługi nie mają stanu ani karty.
 * - inspection_positions — pozycja XL (towar albo usługa) z interwałem wybranym przez człowieka (1–24 mies.): sprzedaż
 *   tej pozycji klientowi = początek odliczania do następnego przeglądu. Towar może mieć usługę, która go „odnawia”
 *   (gaśnica GP-6X ↔ przegląd GP-6).
 * - inspection_sale_lines — dosłowna kopia pozycji faktur sprzedaży (i ich korekt) z usługami i towarami pozycji;
 *   bez kluczy obcych (usługi nie są w erp_items, klient łączony po numerze XL).
 * - inspection_due — wyliczone terminy: klient × pozycja (przebudowa nocą i po zmianie pozycji).
 * - inspection_dismissals — „pomiń klienta” (na zawsze albo do daty), także dla jednej pozycji.
 * - inspection_suggestion_rejections — odrzucone podpowiedzi z wzorca (decyzja trwała).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('xl_gid')->unique();
            // Twr_Typ z XL (4 = usługa)
            $table->unsignedTinyInteger('xl_type');
            $table->string('code', 100);
            $table->string('name', 500);
            $table->string('unit', 20)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamp('synced_at')->nullable();
            // zniknęła z XL przy pełnym odczycie katalogu
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index('code');
        });

        Schema::create('inspection_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('xl_gid')->unique();
            // 1 = towar (erp_items), 4 = usługa (erp_services)
            $table->unsignedTinyInteger('xl_type');
            // kod i nazwa z XL — odświeżane nocą z katalogu
            $table->string('code', 100);
            $table->string('name', 500);
            $table->string('unit', 20)->nullable();
            // wybór człowieka z listy InspectionPosition::INTERVALS (1, 3, 6, 9, 12, 15, 18, 24)
            $table->unsignedTinyInteger('interval_months');
            // usługa XL, której sprzedaż po zakupie tego towaru oznacza wykonany przegląd (null = brak)
            $table->unsignedInteger('renewed_by_xl_gid')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('active')->default(true);
            // manual | suggestion (przyjęta podpowiedź z wzorca)
            $table->string('source', 16)->default('manual');
            $table->foreignId('pattern_position_id')->nullable()->constrained('inspection_positions')->nullOnDelete();
            // towar: pełna historia sprzedaży od początku okna doczytana (null = do doczytania najbliższej nocy);
            // usługi kopiujemy wszystkie, więc dla nich bez znaczenia
            $table->timestamp('history_loaded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('renewed_by_xl_gid');
        });

        Schema::create('inspection_suggestion_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pattern_position_id')->constrained('inspection_positions')->cascadeOnDelete();
            $table->unsignedInteger('xl_gid');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // nazwy indeksów jawnie — domyślne przekraczają 64 znaki (limit MySQL/MariaDB)
            $table->unique(['pattern_position_id', 'xl_gid'], 'insp_sugg_rej_pattern_xl_unique');
        });

        Schema::create('inspection_sale_lines', function (Blueprint $table): void {
            $table->id();
            // TrN_GIDTyp (2033 FS, 2037 FSE, 2041 korekta FS), TrN_GIDNumer, TrE_GIDLp
            $table->unsignedSmallInteger('document_type');
            $table->unsignedInteger('document_id');
            $table->unsignedInteger('line');
            $table->string('document_number', 40);
            // TrN_Data2 (wystawienie) i TrN_Data3 (sprzedaż; null = XL nie podał)
            $table->date('issued_on');
            $table->date('sold_on')->nullable();
            // nabywca (TrN_KntNumer) i odbiorca (TrN_KnDNumer, null = ten sam)
            $table->unsignedInteger('customer_xl_gid');
            $table->unsignedInteger('recipient_xl_gid')->nullable();
            $table->unsignedInteger('xl_item_gid');
            $table->unsignedTinyInteger('xl_item_type');
            // korekty ze znakiem
            $table->decimal('quantity', 14, 3);
            $table->decimal('net_value', 14, 2);
            $table->string('warehouse_code', 20)->nullable();
            // oddział z kodu magazynu (WarehouseLocations::of)
            $table->string('location', 4)->nullable();
            // operator wystawiający (Ope_Ident, wielkie litery)
            $table->string('operator_ident', 20)->nullable();
            $table->unsignedSmallInteger('corrects_document_type')->nullable();
            $table->unsignedInteger('corrects_document_id')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['document_type', 'document_id', 'line']);
            $table->index(['customer_xl_gid', 'xl_item_gid', 'issued_on'], 'insp_sale_lines_customer_item_issued_index');
            $table->index(['xl_item_gid', 'issued_on']);
            $table->index(['corrects_document_type', 'corrects_document_id'], 'insp_sale_lines_corrects_index');
        });

        Schema::create('inspection_due', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('customer_xl_gid');
            $table->foreignId('inspection_position_id')->constrained('inspection_positions')->cascadeOnDelete();
            // najbliższy termin z nieodnowionych sprzedaży (wizyt / zakupów)
            $table->date('due_on');
            // ile nieodnowionych wizyt / zakupów i ile sztuk razem
            $table->unsignedSmallInteger('open_count');
            $table->decimal('open_quantity', 14, 3);
            // ostatnia wizyta / zakup tej pozycji u klienta: data, ilość, wartość netto, dokumenty
            // [{number, issued_on, quantity}] (najwyżej 10)
            $table->date('last_on');
            $table->decimal('last_quantity', 14, 3);
            $table->decimal('last_net', 14, 2)->nullable();
            $table->json('last_documents');
            $table->date('first_on');
            $table->unsignedInteger('recipient_xl_gid')->nullable();
            $table->string('location', 4)->nullable();
            $table->string('operator_ident', 20)->nullable();
            // inna karta XL z tym samym NIP-em ma późniejszą sprzedaż tej pozycji (pewnie przegląd zrobiony, tylko na innej karcie)
            $table->boolean('same_nip_newer')->default(false);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['customer_xl_gid', 'inspection_position_id']);
            $table->index('due_on');
            $table->index('inspection_position_id');
        });

        Schema::create('inspection_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('customer_xl_gid');
            // null = wszystkie przeglądy klienta
            $table->foreignId('inspection_position_id')->nullable()->constrained('inspection_positions')->cascadeOnDelete();
            // null = na zawsze
            $table->date('until_on')->nullable();
            // InspectionDismissal::REASONS
            $table->string('reason', 30);
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_xl_gid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_dismissals');
        Schema::dropIfExists('inspection_due');
        Schema::dropIfExists('inspection_sale_lines');
        Schema::dropIfExists('inspection_suggestion_rejections');
        Schema::dropIfExists('inspection_positions');
        Schema::dropIfExists('erp_services');
    }
};
