<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planowanie wysyłki kampanii: status „scheduled” + godzina startu (UTC). campaigns:dispatch startuje kampanię
 * o tej godzinie; gdy start się nie uda, kampania wraca do projektu z powodem w schedule_error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('audience');
            $table->text('schedule_error')->nullable()->after('scheduled_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['scheduled_at', 'schedule_error']);
        });
    }
};
