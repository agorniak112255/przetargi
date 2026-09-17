<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia ceny wskazuje konkretną aktualizację, nie sam cennik. Dopóki każdy import zakładał własny
 * wpis, wystarczał price_list_id; po zwinięciu Cenników do jednego wpisu na producenta wszystkie
 * przebiegi wskazywałyby ten sam wiersz i nie dałoby się powiedzieć, z której aktualizacji jest cena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->foreignId('price_list_import_id')
                ->nullable()
                ->after('price_list_id')
                ->constrained('price_list_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_list_import_id');
        });
    }
};
