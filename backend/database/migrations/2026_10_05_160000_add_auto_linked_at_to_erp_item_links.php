<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiedy automat połączył towar XL z kartą (status auto) — zostaje po potwierdzeniu przez człowieka, żeby było widać
 * „potwierdzone po automacie”. Dotychczasowe powiązania auto dostają datę założenia wiersza; przy potwierdzonych przed
 * tą zmianą nie wiadomo, czy były automatem, czy propozycją — zostają puste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_item_links', function (Blueprint $table): void {
            $table->timestamp('auto_linked_at')->nullable()->after('decided_at');
        });
        DB::table('erp_item_links')->where('status', 'auto')->update(['auto_linked_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('erp_item_links', function (Blueprint $table): void {
            $table->dropColumn('auto_linked_at');
        });
    }
};
