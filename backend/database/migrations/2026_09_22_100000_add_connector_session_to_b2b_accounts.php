<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesja łącznika zapisana po logowaniu kodem z e-maila (22.09.2026, 3M order.3m.com — B2bCodeLoginSite). Ciasteczka
 * witryny, szyfrowane jak hasło; null = brak sesji (przebieg poprosi o zalogowanie kodem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->longText('connector_session')->nullable()->after('password');
            $table->timestamp('connector_session_saved_at')->nullable()->after('connector_session');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropColumn(['connector_session', 'connector_session_saved_at']);
        });
    }
};
