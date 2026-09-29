<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analiza zapytania w tle (AnalyzeClientInquiryJob). Stare wiersze mają analysis_status = null — to zapytania
 * policzone jeszcze w żądaniu, czyli gotowe. analysis_run_id wiąże zapis wyniku, postępu i błędu z jednym
 * przebiegiem: spóźniony albo podwójnie dostarczony przebieg nie nadpisze nowszego.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_inquiries') || Schema::hasColumn('client_inquiries', 'analysis_status')) {
            return;
        }

        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->string('analysis_status', 16)->nullable()->after('analysis');
            $table->char('analysis_run_id', 26)->nullable()->after('analysis_status');
            $table->json('analysis_progress')->nullable()->after('analysis_run_id');
            $table->string('analysis_error', 500)->nullable()->after('analysis_progress');
            $table->timestamp('analysis_started_at')->nullable()->after('analysis_error');
            $table->timestamp('analysis_finished_at')->nullable()->after('analysis_started_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_inquiries') || ! Schema::hasColumn('client_inquiries', 'analysis_status')) {
            return;
        }

        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'analysis_status',
                'analysis_run_id',
                'analysis_progress',
                'analysis_error',
                'analysis_started_at',
                'analysis_finished_at',
            ]);
        });
    }
};
