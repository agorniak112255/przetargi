<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use RuntimeException;

final class TenderDocumentAiAnalyzer
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly JsonResponseParser $jsonParser,
    ) {}

    /**
     * @param  list<string>  $targets  items|conditions
     * @return array{items: list<array{requirement: string, quantity: int}>, conditions: list<array{category: ?string, content: string}>}
     */
    public function analyze(string $text, array $targets): array
    {
        $wantItems = in_array('items', $targets, true);
        $wantConditions = in_array('conditions', $targets, true);
        if (! $wantItems && ! $wantConditions) {
            return ['items' => [], 'conditions' => []];
        }

        $excerpt = mb_strlen($text) > 60000 ? mb_substr($text, 0, 60000)."\n\n[… ucięte …]" : $text;

        $schema = [
            'items' => $wantItems
                ? [[
                    'sku' => 'numer/kod artykułu lub null',
                    'name' => 'nazwa produktu',
                    'requirement' => 'pełny opis SIWZ (sku + nazwa)',
                    'quantity' => 1,
                    'offer_price' => 12.34,
                    'currency' => 'EUR|PLN|null',
                ]]
                : [],
            'conditions' => $wantConditions
                ? [['category' => 'termin|dostawa|gwarancja|certyfikat|it|inne', 'content' => 'treść warunku']]
                : [],
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś asystentem przetargowym BHP/PPE. Wyodrębniasz pozycje (osobno: numer/SKU, nazwa, cena, ilość) oraz warunki udziału/IT. Odpowiadasz wyłącznie poprawnym JSON.',
            ],
            [
                'role' => 'user',
                'content' => "Wyodrębnij z dokumentu:\n"
                    .($wantItems ? "- pozycje (items): sku, name, requirement, quantity, offer_price, currency\n" : '')
                    .($wantConditions ? "- warunki (conditions): category + content\n" : '')
                    ."Nie sklejaj całego wiersza w jedno pole — rozdziel numer, nazwę i cenę.\n"
                    ."Nie duplikuj. Pomijaj nagłówki, spisy treści, dane kontaktowe.\n"
                    .'Schemat: '.json_encode($schema, JSON_UNESCAPED_UNICODE)."\n\n---\n".$excerpt,
            ],
        ];

        $raw = $this->llm->chat($messages, null, true, null, AiTask::TenderDocument);
        $parsed = $this->jsonParser->parse($raw['content'] ?? '');
        if (! is_array($parsed)) {
            throw new RuntimeException('AI nie zwróciło poprawnego JSON z pozycjami/warunkami.');
        }

        return [
            'items' => $wantItems ? $this->normalizeItems($parsed['items'] ?? []) : [],
            'conditions' => $wantConditions ? $this->normalizeConditions($parsed['conditions'] ?? []) : [],
        ];
    }

    /**
     * @return array{items: list<array{requirement: string, quantity: int}>, conditions: list<array{category: ?string, content: string}>}
     */
    public function heuristic(string $text, array $targets): array
    {
        $wantItems = in_array('items', $targets, true);
        $wantConditions = in_array('conditions', $targets, true);
        $items = [];
        $conditions = [];
        $conditionKeys = ['termin', 'dostaw', 'gwaranc', 'certyfik', 'norma', 'iso', 'warunek', 'wymóg', 'wymagania', 'it ', 'system', 'edi', 'faktura', 'płatno'];

        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) < 8) {
                continue;
            }
            $lower = mb_strtolower($line);
            $isCondition = false;
            foreach ($conditionKeys as $key) {
                if (str_contains($lower, $key)) {
                    $isCondition = true;
                    break;
                }
            }

            if ($wantConditions && $isCondition) {
                $conditions[] = [
                    'category' => $this->guessCategory($lower),
                    'content' => $line,
                ];

                continue;
            }

            if ($wantItems && (preg_match('/^\d+[\.\)]\s+/u', $line) || preg_match('/\t|\s{2,}/u', $line))) {
                $qty = 1;
                if (preg_match('/(\d+)\s*(szt|kpl|par|op|opak)/iu', $line, $m)) {
                    $qty = max(1, (int) $m[1]);
                }
                $items[] = [
                    'requirement' => preg_replace('/^\d+[\.\)]\s+/u', '', $line) ?? $line,
                    'quantity' => $qty,
                ];
            }
        }

        if ($wantItems && $items === []) {
            foreach ($lines as $line) {
                $line = trim($line);
                if (mb_strlen($line) >= 15 && mb_strlen($line) <= 400) {
                    $items[] = ['requirement' => $line, 'quantity' => 1];
                }
                if (count($items) >= 80) {
                    break;
                }
            }
        }

        return [
            'items' => array_slice($items, 0, 200),
            'conditions' => array_slice($conditions, 0, 200),
        ];
    }

    /**
     * @return list<array{requirement: string, quantity: int}>
     */
    private function normalizeItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? $row['article'] ?? $row['kod'] ?? ''));
            $name = trim((string) ($row['name'] ?? $row['nazwa'] ?? ''));
            $req = trim((string) ($row['requirement'] ?? $row['opis'] ?? ''));
            if ($req === '') {
                $req = trim(implode(' · ', array_filter([$sku !== '' ? $sku : null, $name !== '' ? $name : null])));
            }
            if ($name === '' && $req !== '') {
                $name = $req;
            }
            if ($req === '' && $name === '' && $sku === '') {
                continue;
            }
            $qty = $row['quantity'] ?? $row['ilosc'] ?? 1;
            $price = $row['offer_price'] ?? $row['price'] ?? $row['cena'] ?? null;
            $out[] = [
                'sku' => $sku !== '' ? mb_substr($sku, 0, 128) : null,
                'name' => mb_substr($name !== '' ? $name : $req, 0, 5000),
                'requirement' => mb_substr($req !== '' ? $req : $name, 0, 5000),
                'quantity' => max(1, is_numeric($qty) ? (int) $qty : 1),
                'offer_price' => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => isset($row['currency']) ? (string) $row['currency'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{category: ?string, content: string}>
     */
    private function normalizeConditions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (is_string($row)) {
                $content = trim($row);
                if ($content !== '') {
                    $out[] = ['category' => null, 'content' => mb_substr($content, 0, 5000)];
                }

                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? $row['treść'] ?? $row['text'] ?? ''));
            if ($content === '') {
                continue;
            }
            $cat = isset($row['category']) ? trim((string) $row['category']) : null;
            $out[] = [
                'category' => $cat !== '' ? mb_substr($cat, 0, 64) : null,
                'content' => mb_substr($content, 0, 5000),
            ];
        }

        return $out;
    }

    private function guessCategory(string $lower): string
    {
        return match (true) {
            str_contains($lower, 'termin') || str_contains($lower, 'dostaw') => 'termin',
            str_contains($lower, 'gwaranc') => 'gwarancja',
            str_contains($lower, 'certyfik') || str_contains($lower, 'norma') || str_contains($lower, 'iso') => 'certyfikat',
            str_contains($lower, 'płat') || str_contains($lower, 'faktur') => 'platnosc',
            str_contains($lower, 'edi') || str_contains($lower, 'system') || str_contains($lower, 'it ') => 'it',
            default => 'inne',
        };
    }
}
