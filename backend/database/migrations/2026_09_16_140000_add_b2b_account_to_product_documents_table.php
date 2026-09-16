<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konto B2B, z którego panelu pochodzi plik (null = plik znaleziony w internecie przez uzupełnianie AI).
     * Pliki dostawcy są dowodem pochodzenia danych karty, więc uzupełnianie AI ich nie kasuje.
     */
    public function up(): void
    {
        Schema::table('product_documents', function (Blueprint $table): void {
            $table->foreignId('b2b_account_id')->nullable()->after('product_id')
                ->constrained('b2b_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2b_account_id');
        });
    }
};
