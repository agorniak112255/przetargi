<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API platformy Zami, na której działa b2b.anro.net.pl. Nieoficjalne: ścieżki i pola
 * odczytane z ruchu strony www (14.09.2026). Ceny są cenami konta (z rabatem klienta).
 */
final class AnroB2bClient
{
    public const HOST = 'b2b.anro.net.pl';

    public const PRODUCT_PAGE_URL = 'https://b2b.anro.net.pl/products/';

    private const BASE = 'https://b2b.anro.net.pl/api-zami/';

    private const CLIENT_ID = 'www.zami';

    // Publiczny sekret aplikacji www — jest jawnie w skrypcie strony, to nie są dane konta.
    private const CLIENT_SECRET_PUBLIC = '4+J3rddJZ&f-2V5wkGXAZHmchS#eYYnRgQf=x$mHLUKQ@XrBEExdFB9hzsu?HrdWAKATT#!6z+*c6zwJ';

    private ?string $token = null;

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
    ) {}

    public function login(): void
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post(self::BASE.'api/token', [
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET_PUBLIC,
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $reason = $response->json('error_description');
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: '
                .(is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status())
            );
        }

        $this->token = $token;
    }

    /**
     * @return array{count: int, list: list<array<string, mixed>>}
     */
    public function productsPage(int $page, int $perPage): array
    {
        $json = $this->getJson('api/zit/products', ['page' => $page, 'onPage' => $perPage, 'priceId' => -1]);
        $list = is_array($json['list'] ?? null) ? array_values(array_filter($json['list'], 'is_array')) : [];

        return ['count' => (int) ($json['count'] ?? 0), 'list' => $list];
    }

    /**
     * @return array<string, mixed>
     */
    public function price(int $productId): array
    {
        $json = $this->getJson("api/zit/products/{$productId}/price", ['priceId' => -1, 'currencyId' => 0]);

        return is_array($json) ? $json : [];
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function technicalData(int $productId): array
    {
        $json = $this->getJson("api/zit/product/{$productId}/technical-data");
        $out = [];
        foreach (is_array($json) ? $json : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = rtrim(trim((string) ($row['NAZWA'] ?? '')), ':');
            $value = trim((string) ($row['WARTOSC'] ?? ''));
            if ($name !== '' && $value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Załączniki produktu bez treści (typ 1 = grafika domyślna).
     *
     * @return list<array{id: int, type: int, content_type: string, filename: string}>
     */
    public function attachments(int $productId): array
    {
        $json = $this->getJson("api/attachments/{$productId}/table_name/KOD_TOW", ['types' => '1,2', 'hideContent' => 'true']);
        $out = [];
        foreach (is_array($json) ? $json : [] as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'type' => (int) ($row['type'] ?? 0),
                'content_type' => strtolower(trim((string) ($row['contentType'] ?? ''))),
                'filename' => (string) ($row['filename'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function attachmentBytes(int $attachmentId): array
    {
        // Strona podaje token w query (tak ładuje <img>); nagłówek Authorization nie wystarcza.
        $response = $this->request(fn (): Response => Http::timeout(60)
            ->get(self::BASE.'thumb/att.php', ['id' => $attachmentId, 'token' => $this->token]));

        return [
            'bytes' => $response->body(),
            'mime' => (string) $response->header('Content-Type'),
        ];
    }

    /**
     * Adres zapisywany jako źródło zdjęcia — bez tokenu.
     */
    public static function attachmentSourceUrl(int $attachmentId): string
    {
        return self::BASE.'thumb/att.php?id='.$attachmentId;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function getJson(string $path, array $query = []): mixed
    {
        return $this->request(fn (): Response => Http::timeout(30)
            ->acceptJson()
            ->withToken((string) $this->token)
            ->get(self::BASE.$path, $query))->json();
    }

    /**
     * Token wygasa po ok. godzinie, a pełne pobranie trwa dłużej — na 401 logujemy się raz ponownie.
     *
     * @param  callable(): Response  $send
     */
    private function request(callable $send): Response
    {
        if ($this->token === null) {
            $this->login();
        }
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $response = $send();
        if ($response->status() === 401) {
            $this->login();
            $response = $send();
        }
        if (! $response->successful()) {
            throw new RuntimeException('API '.self::HOST.' odpowiedziało HTTP '.$response->status());
        }

        return $response;
    }
}
