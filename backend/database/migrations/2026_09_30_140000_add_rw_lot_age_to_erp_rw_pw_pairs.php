<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiek partii zdjętej przez RW (CDN.TraSElem → CDN.Dostawy): ile leżała od przyjęcia do dnia RW. Stara partia wydana RW
 * i przyjęta z powrotem PW to właściwy dowód „odmładzania”. Przy kilku partiach — najstarsza i średnia ważona ilością.
 * Cecha partii (zwykle rozmiar) RW i PW: 30.09.2026 w 599 z 653 par się zmieniała (38 → 39, XXL → L) — to zmiana
 * rozmiaru, nie odmłodzenie; kandydaci do wyjaśnienia to pary bez zmiany cechy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_rw_pw_pairs', function (Blueprint $table): void {
            // dzień przyjęcia najstarszej partii zdjętej przez RW i jej wiek w pełnych miesiącach w dniu RW
            $table->date('rw_lot_at')->nullable()->after('rw_approver');
            $table->unsignedSmallInteger('rw_lot_age_months')->nullable()->after('rw_lot_at');
            $table->decimal('rw_lot_avg_age_months', 6, 1)->nullable()->after('rw_lot_age_months');
            $table->unsignedSmallInteger('rw_lots')->default(0)->after('rw_lot_avg_age_months');
            // dokument, którym weszła najstarsza partia (PZ-15H/350/21/08); z PW = partia była już wcześniej „odnawiana”
            $table->string('rw_lot_source', 40)->nullable()->after('rw_lots');
            $table->boolean('rw_lot_from_pw')->default(false)->after('rw_lot_source');
            // cecha partii RW i PW („38”, „L×2, XL×1”); same_feature = te same cechy w tych samych ilościach
            $table->string('rw_features', 120)->nullable()->after('rw_lot_from_pw');
            $table->string('pw_features', 120)->nullable()->after('pw_approver');
            $table->boolean('same_feature')->default(true)->after('same_value');
            $table->index('rw_lot_age_months');
        });
    }

    public function down(): void
    {
        Schema::table('erp_rw_pw_pairs', function (Blueprint $table): void {
            $table->dropIndex(['rw_lot_age_months']);
            $table->dropColumn(['rw_lot_at', 'rw_lot_age_months', 'rw_lot_avg_age_months', 'rw_lots', 'rw_lot_source', 'rw_lot_from_pw', 'rw_features', 'pw_features', 'same_feature']);
        });
    }
};
