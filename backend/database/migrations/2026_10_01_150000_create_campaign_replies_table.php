<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odpowiedzi klientów na kampanie: campaigns:replies co 10 min czyta z IMAP skrzynki handlowca (EXAMINE — tylko
 * odczyt, same nagłówki, nic nie oznacza jako przeczytane) maile z kodem kampanii w temacie („Zapytanie K-0006 …”)
 * albo odpowiedzi w wątku maila kampanii (In-Reply-To / References = Message-ID wysłanego maila).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            // liczenie odpowiedzi — ta sama skrzynka i hasło co SMTP; host pusty = host SMTP
            $table->boolean('imap_enabled')->default(true)->after('copy_to_self');
            $table->string('imap_host', 255)->nullable()->after('imap_enabled');
            $table->unsignedSmallInteger('imap_port')->default(993)->after('imap_host');
            // odczyt przyrostowy: UIDVALIDITY skrzynki i ostatni przeczytany UID
            $table->unsignedBigInteger('imap_uidvalidity')->nullable()->after('imap_port');
            $table->unsignedBigInteger('imap_last_uid')->nullable()->after('imap_uidvalidity');
            $table->timestamp('imap_checked_at')->nullable()->after('imap_last_uid');
            $table->text('imap_error')->nullable()->after('imap_checked_at');
        });

        Schema::create('campaign_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
            // właściciel skrzynki, z której odczytano odpowiedź
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_email', 255);
            $table->string('from_name', 200)->nullable();
            $table->string('subject', 500);
            // kod towaru z tematu „Zapytanie K-0006 SPASAHV002”
            $table->string('item_code', 60)->nullable();
            // code = kod kampanii w temacie, thread = odpowiedź w wątku maila kampanii
            $table->string('matched_by', 10);
            $table->string('message_id', 255);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
            $table->index(['campaign_id', 'received_at']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->timestamp('replied_at')->nullable()->after('clicks');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn('replied_at');
        });
        Schema::dropIfExists('campaign_replies');
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            $table->dropColumn(['imap_enabled', 'imap_host', 'imap_port', 'imap_uidvalidity', 'imap_last_uid', 'imap_checked_at', 'imap_error']);
        });
    }
};
