<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wzmianki „@” w komentarzu przetargu: identyfikatory osób wybranych z listy kandydatów.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_comments', function (Blueprint $table): void {
            $table->json('mentioned_user_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tender_comments', function (Blueprint $table): void {
            $table->dropColumn('mentioned_user_ids');
        });
    }
};
