<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pary RW → PW: imię i nazwisko operatora (CDN.OpeKarty.Ope_Nazwisko, dosłownie) i uwagi dokumentu (CDN.TrNOpisy,
 * np. „ZAMIANA ROZMIARÓW”; w PW często numer RW), dokument obcy PW. Uprawnienia SELECT nadane 30.09.2026.
 */
return new class extends Migration
{
    private const DOCS = ['rw', 'pw'];

    public function up(): void
    {
        Schema::table('erp_rw_pw_pairs', function (Blueprint $table): void {
            foreach (self::DOCS as $doc) {
                $table->string($doc.'_operator_name', 100)->nullable();
                $table->string($doc.'_approver_name', 100)->nullable();
                $table->string($doc.'_note', 255)->nullable();
                $table->string($doc.'_foreign_number', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_rw_pw_pairs', function (Blueprint $table): void {
            foreach (self::DOCS as $doc) {
                $table->dropColumn([$doc.'_operator_name', $doc.'_approver_name', $doc.'_note', $doc.'_foreign_number']);
            }
        });
    }
};
