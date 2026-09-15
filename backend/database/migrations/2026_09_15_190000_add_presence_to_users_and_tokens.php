<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Obecność w systemie: ostatnie logowanie i ostatni sygnał aktywności (lista w Administracji).
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->index('last_seen_at');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Sesja = token Sanctum: skąd się zalogowano i na jakiej podstronie była ostatnio.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('presence_path', 255)->nullable();
            $table->timestamp('presence_at')->nullable();

            $table->index('presence_at');
        });

        $this->backfillUsers();
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['presence_at']);
            $table->dropColumn(['ip_address', 'user_agent', 'presence_path', 'presence_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_login_at', 'last_seen_at']);
        });
    }

    /**
     * Uzupełnia nowe kolumny z istniejących danych. Dziennik ma retencję 120 dni,
     * więc starsze logowania zostają puste (null) — nie zgadujemy.
     */
    private function backfillUsers(): void
    {
        $lastLogins = DB::table('activity_logs')
            ->where('action', 'login')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->select('user_id', DB::raw('MAX(created_at) as value'))
            ->pluck('value', 'user_id');

        $lastTokenUse = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereNotNull('last_used_at')
            ->groupBy('tokenable_id')
            ->select('tokenable_id', DB::raw('MAX(last_used_at) as value'))
            ->pluck('value', 'tokenable_id');

        $userIds = $lastLogins->keys()->merge($lastTokenUse->keys())->map(fn ($id): int => (int) $id)->unique();

        foreach ($userIds as $userId) {
            $login = $lastLogins->get($userId);
            $tokenUse = $lastTokenUse->get($userId);

            $loginAt = $login !== null ? Carbon::parse((string) $login) : null;
            $tokenAt = $tokenUse !== null ? Carbon::parse((string) $tokenUse) : null;
            $seenAt = $loginAt === null ? $tokenAt : ($tokenAt === null ? $loginAt : $loginAt->max($tokenAt));

            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'last_login_at' => $loginAt?->format('Y-m-d H:i:s'),
                    'last_seen_at' => $seenAt?->format('Y-m-d H:i:s'),
                ]);
        }
    }
};
