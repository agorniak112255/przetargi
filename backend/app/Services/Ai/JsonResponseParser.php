<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class JsonResponseParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $content): array
    {
        $candidates = $this->candidates($content);
        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $fixed = $this->soften($candidate);
            $decoded = json_decode($fixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $recovered = $this->recoverTruncated($content);
        if ($recovered !== null) {
            return $recovered;
        }

        $snippet = mb_substr(trim($content), 0, 280);
        throw new RuntimeException(
            'API AI nie zwróciło poprawnego JSON. Fragment odpowiedzi: '.$snippet
        );
    }

    /**
     * @return list<string>
     */
    private function candidates(string $content): array
    {
        $raw = trim($content);
        $out = [$raw];

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m) === 1) {
            $out[] = trim($m[1]);
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $out[] = substr($raw, $start, $end - $start + 1);
        }

        // wyważony obiekt JSON (gdy model dorzuci tekst po JSON)
        $balanced = $this->extractBalancedObject($raw);
        if ($balanced !== null) {
            $out[] = $balanced;
        }

        $closed = $this->closeTruncatedJson($raw);
        if ($closed !== null) {
            $out[] = $closed;
        }

        return array_values(array_unique($out));
    }

    private function extractBalancedObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($raw);

        for ($i = $start; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Domknięcie uciętego JSON (typowy przypadek: max_tokens / urwanie w środku products[]).
     */
    private function closeTruncatedJson(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }
        $body = substr($raw, $start);

        // obetnij do ostatniego kompletnego obiektu produktu: } po którym jest , lub koniec
        $lastComplete = null;
        $depthObj = 0;
        $depthArr = 0;
        $inString = false;
        $escape = false;
        $len = strlen($body);
        $productsStarted = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;

                continue;
            }
            if ($ch === '[') {
                $depthArr++;
                if (! $productsStarted && str_contains(substr($body, max(0, $i - 20), 20), 'products')) {
                    $productsStarted = true;
                }
            } elseif ($ch === ']') {
                $depthArr = max(0, $depthArr - 1);
            } elseif ($ch === '{') {
                $depthObj++;
            } elseif ($ch === '}') {
                $depthObj = max(0, $depthObj - 1);
                if ($productsStarted && $depthObj === 1 && $depthArr >= 1) {
                    // zamknięcie elementu w products przy root jeszcze otwartym
                    $lastComplete = $i;
                } elseif ($depthObj === 0) {
                    $lastComplete = $i;
                }
            }
        }

        if ($lastComplete === null) {
            // spróbuj ostatniego '} ' w ogóle (koniec obiektu produktu)
            $pos = strrpos($body, '}');
            if ($pos === false) {
                return null;
            }
            $lastComplete = $pos;
        }

        $cut = rtrim(substr($body, 0, $lastComplete + 1), " \t\n\r,");
        // domknij tablice/obiekty
        $openArr = substr_count($cut, '[') - substr_count($cut, ']');
        $openObj = substr_count($cut, '{') - substr_count($cut, '}');
        // prostsze liczenie bez stringów — soften i tak naprawi trailing comma
        for ($i = 0; $i < $openArr; $i++) {
            $cut .= ']';
        }
        for ($i = 0; $i < $openObj; $i++) {
            $cut .= '}';
        }

        return $this->soften($cut);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recoverTruncated(string $content): ?array
    {
        $closed = $this->closeTruncatedJson($content);
        if ($closed !== null) {
            $decoded = json_decode($closed, true);
            if (is_array($decoded) && isset($decoded['products']) && is_array($decoded['products']) && $decoded['products'] !== []) {
                $decoded['_partial'] = true;
                $decoded['notes'] = trim((string) ($decoded['notes'] ?? '').' (odczyt częściowy — odpowiedź AI była ucięta)');

                return $decoded;
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // awaryjnie: zbierz kompletne obiekty produktów regexem
        if (preg_match_all('/\{\s*"sku"\s*:\s*"[^"]+"\s*,\s*"name"\s*:\s*"[^"]*"\s*,\s*"catalog_price"\s*:\s*[0-9.]+[^}]{0,400}\}/u', $content, $m) !== false
            && ($m[0] ?? []) !== []) {
            $products = [];
            foreach ($m[0] as $chunk) {
                $p = json_decode($this->soften($chunk), true);
                if (is_array($p) && isset($p['sku'], $p['catalog_price'])) {
                    $products[] = $p;
                }
            }
            if ($products !== []) {
                $manuf = null;
                if (preg_match('/"manufacturer_detected"\s*:\s*"([^"]+)"/', $content, $mm) === 1) {
                    $manuf = $mm[1];
                }
                $currency = null;
                if (preg_match('/"currency"\s*:\s*"([^"]+)"/', $content, $mc) === 1) {
                    $currency = $mc[1];
                }

                return [
                    'manufacturer_detected' => $manuf,
                    'currency' => $currency,
                    'notes' => 'Odczyt częściowy z uciętej odpowiedzi AI ('.count($products).' pozycji).',
                    'products' => $products,
                    '_partial' => true,
                ];
            }
        }

        return null;
    }

    private function soften(string $json): string
    {
        $s = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $json);
        // trailing commas
        $s = preg_replace('/,\s*([}\]])/', '$1', $s) ?? $s;

        return $s;
    }
}
