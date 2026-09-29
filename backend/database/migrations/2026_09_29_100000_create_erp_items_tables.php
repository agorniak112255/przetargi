<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopia towarów Comarch ERP XL (erp:sync) i ich powiązania z kartami (erp:match).
 *
 * erp_items — towar XL dosłownie (kod, nazwy, jednostka), stany z rozbiciem na magazyny, dostawcy z karty towaru.
 * Dane XL stoją obok kart: nie nadpisują products.purchase_price ani product_source_prices.
 * erp_item_purchases — ostatnie pozycje PZ z numerem dokumentu (ślad pochodzenia ceny).
 * erp_item_links — towar XL ↔ karta: czym połączono (method, matched_value dosłownie z XL) i dowody (evidence).
 * product_id nullOnDelete: potwierdzone ręcznie powiązanie nie może zniknąć razem z usuniętą kartą bez śladu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_items', function (Blueprint $table): void {
            $table->id();
            // Twr_GIDNumer
            $table->unsignedInteger('xl_gid')->unique();
            $table->string('code', 100);
            $table->string('name', 500);
            $table->string('name1', 500)->nullable();
            $table->string('ean', 64)->nullable();
            $table->string('unit', 20)->nullable();
            $table->boolean('archived')->default(false);
            // suma magazynów HANDEL (config erpxl.trade_warehouse_prefix) i wszystkich magazynów
            $table->decimal('stock_trade', 14, 4)->default(0);
            $table->decimal('stock_total', 14, 4)->default(0);
            // [{code, name, quantity}] — magazyny z niezerowym stanem
            $table->json('stock_by_warehouse')->nullable();
            // [{supplier_id, supplier, price, currency, updated_at}] z CDN.TwrDost
            $table->json('suppliers')->nullable();
            $table->date('last_purchase_at')->nullable();
            $table->date('last_sale_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            // towar zniknął z XL (nie przyszedł w pełnym przebiegu)
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index('code');
        });

        Schema::create('erp_item_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            // TrE_GIDTyp / TrE_GIDNumer / TrE_GIDLp — pozycja dokumentu w XL
            $table->unsignedInteger('document_type');
            $table->unsignedInteger('document_id');
            $table->unsignedInteger('document_line');
            // TrN_Stan dosłownie (znaczenie do potwierdzenia w XL)
            $table->integer('document_state')->nullable();
            $table->date('purchased_at')->nullable();
            $table->unsignedInteger('supplier_xl_id')->nullable();
            $table->string('supplier', 100)->nullable();
            // ilość w jednostce podstawowej towaru; wartość księgowa netto w PLN
            $table->decimal('quantity', 14, 4);
            $table->string('document_unit', 20)->nullable();
            $table->decimal('net_value_pln', 14, 2);
            // wartość / ilość — cena jednostki podstawowej w PLN
            $table->decimal('unit_price_pln', 14, 4)->nullable();
            // TrE_Cena w walucie dokumentu, dosłownie
            $table->decimal('document_price', 14, 4)->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamps();

            $table->index(['erp_item_id', 'purchased_at']);
        });

        Schema::create('erp_item_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // auto | suggested | confirmed | rejected
            $table->string('status', 16);
            // skąd kod: name | name1 | xl_code | manual
            $table->string('method', 16);
            // kod dosłownie z XL i jego postać porównania (ProductIdentifierCode::code)
            $table->string('matched_value', 150)->nullable();
            $table->string('matched_code', 100)->nullable();
            // dowody: dostawca = producent, marka w nazwie, wspólne słowa, liczba kandydatów, wersja reguł
            $table->json('evidence')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_item_id', 'product_id']);
            $table->index(['product_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_links');
        Schema::dropIfExists('erp_item_purchases');
        Schema::dropIfExists('erp_items');
    }
};
