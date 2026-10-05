<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dostęp z sieci: konto albo grupa (rola) może pracować z każdej sieci albo tylko z sieci lokalnej.
 * - roles.network_access: any | local (domyślnie any — nic się nie zmienia po wdrożeniu)
 * - users.network_access: null = jak w grupie, any, local — ustawienie konta ma pierwszeństwo przed grupą
 * - local_networks: adresy sieci lokalnej (pojedynczy IPv4/IPv6 albo zakres CIDR). Aplikacja stoi na serwerze
 *   zewnętrznym, więc to publiczne adresy biur, które serwer widzi jako adres klienta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('network_access', 10)->default('any');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('network_access', 10)->nullable();
        });
        Schema::create('local_networks', function (Blueprint $table): void {
            $table->id();
            $table->string('address', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_networks');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('network_access');
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('network_access');
        });
    }
};
