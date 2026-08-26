<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Klasy pochłaniaczy EN 14387 — A2B2E2K2 ≠ A2B2E2K2NO.
 */
final class PpeFilterType
{
    /** @var list<string> dłuższe tokeny pierwsze */
    private const TOKENS = [
        'ax2', 'ax1', 'a2', 'a1', 'b2', 'b1', 'e2', 'e1', 'k2', 'k1',
        'hg', 'co', 'no', 'sx', 'p3', 'p2', 'p1',
    ];

    private const SPECIALS = ['no', 'hg', 'co', 'sx'];

    public function compact(string $text): string
    {
        $t = mb_strtolower($text);
        $t = strtr($t, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return preg_replace('/[\s\-\/_.,;:]+/u', '', $t) ?? $t;
    }

    /**
     * @return list<string> np. a2b2e2k2no, a2b2e2k2hgconop3
     */
    public function compactCodes(string $text): array
    {
        $out = [];
        foreach ($this->codeMatches($text) as $raw) {
            $code = $this->compact($raw);
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function required(string $requirement): array
    {
        $found = [];
        foreach ($this->compactCodes($requirement) as $code) {
            foreach ($this->parseTokens($code) as $tok) {
                $found[] = $tok;
            }
        }
        foreach ($this->impliedSpecials($requirement) as $sp) {
            $found[] = $sp;
        }

        return array_values(array_unique($found));
    }

    public function covers(string $requirement, string $candidate): bool
    {
        $need = $this->required($requirement);
        if ($need === []) {
            return true;
        }

        $have = $this->candidateTokens($candidate);
        foreach ($need as $tok) {
            if (! isset($have[$tok])) {
                return false;
            }
        }

        return true;
    }

    public function coverageScore(string $requirement, string $candidate): int
    {
        $need = $this->required($requirement);
        if ($need === []) {
            return 0;
        }

        $have = $this->candidateTokens($candidate);
        $score = 0;
        foreach ($need as $tok) {
            if (isset($have[$tok])) {
                $score += in_array($tok, self::SPECIALS, true) ? 40 : 10;
            }
        }
        foreach (array_keys($have) as $tok) {
            if (! in_array($tok, $need, true) && in_array($tok, [...self::SPECIALS, 'p3'], true)) {
                $score += 8;
            }
        }

        return $score;
    }

    /**
     * Wzorce LIKE (wewnętrzne %/_ już wyescapowane): a2b2e2k2no, a2-b2-e2-k2-no, a2%b2%e2%k2%no.
     *
     * @return list<string>
     */
    public function sqlLikes(string $requirement): array
    {
        $out = [];
        foreach ($this->compactCodes($requirement) as $code) {
            $parts = $this->parseTokens($code);
            $out[] = $this->likeLiteral($code);
            $out[] = $this->likeLiteral(strtolower($this->hyphenated($code)));
            if (count($parts) >= 2) {
                $out[] = implode('%', array_map($this->likeLiteral(...), $parts));
            }
        }
        $need = $this->required($requirement);
        if ($this->compactCodes($requirement) === [] && count($need) >= 2) {
            $out[] = implode('%', array_map($this->likeLiteral(...), $need));
        }
        if (in_array('no', $need, true)) {
            $out[] = 'tlenk%azot';
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function likeLiteral(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * A2-B2-E2-K2-NO — tak zapisują karty producentów (Oxyline).
     */
    public function hyphenated(string $code): string
    {
        $parts = $this->parseTokens($this->compact($code));
        if ($parts === []) {
            return $code;
        }

        return implode('-', array_map(strtoupper(...), $parts));
    }

    /**
     * @return list<string>
     */
    private function codeMatches(string $text): array
    {
        $t = mb_strtolower($text);
        $pattern = '/(?:(?:ax|a|b|e|k)\s*\d[\s\-\/]*){2,}(?:(?:hg|co|no|sx|p\s*\d)[\s\-\/]*)*/u';
        if (preg_match_all($pattern, $t, $m) === false) {
            return [];
        }

        return array_values(array_filter(
            $m[0],
            static fn (string $s): bool => trim($s, " \t\n\r\0\x0B-") !== ''
        ));
    }

    /**
     * @return array<string, true>
     */
    private function candidateTokens(string $candidate): array
    {
        $have = [];
        foreach ($this->compactCodes($candidate) as $code) {
            foreach ($this->parseTokens($code) as $tok) {
                $have[$tok] = true;
            }
        }
        foreach ($this->impliedSpecials($candidate) as $sp) {
            $have[$sp] = true;
        }

        return $have;
    }

    /**
     * @return list<string>
     */
    private function parseTokens(string $compact): array
    {
        $out = [];
        $i = 0;
        $len = strlen($compact);
        while ($i < $len) {
            $hit = false;
            foreach (self::TOKENS as $tok) {
                $n = strlen($tok);
                if ($i + $n <= $len && substr($compact, $i, $n) === $tok) {
                    $out[] = $tok;
                    $i += $n;
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                $i++;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function impliedSpecials(string $text): array
    {
        $t = mb_strtolower($text);
        $t = strtr($t, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
        $out = [];
        if (preg_match('/tlenk\w*\s+azot/u', $t) === 1) {
            $out[] = 'no';
        }
        if (preg_match('/\brtec|\bmercury\b/u', $t) === 1) {
            $out[] = 'hg';
        }
        if (preg_match('/tlenek\s+wegla|carbon\s+monoxide/u', $t) === 1) {
            $out[] = 'co';
        }

        return $out;
    }
}
