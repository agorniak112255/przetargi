<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wersja listu w HTML: tabela „pozycja z zapytania — nasza propozycja”.
 *
 * Powstaje z tych samych danych co wersja tekstowa. Ręczna poprawka treści
 * kasuje ją (kolumna wraca do null), żeby nigdy nie wysłać tabeli niezgodnej
 * z tym, co pracownik widzi i zatwierdza w polu tekstowym.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->longText('reply_html')->nullable()->after('reply_body');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn('reply_html');
        });
    }
};
