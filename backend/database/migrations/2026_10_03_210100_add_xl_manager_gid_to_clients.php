<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opiekun klienta w ERP XL jako numer pracownika (KntOpiekun.KtO_PrcNumer → PrcKarty) — do przypisania klienta
 * handlowcowi (users.erp_employee_gid). Imię, nazwisko i e-mail opiekuna zostają w account_manager(_email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedInteger('xl_manager_gid')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['xl_manager_gid']);
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('xl_manager_gid');
        });
    }
};
