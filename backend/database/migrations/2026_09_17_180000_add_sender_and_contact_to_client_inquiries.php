<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kto i kiedy napisał zapytanie oraz dane kontaktowe wyjęte ze stopki maila.
 *
 * Stopka i tak jest odcinana przed analizą — zamiast ją wyrzucać, zapisujemy
 * z niej kontakt, razem z surowym blokiem, żeby każdą wartość dało się
 * sprawdzić w źródle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->string('source_from_name', 200)->nullable()->after('source_message_id');
            $table->string('source_from_email', 320)->nullable()->after('source_from_name');
            $table->timestamp('source_sent_at')->nullable()->after('source_from_email');
            $table->json('contact')->nullable()->after('source_sent_at');

            $table->index('source_from_email');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropIndex(['source_from_email']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['source_from_name', 'source_from_email', 'source_sent_at', 'contact']);
        });
    }
};
