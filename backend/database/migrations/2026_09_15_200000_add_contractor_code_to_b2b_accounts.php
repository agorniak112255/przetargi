<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kod kontrahenta konta B2B (15.09.2026) — trzecie pole logowania wymagane przez niektóre witryny (np. UVEX,
 * izam.system-b2b.pl). Zwykły tekst, nie sekret; puste dla witryn logujących tylko nazwą użytkownika i hasłem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->string('contractor_code', 100)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropColumn('contractor_code');
        });
    }
};
