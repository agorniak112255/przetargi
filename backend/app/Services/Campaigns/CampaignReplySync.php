<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
use App\Models\UserMailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odpowiedzi klientów na kampanie ze skrzynki handlowca (IMAP, tylko nagłówki, EXAMINE — nic nie zmienia): mail
 * z kodem kampanii w temacie („Zapytanie K-0006 SPASAHV002” z przycisku „Zapytaj o ofertę”) albo odpowiedź w wątku
 * wysłanego maila (In-Reply-To / References = Message-ID). Wszystkie foldery poza Wysłanymi, Koszem, Spamem i Szkicami
 * (odpowiedź przeniesiona regułą albo ręcznie też się liczy), odczyt przyrostowy po UID osobno w każdym folderze.
 * Mail przeniesiony do folderu dostaje tam nowy UID — trafi do odczytu, a powtórkę odsieje Message-ID.
 */
class CampaignReplySync
{
    /** Odpowiedzi liczymy do tylu dni po starcie wysyłki. */
    public const WINDOW_DAYS = 60;

    /** Pierwszy odczyt skrzynki: najwyżej tyle najnowszych wiadomości od najwcześniejszej kampanii. */
    private const FIRST_RUN_LIMIT = 3000;

    private const FETCH_CHUNK = 100;

    /** Blokada odczytu jednej skrzynki — dłużej niż najdłuższy przebieg (pierwszy odczyt folderów). */
    private const LOCK_SECONDS = 300;

    /** Najwyżej tyle folderów na skrzynkę (INBOX zawsze pierwszy). */
    private const MAX_FOLDERS = 100;

    /** Oznaczenia folderów bez odpowiedzi klientów (RFC 6154) i folderów, których nie da się otworzyć. */
    private const SKIPPED_FLAGS = ['\sent', '\trash', '\junk', '\drafts', '\noselect', '\nonexistent'];

    /** To samo po nazwie (serwery bez SPECIAL-USE, np. Dovecot z Pleska: INBOX.Sent, INBOX.Trash, „Wysłane”). */
    private const SKIPPED_NAMES = '/^(sent|sent items|sent messages|sent mail|wysłane|elementy wysłane|trash|deleted|deleted items|deleted messages|kosz|elementy usunięte|junk|junk e-?mail|spam|drafts|szkice|kopie robocze|robocze|outbox|skrzynka nadawcza|templates|szablony)$/iu';

    public function __construct(private readonly SmtpHostGuard $hosts) {}

    /**
     * @return array{accounts: int, messages: int, replies: int, errors: int}
     */
    public function run(): array
    {
        $stats = ['accounts' => 0, 'messages' => 0, 'replies' => 0, 'errors' => 0];
        $since = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);
        $userIds = Campaign::query()->whereNotNull('sending_started_at')->where('sending_started_at', '>=', $since)
            ->distinct()->pluck('user_id')->all();
        foreach (UserMailAccount::query()->whereIn('user_id', $userIds)->where('imap_enabled', true)->get() as $account) {
            $stats['accounts']++;
            $result = $this->syncLocked($account, $since);
            $stats['messages'] += $result['messages'];
            $stats['replies'] += $result['replies'];
            $stats['errors'] += $result['error'] !== null ? 1 : 0;
        }

        return $stats;
    }

    /**
     * „Sprawdź skrzynkę teraz” w kampanii: odczyt jednej skrzynki od razu, bez czekania na przebieg co 10 minut.
     * busy = ta skrzynka jest właśnie czytana (przebieg harmonogramu albo drugie kliknięcie).
     *
     * @return array{busy: bool, messages: int, replies: int, error: string|null}
     */
    public function checkNow(UserMailAccount $account): array
    {
        return $this->syncLocked($account, CarbonImmutable::now()->subDays(self::WINDOW_DAYS));
    }

    /**
     * Odczyt skrzynki pod blokadą na konto — harmonogram i przycisk nie czytają jej naraz (ta sama pozycja odczytu).
     *
     * @return array{busy: bool, messages: int, replies: int, error: string|null}
     */
    private function syncLocked(UserMailAccount $account, CarbonImmutable $since): array
    {
        $lock = Cache::lock('campaign-replies:account:'.$account->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['busy' => true, 'messages' => 0, 'replies' => 0, 'error' => null];
        }
        try {
            [$messages, $replies] = $this->syncAccount($account, $since);

            return ['busy' => false, 'messages' => $messages, 'replies' => $replies, 'error' => null];
        } catch (Throwable $e) {
            $error = $this->errorText($e, $account);
            $account->forceFill(['imap_error' => $error, 'imap_checked_at' => now()])->save();

            return ['busy' => false, 'messages' => 0, 'replies' => 0, 'error' => $error];
        } finally {
            $lock->release();
        }
    }

    /**
     * Test ustawień IMAP (przycisk w „Moja poczta”): logowanie, lista folderów i otwarcie INBOX.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(UserMailAccount $account): array
    {
        $reader = $this->reader();
        try {
            $this->connect($reader, $account);
            $folders = $this->folders($reader);
            $box = $reader->examine('INBOX');
            // stan w „Moja poczta” od razu po teście, nie dopiero po kolejnym odczycie
            $account->forceFill(['imap_error' => null])->save();

            return ['ok' => true, 'message' => 'Odczyt odpowiedzi działa (folderów do sprawdzania: '.count($folders).', skrzynka odbiorcza: '.$box['exists'].' wiadomości).'];
        } catch (Throwable $e) {
            $error = $this->errorText($e, $account);
            $account->forceFill(['imap_error' => $error])->save();

            return ['ok' => false, 'message' => 'Odczyt odpowiedzi (IMAP) nie działa: '.$error];
        } finally {
            $reader->logout();
        }
    }

    /** @return array{0: int, 1: int} przeczytanych nagłówków, nowych odpowiedzi */
    private function syncAccount(UserMailAccount $account, CarbonImmutable $since): array
    {
        $campaigns = Campaign::query()->where('user_id', $account->user_id)->whereNotNull('sending_started_at')
            ->where('sending_started_at', '>=', $since)->get(['id', 'code', 'sending_started_at']);
        $byCode = $campaigns->keyBy(fn (Campaign $c): string => strtoupper((string) $c->code))->all();
        $byId = $campaigns->keyBy('id')->all();
        // Message-ID wysłanych maili → odbiorca (odpowiedź w wątku)
        $byMessageId = [];
        $recipientsByEmail = [];
        foreach (CampaignRecipient::query()->whereIn('campaign_id', $campaigns->pluck('id'))->where('status', CampaignRecipient::STATUS_SENT)
            ->get(['id', 'campaign_id', 'email', 'message_id']) as $r) {
            if ($r->message_id !== null && $r->message_id !== '') {
                $byMessageId[$this->normalizeId($r->message_id)] = $r;
            }
            $recipientsByEmail[(int) $r->campaign_id][mb_strtolower((string) $r->email)] = $r;
        }
        // kody towarów z wysłanych maili (migawka) — najdłuższe najpierw, żeby „ABC 12” nie przegrało z „ABC 1”
        $itemCodes = [];
        foreach (CampaignItem::query()->whereIn('campaign_id', $campaigns->pluck('id'))->whereNotNull('snap_code')->get(['campaign_id', 'snap_code']) as $item) {
            $code = trim((string) $item->snap_code);
            if ($code !== '') {
                $itemCodes[(int) $item->campaign_id][] = $code;
            }
        }
        foreach ($itemCodes as &$codes) {
            usort($codes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        }
        unset($codes);
        $map = ['byCode' => $byCode, 'byId' => $byId, 'byMessageId' => $byMessageId, 'recipientsByEmail' => $recipientsByEmail, 'itemCodes' => $itemCodes];

        $reader = $this->reader();
        try {
            $this->connect($reader, $account);
            $searchSince = $campaigns->min('sending_started_at')->copy()->subDay();
            $ownAddress = mb_strtolower((string) $account->from_address);
            $previous = is_array($account->imap_folders) ? $account->imap_folders : [];
            $state = [];
            $messages = $replies = 0;
            foreach ($this->folders($reader) as $folder) {
                try {
                    $box = $reader->examine($folder);
                } catch (ImapCommandException $e) {
                    // folderu nie da się otworzyć (np. usunięty w międzyczasie) — pozostałe czytamy dalej
                    if ($folder === 'INBOX') {
                        throw $e;
                    }
                    Log::info('campaigns:replies — pominięty folder', ['user_id' => $account->user_id, 'folder' => $folder]);
                    // pozycja zostaje — przy kolejnym odczycie folder nie jest czytany od nowa
                    if (isset($previous[$folder])) {
                        $state[$folder] = $previous[$folder];
                    }

                    continue;
                }
                $before = $previous[$folder] ?? null;
                $fresh = ! is_array($before) || (int) ($before['v'] ?? 0) !== $box['uidvalidity'] || ! isset($before['u']);
                $uids = $fresh
                    ? array_slice($reader->searchSince($searchSince), -self::FIRST_RUN_LIMIT)
                    : $reader->searchAfterUid((int) $before['u']);
                // pierwszy odczyt: wszystko poniżej UIDNEXT jest przeczytane albo starsze niż kampanie — następny odczyt
                // weźmie tylko nowe maile (bez tego folder bez świeżych maili byłby czytany w całości)
                $lastUid = $fresh ? max(0, $box['uidnext'] - 1) : (int) $before['u'];
                foreach (array_chunk($uids, self::FETCH_CHUNK) as $chunk) {
                    foreach ($reader->fetchHeaders($chunk) as $message) {
                        $messages++;
                        $replies += $this->record($account, $message, $map, $ownAddress) ? 1 : 0;
                    }
                    $lastUid = max($lastUid, ...$chunk);
                }
                $state[$folder] = ['v' => $box['uidvalidity'], 'u' => $lastUid];
            }
            // foldery usunięte ze skrzynki wypadają ze stanu
            $account->forceFill([
                'imap_folders' => $state,
                'imap_checked_at' => now(),
                'imap_error' => null,
            ])->save();

            return [$messages, $replies];
        } finally {
            $reader->logout();
        }
    }

    /**
     * @param  array{uid: int, headers: array<string, string>}  $message
     * @param  array{byCode: array<string, Campaign>, byId: array<int, Campaign>, byMessageId: array<string, CampaignRecipient>, recipientsByEmail: array<int, array<string, CampaignRecipient>>, itemCodes: array<int, list<string>>}  $map
     */
    private function record(UserMailAccount $account, array $message, array $map, string $ownAddress): bool
    {
        $h = $message['headers'];
        $subject = trim((string) ($h['subject'] ?? ''));
        [$fromEmail, $fromName] = $this->parseFrom((string) ($h['from'] ?? ''));
        if ($fromEmail === '' || $this->isAutomatic($h, $fromEmail, $subject)) {
            return false;
        }

        $campaign = null;
        $recipient = null;
        $matchedBy = null;
        // odpowiedź w wątku wysłanego maila
        $thread = trim(($h['in-reply-to'] ?? '').' '.($h['references'] ?? ''));
        if ($thread !== '' && preg_match_all('/<([^>]+)>/', $thread, $ids) > 0) {
            foreach (array_reverse($ids[1]) as $id) {
                $hit = $map['byMessageId'][$this->normalizeId($id)] ?? null;
                if ($hit !== null) {
                    $recipient = $hit;
                    $campaign = $map['byId'][(int) $hit->campaign_id] ?? null;
                    $matchedBy = CampaignReply::MATCHED_THREAD;
                    break;
                }
            }
        }
        // kod kampanii w temacie („Zapytanie K-0006 SPASAHV002” z przycisku „Zapytaj o ofertę”)
        $subjectCode = preg_match('/\b(K-\d{4,})\b\s*(.*)$/iu', $subject, $m) === 1 ? ['campaign' => strtoupper($m[1]), 'rest' => $m[2]] : null;
        if ($campaign === null && $subjectCode !== null) {
            $campaign = $map['byCode'][$subjectCode['campaign']] ?? null;
            $matchedBy = CampaignReply::MATCHED_CODE;
        }
        if ($campaign === null) {
            return false;
        }
        // kod towaru tylko, gdy jest jednym z kodów pozycji tej kampanii — nie zgadujemy z dowolnego słowa tematu
        $itemCode = null;
        if ($subjectCode !== null && $subjectCode['campaign'] === strtoupper((string) $campaign->code)) {
            $rest = mb_strtoupper(trim($subjectCode['rest']));
            foreach ($map['itemCodes'][(int) $campaign->id] ?? [] as $code) {
                $upper = mb_strtoupper($code);
                if ($rest === $upper || str_starts_with($rest, $upper.' ')) {
                    $itemCode = mb_substr($code, 0, 60);
                    break;
                }
            }
        }
        $recipient ??= $map['recipientsByEmail'][(int) $campaign->id][$fromEmail] ?? null;
        // własny mail handlowca (kopia, wysłane do siebie, własna odpowiedź w wątku z DW do siebie) — nie jest odpowiedzią
        // klienta, chyba że handlowiec sam był odbiorcą kampanii
        if ($fromEmail === $ownAddress && ($recipient === null || mb_strtolower((string) $recipient->email) !== $ownAddress)) {
            return false;
        }
        $receivedAt = $this->date((string) ($h['date'] ?? ''));
        if ($receivedAt->lt($campaign->sending_started_at)) {
            return false;
        }
        $messageId = trim((string) ($h['message-id'] ?? ''));
        // bez Message-ID: klucz z nadawcy, daty i tematu — ten sam mail w dwóch folderach liczy się raz
        $messageId = $messageId !== '' ? mb_substr($this->normalizeId($messageId), 0, 255) : 'hash-'.sha1($fromEmail.'|'.($h['date'] ?? '').'|'.$subject);

        $inserted = DB::table('campaign_replies')->insertOrIgnore([
            'campaign_id' => $campaign->id,
            'campaign_recipient_id' => $recipient?->id,
            'user_id' => $account->user_id,
            'from_email' => mb_substr($fromEmail, 0, 255),
            'from_name' => $fromName !== '' ? mb_substr($fromName, 0, 200) : null,
            'subject' => mb_substr($subject, 0, 500),
            'item_code' => $itemCode,
            'matched_by' => $matchedBy,
            'message_id' => $messageId,
            'received_at' => $receivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted > 0 && $recipient !== null) {
            DB::table('campaign_recipients')->where('id', $recipient->id)
                ->where(fn ($q) => $q->whereNull('replied_at')->orWhere('replied_at', '>', $receivedAt))
                ->update(['replied_at' => $receivedAt, 'updated_at' => now()]);
        }

        return $inserted > 0;
    }

    /**
     * Foldery do odczytu: INBOX pierwszy, bez Wysłanych, Kosza, Spamu, Szkiców i folderów, których nie da się otworzyć.
     *
     * @return list<string>
     */
    private function folders(ImapHeaderReader $reader): array
    {
        $out = ['INBOX'];
        foreach ($reader->listMailboxes() as $box) {
            if (strtoupper($box['name']) === 'INBOX' || array_intersect($box['flags'], self::SKIPPED_FLAGS) !== []) {
                continue;
            }
            // każdy człon nazwy — podfolder Kosza czy Wysłanych (INBOX.Trash.Stare) też odpada
            $segments = $box['delimiter'] !== '' ? explode($box['delimiter'], $box['name']) : [$box['name']];
            foreach ($segments as $segment) {
                $decoded = @mb_convert_encoding($segment, 'UTF-8', 'UTF7-IMAP');
                if (preg_match(self::SKIPPED_NAMES, trim(is_string($decoded) && $decoded !== '' ? $decoded : $segment)) === 1) {
                    continue 2;
                }
            }
            $out[] = $box['name'];
            if (count($out) >= self::MAX_FOLDERS) {
                break;
            }
        }

        return $out;
    }

    private function connect(ImapHeaderReader $reader, UserMailAccount $account): void
    {
        $host = trim((string) ($account->imap_host ?: $account->host));
        // ten sam strażnik co SMTP: tylko publiczny serwer, nie sieć wewnętrzna
        $this->hosts->assertAllowed($host);
        $reader->open($host, (int) ($account->imap_port ?: 993), (bool) $account->verify_peer);
        $reader->login((string) $account->username, (string) $account->password);
    }

    /** Czytnik z kontenera — testy podmieniają go nagraną skrzynką. */
    protected function reader(): ImapHeaderReader
    {
        return app(ImapHeaderReader::class);
    }

    /**
     * Autoodpowiedź („jestem na urlopie”) albo zwrotka serwera — nie jest odpowiedzią klienta (RFC 3834 i typowe
     * znaczniki Exchange/Gmail/Dovecot).
     *
     * @param  array<string, string>  $h
     */
    private function isAutomatic(array $h, string $fromEmail, string $subject): bool
    {
        $autoSubmitted = mb_strtolower(trim($h['auto-submitted'] ?? ''));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }
        if (trim($h['x-autoreply'] ?? '') !== '' || trim($h['x-autorespond'] ?? '') !== '') {
            return true;
        }
        if (preg_match('/^\s*(auto_reply|bulk|junk|list)\b/i', $h['precedence'] ?? '') === 1) {
            return true;
        }
        if (preg_match('/multipart\/report/i', $h['content-type'] ?? '') === 1) {
            return true;
        }
        if (preg_match('/^(mailer-daemon|postmaster)@/i', $fromEmail) === 1) {
            return true;
        }

        return preg_match('/^\s*(automatyczna odpowied|odpowied\S* automatyczn|automatic reply|auto:|autoreply|out of office|nieobecno)/iu', $subject) === 1;
    }

    /** @return array{0: string, 1: string} e-mail (małe litery), nazwa */
    private function parseFrom(string $from): array
    {
        if (preg_match('/^(.*)<([^>]+)>\s*$/', $from, $m) === 1) {
            return [mb_strtolower(trim($m[2])), trim(trim($m[1]), " \"'")];
        }

        return [mb_strtolower(trim($from)), ''];
    }

    private function normalizeId(string $id): string
    {
        return mb_strtolower(trim($id, " <>\t"));
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            return $value !== '' ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function errorText(Throwable $e, UserMailAccount $account): string
    {
        if ($e instanceof DecryptException) {
            return 'Nie da się odczytać zapisanego hasła skrzynki — wpisz je ponownie w „Moja poczta”.';
        }
        $message = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : class_basename($e);
        try {
            $password = (string) $account->password;
            if ($password !== '') {
                $message = str_replace($password, '***', $message);
            }
        } catch (Throwable) {
            // hasło nieczytelne — w komunikacie go nie ma
        }

        return mb_substr($message, 0, 500);
    }
}
