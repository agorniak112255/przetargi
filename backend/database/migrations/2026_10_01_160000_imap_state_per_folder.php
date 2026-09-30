<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odpowiedzi klientów ze wszystkich folderów skrzynki (poza Wysłanymi, Koszem, Spamem i Szkicami): pozycja odczytu
 * (UIDVALIDITY + ostatni UID) osobno dla każdego folderu — imap_folders = {"INBOX": {"v": 123, "u": 456}, …}.
 * Dotychczasowa pozycja dotyczyła INBOX i przechodzi do imap_folders.INBOX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            $table->json('imap_folders')->nullable()->after('imap_port');
        });
        foreach (DB::table('user_mail_accounts')->whereNotNull('imap_last_uid')->whereNotNull('imap_uidvalidity')->get(['id', 'imap_uidvalidity', 'imap_last_uid']) as $row) {
            DB::table('user_mail_accounts')->where('id', $row->id)->update([
                'imap_folders' => json_encode(['INBOX' => ['v' => (int) $row->imap_uidvalidity, 'u' => (int) $row->imap_last_uid]]),
            ]);
        }
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            $table->dropColumn(['imap_uidvalidity', 'imap_last_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('imap_uidvalidity')->nullable()->after('imap_port');
            $table->unsignedBigInteger('imap_last_uid')->nullable()->after('imap_uidvalidity');
        });
        foreach (DB::table('user_mail_accounts')->whereNotNull('imap_folders')->get(['id', 'imap_folders']) as $row) {
            $inbox = json_decode((string) $row->imap_folders, true)['INBOX'] ?? null;
            if (is_array($inbox)) {
                DB::table('user_mail_accounts')->where('id', $row->id)->update(['imap_uidvalidity' => $inbox['v'] ?? null, 'imap_last_uid' => $inbox['u'] ?? null]);
            }
        }
        Schema::table('user_mail_accounts', function (Blueprint $table): void {
            $table->dropColumn('imap_folders');
        });
    }
};
