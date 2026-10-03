<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - erp_employee_gid: pracownik ERP XL (PrcKarty) przypisany do konta przez administratora — opiekun klienta w XL
 *   staje się handlowcem w aplikacji (cele handlowców). To nie operator XL (erp_operator_ident).
 * - calendar_*: osobisty adres kalendarza terminów (ICS). W bazie tylko skrót SHA-256 klucza, nigdy sam klucz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('erp_employee_gid')->nullable()->unique();
            $table->char('calendar_token_hash', 64)->nullable()->unique();
            // mine | all
            $table->string('calendar_scope', 8)->nullable();
            $table->timestamp('calendar_created_at')->nullable();
            $table->timestamp('calendar_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['erp_employee_gid']);
            $table->dropUnique(['calendar_token_hash']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['erp_employee_gid', 'calendar_token_hash', 'calendar_scope', 'calendar_created_at', 'calendar_used_at']);
        });
    }
};
