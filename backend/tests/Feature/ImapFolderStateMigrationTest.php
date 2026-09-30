<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pozycja odczytu INBOX przechodzi do imap_folders i wraca przy cofnięciu migracji. */
final class ImapFolderStateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_position_moves_to_folder_state_and_back(): void
    {
        $migration = require database_path('migrations/2026_10_01_160000_imap_state_per_folder.php');
        $migration->down();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $base = ['from_name' => 'Jan', 'from_address' => 'jan@supon.pl', 'host' => 'smtp.supon.pl', 'port' => 587, 'username' => 'jan', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()];
        DB::table('user_mail_accounts')->insert([...$base, 'user_id' => $user->id, 'imap_uidvalidity' => 1546890440, 'imap_last_uid' => 195716]);
        DB::table('user_mail_accounts')->insert([...$base, 'user_id' => $other->id]);

        $migration->up();
        $this->assertSame(['INBOX' => ['v' => 1546890440, 'u' => 195716]], json_decode((string) DB::table('user_mail_accounts')->where('user_id', $user->id)->value('imap_folders'), true));
        $this->assertNull(DB::table('user_mail_accounts')->where('user_id', $other->id)->value('imap_folders'));

        $migration->down();
        $row = DB::table('user_mail_accounts')->where('user_id', $user->id)->first();
        $this->assertSame([1546890440, 195716], [(int) $row->imap_uidvalidity, (int) $row->imap_last_uid]);
        $migration->up();
    }
}
