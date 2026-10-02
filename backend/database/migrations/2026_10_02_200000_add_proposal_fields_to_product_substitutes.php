<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zamienniki proponowane automatem (substitutes:propose): skąd wiersz pochodzi, dowody porównania z cytatami z kart
 * i kiedy je policzono, plus notatka do decyzji człowieka. Wiersze ręczne zostają 'reczny' bez dowodów.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_substitutes', function (Blueprint $table): void {
            $table->string('source', 16)->default('reczny')->after('approved_by');
            $table->json('evidence')->nullable()->after('source');
            $table->timestamp('generated_at')->nullable()->after('evidence');
            // uzasadnienie decyzji człowieka (zwłaszcza odrzucenia propozycji automatu — materiał do poprawy reguł)
            $table->text('decision_note')->nullable()->after('generated_at');
            $table->index(['source', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('product_substitutes', function (Blueprint $table): void {
            $table->dropIndex(['source', 'approval_status']);
            $table->dropColumn(['source', 'evidence', 'generated_at', 'decision_note']);
        });
    }
};
