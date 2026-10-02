<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista kontrolna warunków przetargu: czy firma spełnia warunek (spelniamy / nie_spelniamy; null = do sprawdzenia),
 * kto i kiedy to zaznaczył.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_conditions', function (Blueprint $table) {
            $table->string('status', 16)->nullable()->after('source');
            $table->foreignId('status_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('status_at')->nullable()->after('status_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tender_conditions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_user_id');
            $table->dropColumn(['status', 'status_at']);
        });
    }
};
