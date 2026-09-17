<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skąd pochodzi zdjęcie karty — dokładnie tak, jak przy plikach (product_documents.b2b_account_id).
 *
 * Bez tego pola z wiersza product_images nie da się odczytać, czy zdjęcie przyszło z witryny dostawcy,
 * czy zostało wyłowione z internetu przez model. A to rozstrzyga dwie rzeczy: które zdjęcie jest
 * główne (packshot producenta bije zdjęcie znalezione przy cudzej karcie) i czego nie wolno skasować
 * przy ponownym wzbogacaniu, które czyści dane z sieci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->foreignId('b2b_account_id')
                ->nullable()
                ->after('product_id')
                ->constrained('b2b_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2b_account_id');
        });
    }
};
