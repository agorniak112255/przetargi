<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przetarg ↔ ogłoszenia z Biuletynu: ogłoszenie o zamówieniu (po numerze ogłoszenia przetargu) i ogłoszenie
 * o wyniku (po wspólnym identyfikatorze postępowania). Usunięcie ogłoszenia tylko odpina powiązanie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->foreignId('contract_notice_id')->nullable()->constrained('procurement_notices')->nullOnDelete();
            $table->foreignId('result_notice_id')->nullable()->constrained('procurement_notices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_notice_id');
            $table->dropConstrainedForeignId('result_notice_id');
        });
    }
};
