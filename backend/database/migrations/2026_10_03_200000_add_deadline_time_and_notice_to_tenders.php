<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przetarg: godzina składania ofert (czas polski „na zegarze”, data zostaje w `deadline`), numer ogłoszenia
 * (Biuletyn Zamówień Publicznych albo TED — osobno od wewnętrznego `number`), wynik przetargu w całości
 * (przeliczany z części: TenderResultStatus) i chwila ostatniego sprawdzenia w Biuletynie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->time('deadline_time')->nullable()->after('deadline');
            $table->string('notice_number', 40)->nullable()->index();
            $table->string('result_status', 20)->nullable()->index();
            $table->timestamp('bzp_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropIndex(['notice_number']);
            $table->dropIndex(['result_status']);
        });
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn(['deadline_time', 'notice_number', 'result_status', 'bzp_checked_at']);
        });
    }
};
