<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Grupuje warianty, które różnią się tylko rozmiarem (np. AlphaTec 37695VP Size 7.0 / 10.0).
 */
final class ProductSizeVariant
{
    /** Kod 3-cyfrowy na końcu SKU (Ansell VP100 = 10.0). */
    private const DIGIT_CODE_SIZES = [
        '050' => '5',
        '055' => '5.5',
        '060' => '6',
        '065' => '6.5',
        '070' => '7',
        '075' => '7.5',
        '080' => '8',
        '085' => '8.5',
        '090' => '9',
        '095' => '9.5',
        '100' => '10',
        '105' => '10.5',
        '110' => '11',
        '115' => '11.5',
        '120' => '12',
        '130' => '13',
    ];

    /**
     * Lista rozmiarów z pola opakowania (np. „7, 8, 9, 10”) albo jeden rozmiar z nazwy/SKU.
     *
     * @return list<string>
     */
    public function parseSizeList(?string $packaging, ?string $name = null, ?string $sku = null): array
    {
        $found = [];
        $raw = trim((string) $packaging);
        if ($raw !== '' && preg_match('/[,;]/', $raw) === 1) {
            foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $norm = $this->normalizeSizeToken($part);
                if ($norm === null && preg_match('/^\d{1,2}(?:[.,]\d)?\s*-\s*\d{1,2}(?:[.,]\d)?$/', $part) === 1) {
                    $norm = str_replace(',', '.', preg_replace('/\s+/', '', $part) ?? $part);
                }
                if ($norm !== null && ! in_array($norm, $found, true)) {
                    $found[] = $norm;
                }
            }
        }
        if ($found !== []) {
            return $found;
        }
        $one = $this->extractSize($name, $sku, $packaging);
        if ($one === null) {
            return [];
        }

        return [$one];
    }

    public function extractSize(?string $name, ?string $sku = null, ?string $packaging = null): ?string
    {
        $fromPack = $this->normalizeSizeToken((string) $packaging);
        if ($fromPack !== null) {
            return $fromPack;
        }
        $fromName = $this->sizeFromName((string) $name);
        if ($fromName !== null) {
            return $fromName;
        }

        return $this->sizeFromSku((string) $sku);
    }

    public function stripSizeFromName(string $name): string
    {
        $t = trim($name);
        $t = preg_replace(
            '/[,\s]+(?:size|rozmiar|taille|rozm\.?)\s*:?\s*(?:\d{1,2}(?:[.,]\d)?|[2-6]\s*xl|xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l)\s*$/iu',
            '',
            $t
        ) ?? $t;
        $t = preg_replace('/\s+\d{1,2}[.,]\d\s*$/u', '', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    public function skuCore(?string $sku, ?string $name = null): ?string
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        $size = $this->extractSize($name, $sku);
        if ($size === null) {
            return null;
        }
        $code = $this->sizeToSkuSuffix($size);
        if ($code !== null && preg_match('/^(.+)'.$code.'$/i', $sku, $m) === 1 && $this->isUsableCore($m[1])) {
            return rtrim($m[1], "-/_ \t");
        }
        if (preg_match('/^\d+(?:\.\d)?$/', $size) === 1) {
            $two = (string) (int) $size;
            if (preg_match('/^(.+)'.$two.'$/i', $sku, $m) === 1 && $this->isUsableCore($m[1])) {
                return rtrim($m[1], "-/_ \t");
            }
        }

        return null;
    }

    /**
     * Null = nie jest wariantem rozmiaru, nie scalać.
     */
    public function groupKey(string $manufacturer, string $name, string $sku, ?string $packaging = null): ?string
    {
        $size = $this->extractSize($name, $sku, $packaging);
        if ($size === null) {
            return null;
        }
        $stripped = mb_strtolower($this->stripSizeFromName($name));
        $original = mb_strtolower(trim($name));
        $mfr = mb_strtolower(trim($manufacturer));
        if ($stripped !== '' && $stripped !== $original && mb_strlen($stripped) >= 6) {
            return 'name:'.$mfr.'|'.$stripped;
        }
        $core = $this->skuCore($sku, $name);
        if ($core !== null) {
            return 'sku:'.$mfr.'|'.mb_strtolower($core);
        }

        return null;
    }

    public function priceBucket(float|string|null $catalog, float|string|null $purchase): string
    {
        return number_format(round((float) $catalog, 2), 2, '.', '')
            .'|'.number_format(round((float) $purchase, 2), 2, '.', '');
    }

    private function sizeFromName(string $name): ?string
    {
        if (preg_match(
            '/(?:size|rozmiar|taille|rozm\.?)\s*:?\s*(\d{1,2}(?:[.,]\d)?|[2-6]\s*xl|xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l)\b/iu',
            $name,
            $m
        ) === 1) {
            return $this->normalizeSizeToken($m[1]);
        }

        return null;
    }

    private function sizeFromSku(string $sku): ?string
    {
        if (preg_match('/[A-Za-z](\d{3})$/', $sku, $m) === 1) {
            return $this->sizeForDigitCode($m[1]);
        }

        return null;
    }

    private function normalizeSizeToken(string $raw): ?string
    {
        $t = mb_strtolower(trim(str_replace(',', '.', $raw)));
        $t = preg_replace('/\s+/', '', $t) ?? $t;
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l|[2-6]xl|onesize)$/', $t) === 1) {
            return $t;
        }
        if (preg_match('/^\d{1,2}(?:\.\d)?$/', $t) === 1) {
            $n = (float) $t;
            if ($n >= 4 && $n <= 16) {
                return fmod($n, 1.0) === 0.0 ? (string) (int) $n : (string) $n;
            }
        }

        return null;
    }

    private function sizeForDigitCode(string $code): ?string
    {
        foreach (self::DIGIT_CODE_SIZES as $digits => $label) {
            if (sprintf('%03d', $digits) === $code || (string) $digits === $code) {
                return $label;
            }
        }

        return null;
    }

    private function sizeToSkuSuffix(string $size): ?string
    {
        foreach (self::DIGIT_CODE_SIZES as $digits => $label) {
            if ($label === $size) {
                return sprintf('%03d', $digits);
            }
        }

        return null;
    }

    private function isUsableCore(string $core): bool
    {
        $core = rtrim($core, "-/_ \t");

        return mb_strlen($core) >= 3;
    }
}
