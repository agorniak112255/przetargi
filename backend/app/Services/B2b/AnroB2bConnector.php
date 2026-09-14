<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;

/**
 * b2b.anro.net.pl (platforma Zami). Lista produktów nie ma cen — cena konta, parametry
 * i zdjęcie to osobne zapytania na produkt.
 */
final class AnroB2bConnector implements B2bConnector
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
        $out = implode("\n", $lines);

        $technical = $this->client->technicalData((int) $product->remoteId);
        if ($technical !== []) {
            $params = array_map(static fn (array $p): string => '- '.$p['name'].': '.$p['value'], $technical);
            $out .= ($out !== '' ? "\n\n" : '').'Parametry ('.AnroB2bClient::HOST."):\n".implode("\n", $params);
        }

        return mb_substr($out, 0, 10000);
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

    private static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
