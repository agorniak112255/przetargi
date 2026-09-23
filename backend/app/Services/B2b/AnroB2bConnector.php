<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;

/**
 * b2b.anro.net.pl (platforma Zami). Lista produktów nie ma cen — cena konta, parametry
 * i zdjęcie to osobne zapytania na produkt.
 */
final class AnroB2bConnector implements B2bConnector, B2bShopFieldSource
{
    private const PAGE_SIZE = 100;

    private int $total = 0;

    public function __construct(private readonly AnroB2bClient $client) {}

    public static function key(): string
    {
        return 'anro';
    }

    public static function label(): string
    {
        return 'Anro';
    }

    public static function host(): string
    {
        return AnroB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new AnroB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $page = 0;
        $fetched = 0;
        do {
            $batch = $this->client->productsPage($page, self::PAGE_SIZE);
            $this->total = $batch['count'];
            $fetched += count($batch['list']);

            foreach ($batch['list'] as $item) {
                $id = (int) ($item['ID'] ?? 0);
                $name = trim((string) ($item['NAME'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($item['TOWARNAZWA'] ?? ''));
                }
                $category = trim((string) ($item['DZIAL_OPIS'] ?? ''));

                yield new B2bRemoteProduct(
                    remoteId: $id > 0 ? (string) $id : '',
                    sku: trim((string) ($item['KOD'] ?? '')),
                    name: $name,
                    category: $category !== '' ? $category : null,
                    sourceUrl: $id > 0 ? AnroB2bClient::PRODUCT_PAGE_URL.$id : null,
                    raw: $item,
                    identifiers: self::identifiers($item),
                );
            }
            $page++;
        } while ($batch['list'] !== [] && $fetched < $this->total);
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        // Lista Anro nie podaje producenta — dostawca jest źródłem karty.
        return self::label();
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $json = $this->client->price((int) $product->remoteId);
        $net = self::decimal($json['O_CENA_NETTO'] ?? null);
        if ($net === null || $net <= 0) {
            return null;
        }
        $base = self::decimal($json['O_CENA_BAZOWA'] ?? null);

        return new B2bRemotePrice(
            net: $net,
            base: $base !== null && $base > 0 ? $base : null,
            discountPercent: self::decimal($json['O_RABAT'] ?? null) ?? 0.0,
        );
    }

    /**
     * Sama proza z pola OPIS, dosłownie ze sklepu (HTML zamieniony na linie tekstu). Parametrów technicznych
     * tu nie ma — te same wiersze podaje shopFields() jako tabelkę „Informacje techniczne”, a powtórzenie ich
     * w opisie dublowałoby dane i psuło wyszukiwanie. Opis bywa przez to pusty: pusty opis niczego nie nadpisuje
     * (B2bCatalogSync::applyCardDetails), więc karta zostaje z tym, co już ma.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $html = (string) ($product->raw['OPIS'] ?? '');
        $text = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</\s*(p|div|h[1-6]|li|ul|ol|tr|table)\s*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        // źródło karty jest w linku produktu i powiązaniu z kontem B2B — nie w treści opisu
        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Tabelka „Informacje o produkcie” ze sklepu Anro, w układzie ze strony dostawcy: dane handlowe i
     * klasyfikacja z pozycji listy (B2bRemoteProduct::$raw), parametry techniczne osobnym zapytaniem.
     * To jedyne miejsce, w którym parametry techniczne się zapisują — opis ich nie powtarza.
     *
     * Cen tu nie ma: karta wyrobu ma własne sloty cen ze źródeł (product_source_prices), a przepisanie
     * ceny konta do tabelki dublowałoby ją w drugim miejscu, bez wiedzy, dla którego konta obowiązuje.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        // Pełna ścieżka działu bywa pusta — wtedy sklep pokazuje sam dział liścia.
        $department = self::text($raw['DZIAL_OPIS_PELNY'] ?? null);
        if ($department === '') {
            $department = self::text($raw['DZIAL_OPIS'] ?? null);
        }
        $name = self::text($raw['NAME'] ?? null);
        if ($name === '') {
            $name = self::text($raw['TOWARNAZWA'] ?? null);
        }
        $code = self::text($raw['KOD'] ?? null);
        if ($code === '') {
            $code = self::text($raw['TOWARKOD'] ?? null);
        }

        $fields = [];
        $commercial = [
            'Nazwa towaru' => $name,
            'Dział towarowy' => $department,
            'Kod towaru' => $code,
            // Kod producenta u wielu towarów jest pusty — pustego wiersza sklep nie pokazuje i my też nie.
            'Kod producenta' => self::text($raw['TOWARKODD'] ?? null),
            'Jednostka sprzedaży' => self::text($raw['JEDNOSTKA'] ?? null),
        ];
        foreach ($commercial as $label => $value) {
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField('Informacje handlowe', $label, $value);
            }
        }

        $productId = (int) $product->remoteId;
        if ($productId > 0) {
            foreach ($this->client->technicalData($productId) as $row) {
                $fields[] = new B2bRemoteShopField('Informacje techniczne', $row['name'], $row['value']);
            }
        }

        // Sklep pokazuje tę samą ścieżkę działu rozbitą na poziomy — nazwy wierszy są jego, nie nasze.
        $levels = ['Dział asortymentowy', 'Grupa asortymentowa', 'Podgrupa'];
        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode('\\', $department)),
            static fn (string $part): bool => $part !== '',
        ));
        foreach (array_slice($parts, 0, count($levels)) as $index => $part) {
            $fields[] = new B2bRemoteShopField('Klasyfikacja produktowa', $levels[$index], $part);
        }

        return $fields;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $candidates = array_values(array_filter(
            $this->client->attachments((int) $product->remoteId),
            static fn (array $a): bool => str_starts_with($a['content_type'], 'image/'),
        ));
        if ($candidates === []) {
            return null;
        }
        // typ 1 = „Grafika domyślna” w Zami
        usort($candidates, static fn (array $a, array $b): int => ($a['type'] === 1 ? 0 : 1) <=> ($b['type'] === 1 ? 0 : 1));
        $attachment = $candidates[0];
        $file = $this->client->attachmentBytes($attachment['id']);

        return new B2bRemoteImage(
            bytes: $file['bytes'],
            mime: $file['mime'] !== '' ? $file['mime'] : $attachment['content_type'],
            sourceUrl: AnroB2bClient::attachmentSourceUrl($attachment['id']),
        );
    }

    /**
     * Kody towaru z pozycji listy, dosłownie, na całą kartę (karta = jedna pozycja). Wszystkie wyroby Anro to jego
     * własne wyroby (decyzja użytkownika), więc kod towaru („KOD”, gdy pusty — „TOWARKOD”, jak w tabelce karty) jest
     * kodem producenta; „Kod producenta” (TOWARKODD, zwykle pusty) — także. ID to numer wewnętrzny platformy Zami —
     * nie jest identyfikatorem wyrobu. EAN-u lista nie podaje; parametry techniczne pobiera dopiero shopFields().
     *
     * @param  array<string, mixed>  $item
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $item): array
    {
        $out = [];
        $field = self::text($item['KOD'] ?? null) !== '' ? 'KOD' : 'TOWARKOD';
        $code = self::text($item[$field] ?? null);
        if ($code !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $code, field: $field);
        }
        $manufacturerCode = self::text($item['TOWARKODD'] ?? null);
        if ($manufacturerCode !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $manufacturerCode, field: 'TOWARKODD');
        }

        return $out;
    }

    private static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /** Wartość pola listy dosłownie ze źródła (bez zamiany znaczenia) — same białe znaki liczą się jak brak. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
