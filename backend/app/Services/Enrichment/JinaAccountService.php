<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\JinaUsageSnapshot;
use App\Services\Ai\AiSettingsService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Saldo tokenów klucza Jina i szacunek, kiedy się skończy.
 *
 * Jina nie ma udokumentowanego endpointu salda — panel jina.ai czyta je z
 * embeddings-dashboard-api (ten sam, którego używają skrypty społeczności).
 * Odpowiedź parsujemy tolerancyjnie: szukamy pola z saldem po kilku nazwach.
 */
final class JinaAccountService
{
    public const BALANCE_ENDPOINT = 'https://embeddings-dashboard-api.jina.ai/api/v1/api_key/user';

    /** Jedno wyszukiwanie s.jina.ai kosztuje stałe 10 000 tokenów (jina.ai/reader, sekcja billing). */
    public const TOKENS_PER_SEARCH = 10_000;

    /** Doładowanie: ok. 50 USD za 1 mld tokenów. */
    public const USD_PER_MILLION_TOKENS = 0.05;

    /** Panel odświeżany co chwilę nie ma bić w API Jina — próbka najwyżej co 10 min bez wymuszenia. */
    private const SNAPSHOT_MIN_INTERVAL_MINUTES = 10;

    /** Tempo liczymy z ostatniego tygodnia — starsze próbki opisują inny batch. */
    private const RATE_WINDOW_DAYS = 7;

    /** Poniżej godziny między pierwszą a ostatnią próbką tempo byłoby zgadywaniem. */
    private const MIN_RATE_WINDOW_HOURS = 1.0;

    public function __construct(private readonly AiSettingsService $settings) {}

    /**
     * Saldo tokenów z API Jina.
     *
     * @throws RuntimeException gdy brak klucza, klucz nieważny albo odpowiedź bez salda
     */
    public function fetchBalance(): int
    {
        $key = $this->settings->jinaApiKey();
        if ($key === null) {
            throw new RuntimeException('Brak klucza Jina — wpisz go w Ustawieniach AI albo w JINA_API_KEY.');
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(8)
                ->acceptJson()
                ->get(self::BALANCE_ENDPOINT, ['api_key' => $key]);
        } catch (Throwable $e) {
            throw new RuntimeException('Jina: nie udało się pobrać salda — '.$e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Jina: klucz nieważny (HTTP '.$response->status().'). Sprawdź klucz w Ustawieniach AI.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Jina: saldo niedostępne (HTTP '.$response->status().'). Spróbuj później.');
        }

        $tokens = $this->tokensFromPayload($response->json());
        if ($tokens === null) {
            throw new RuntimeException('Jina: odpowiedź bez pola z saldem tokenów — sprawdź saldo w panelu jina.ai.');
        }

        return $tokens;
    }

    /**
     * Pole z saldem po kilku znanych nazwach — panel Jina zmieniał już strukturę odpowiedzi.
     */
    public function tokensFromPayload(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }
        foreach ([
            'wallet.total_balance',
            'wallet.balance',
            'total_balance',
            'balance',
            'tokens_left',
            'remaining_tokens',
            'data.wallet.total_balance',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) {
                return max(0, (int) round((float) $value));
            }
        }

        return null;
    }

    /**
     * Zapisuje próbkę salda. Bez wymuszenia nie częściej niż co 10 min; bez klucza nic nie robi.
     *
     * @throws RuntimeException jak fetchBalance()
     */
    public function snapshot(bool $force = false): ?JinaUsageSnapshot
    {
        if ($this->settings->jinaApiKey() === null) {
            return null;
        }
        $latest = JinaUsageSnapshot::query()->latest('taken_at')->first();
        if (! $force && $latest !== null && $latest->taken_at !== null
            && $latest->taken_at->gt(now()->subMinutes(self::SNAPSHOT_MIN_INTERVAL_MINUTES))) {
            return null;
        }

        return JinaUsageSnapshot::query()->create([
            'tokens_left' => $this->fetchBalance(),
            'taken_at' => now(),
        ]);
    }

    /**
     * Stan salda i szacunek wyczerpania na podstawie zapisanych próbek.
     *
     * Zużycie = suma spadków salda między kolejnymi próbkami z ostatniego tygodnia
     * (wzrost to doładowanie i nie liczy się jako ujemne zużycie), podzielona przez
     * czas między pierwszą a ostatnią próbką.
     *
     * @return array{
     *     configured: bool,
     *     key_source: ?string,
     *     tokens_left: ?int,
     *     usd_left: ?float,
     *     searches_left: ?int,
     *     checked_at: ?string,
     *     tokens_per_day: ?int,
     *     days_left: ?float,
     *     runs_out_at: ?string,
     *     window_hours: ?float,
     *     snapshots: int,
     *     tokens_per_search: int,
     *     usd_per_million_tokens: float
     * }
     */
    public function usage(): array
    {
        $key = $this->settings->jinaApiKey();
        $latest = JinaUsageSnapshot::query()->latest('taken_at')->latest('id')->first();
        $window = JinaUsageSnapshot::query()
            ->where('taken_at', '>=', now()->subDays(self::RATE_WINDOW_DAYS))
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get();

        $used = 0;
        $previous = null;
        foreach ($window as $row) {
            if ($previous !== null && $row->tokens_left < $previous) {
                $used += $previous - $row->tokens_left;
            }
            $previous = (int) $row->tokens_left;
        }
        $windowHours = null;
        if ($window->count() >= 2) {
            $first = $window->first();
            $last = $window->last();
            $windowHours = $first->taken_at !== null && $last->taken_at !== null
                ? round(abs($last->taken_at->diffInSeconds($first->taken_at)) / 3600, 2)
                : null;
        }
        $tokensPerDay = $windowHours !== null && $windowHours >= self::MIN_RATE_WINDOW_HOURS
            ? (int) round($used / $windowHours * 24)
            : null;

        $tokensLeft = $latest !== null ? (int) $latest->tokens_left : null;
        $daysLeft = $tokensLeft !== null && $tokensPerDay !== null && $tokensPerDay > 0
            ? round($tokensLeft / $tokensPerDay, 1)
            : null;

        return [
            'configured' => $key !== null,
            'key_source' => $key !== null ? $this->settings->jinaKeySource() : null,
            'tokens_left' => $tokensLeft,
            'usd_left' => $tokensLeft !== null ? round($tokensLeft / 1_000_000 * self::USD_PER_MILLION_TOKENS, 2) : null,
            'searches_left' => $tokensLeft !== null ? intdiv($tokensLeft, self::TOKENS_PER_SEARCH) : null,
            'checked_at' => $latest?->taken_at?->toIso8601String(),
            'tokens_per_day' => $tokensPerDay,
            'days_left' => $daysLeft,
            'runs_out_at' => $daysLeft !== null ? now()->addSeconds((int) round($daysLeft * 86400))->toIso8601String() : null,
            'window_hours' => $windowHours,
            'snapshots' => $window->count(),
            'tokens_per_search' => self::TOKENS_PER_SEARCH,
            'usd_per_million_tokens' => self::USD_PER_MILLION_TOKENS,
        ];
    }
}
