<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Serwer poczty skrzynki użytkownika musi być publiczną nazwą w internecie — inaczej aplikacja łączyłaby się na
 * polecenie użytkownika z siecią wewnętrzną (SSRF): localhost, adres IP, nazwa wskazująca na adres prywatny,
 * link-local albo zarezerwowany. Wyjątki (np. własny serwer w sieci firmy) tylko z config('campaigns.smtp_allowed_hosts').
 * Sprawdzane przy zapisie skrzynki i ponownie przed każdym połączeniem (DNS mógł się zmienić).
 */
class SmtpHostGuard
{
    public const NOT_PUBLIC = 'Serwer poczty musi być publicznym adresem w internecie (np. smtp.firma.pl) — nie localhost, adres IP ani adres sieci wewnętrznej.';

    public const NOT_FOUND = 'Nie znaleziono serwera poczty o tej nazwie — sprawdź adres.';

    /** Zakresy niedozwolone poza tym, co odrzuca filter_var (starsze PHP nie znają części z nich). */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> nazwa → adresy IP (A i AAAA) */
    private readonly Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  podmieniany w testach (bez prawdziwego DNS) */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveDns(...);
    }

    /**
     * Adresy IP serwera do przypięcia połączenia (CURLOPT_RESOLVE) — połączenie idzie dokładnie pod sprawdzony adres,
     * bez drugiego zapytania DNS (podmiana adresu między sprawdzeniem a połączeniem). null = serwer niedozwolony.
     *
     * @return list<string>|null
     */
    public function publicIps(string $host): ?array
    {
        if ($this->problem($host) !== null) {
            return null;
        }
        $ips = ($this->resolver)(rtrim(mb_strtolower(trim($host)), '.'));
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return array_values($ips);
    }

    /** Komunikat dla użytkownika albo null, gdy serwer dozwolony. */
    public function problem(string $host): ?string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');
        if ($host === '') {
            return self::NOT_FOUND;
        }
        $allowed = array_map(static fn ($h): string => rtrim(mb_strtolower(trim((string) $h)), '.'), (array) config('campaigns.smtp_allowed_hosts', []));
        if (in_array($host, $allowed, true)) {
            return null;
        }
        $labels = explode('.', $host);
        // adres IP w każdej postaci (także 2130706433 czy 127.1 — ostatni człon nazwy nigdy nie jest samą liczbą)
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ctype_digit((string) end($labels))) {
            return self::NOT_PUBLIC;
        }

        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return self::NOT_FOUND;
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return self::NOT_PUBLIC;
            }
        }

        return null;
    }

    /** Przed połączeniem: TransportException (wysyłka traktuje go jak błąd skrzynki nadawcy → pauza). */
    public function assertAllowed(string $host): void
    {
        $problem = $this->problem($host);
        if ($problem !== null) {
            throw new TransportException($problem);
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // IPv6 z osadzonym IPv4 (::ffff:127.0.0.1, 64:ff9b::7f00:1) — sprawdzamy adres IPv4 w środku
        if (strlen($bin) === 16 && (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff") || str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8)))) {
            $v4 = inet_ntop(substr($bin, 12));

            return $v4 !== false && self::isPublicIp($v4);
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /**
     * Adresy A (resolver systemu — także plik hosts) i AAAA (DNS).
     *
     * @return list<string>
     */
    private static function resolveDns(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
