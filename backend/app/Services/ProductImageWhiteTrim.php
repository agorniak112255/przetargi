<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;

final class ProductImageWhiteTrim
{
    private const MAX_SIDE = 160;

    private const BG_CHANNEL_SUM = 54;

    public function toJpeg(string $bytes, int $maxSide = self::MAX_SIDE): ?string
    {
        if (! function_exists('imagecreatefromstring') || $bytes === '') {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if (! $src instanceof GdImage) {
            return null;
        }

        try {
            $box = $this->contentBox($src);
            if ($box === null) {
                return $this->encodeResized($src, $maxSide);
            }

            return $this->encodeCrop($src, $box, $maxSide);
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private function contentBox(GdImage $src): ?array
    {
        $bg = $this->sampleBackground($src);
        if (! $bg['light'] && ! $bg['transparent']) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($this->isBackground($src, $x, $y, $bg)) {
                    continue;
                }
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return null;
        }

        $boxW = $maxX - $minX + 1;
        $boxH = $maxY - $minY + 1;
        if ($boxW * $boxH < ($width * $height * 0.004)) {
            return null;
        }

        $pad = max(2, (int) round(max($boxW, $boxH) * 0.06));
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($width - 1, $maxX + $pad);
        $maxY = min($height - 1, $maxY + $pad);

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => $maxX - $minX + 1,
            'h' => $maxY - $minY + 1,
        ];
    }

    /**
     * @return array{r: int, g: int, b: int, light: bool, transparent: bool}
     */
    private function sampleBackground(GdImage $src): array
    {
        $width = imagesx($src);
        $height = imagesy($src);
        $points = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int) intdiv($width, 2), 0],
            [(int) intdiv($width, 2), $height - 1],
        ];

        $sumR = 0;
        $sumG = 0;
        $sumB = 0;
        $opaque = 0;
        $transparent = 0;

        foreach ($points as [$x, $y]) {
            [$r, $g, $b, $a] = $this->rgba($src, $x, $y);
            if ($a > 100) {
                $transparent++;
                continue;
            }
            $sumR += $r;
            $sumG += $g;
            $sumB += $b;
            $opaque++;
        }

        if ($opaque === 0) {
            return [
                'r' => 255,
                'g' => 255,
                'b' => 255,
                'light' => true,
                'transparent' => true,
            ];
        }

        $r = (int) round($sumR / $opaque);
        $g = (int) round($sumG / $opaque);
        $b = (int) round($sumB / $opaque);

        return [
            'r' => $r,
            'g' => $g,
            'b' => $b,
            'light' => (($r + $g + $b) / 3) >= 220,
            'transparent' => $transparent >= 3,
        ];
    }

    /**
     * @param  array{r: int, g: int, b: int, light: bool, transparent: bool}  $bg
     */
    private function isBackground(GdImage $src, int $x, int $y, array $bg): bool
    {
        [$r, $g, $b, $a] = $this->rgba($src, $x, $y);
        if ($a > 100) {
            return true;
        }
        if ($bg['transparent'] && $this->nearWhite($r, $g, $b)) {
            return true;
        }

        return (abs($r - $bg['r']) + abs($g - $bg['g']) + abs($b - $bg['b'])) <= self::BG_CHANNEL_SUM;
    }

    private function nearWhite(int $r, int $g, int $b): bool
    {
        return min($r, $g, $b) >= 242 && (max($r, $g, $b) - min($r, $g, $b)) <= 12;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function rgba(GdImage $src, int $x, int $y): array
    {
        $rgba = imagecolorat($src, $x, $y);
        $a = ($rgba & 0x7F000000) >> 24;
        if (! imageistruecolor($src)) {
            $colors = imagecolorsforindex($src, $rgba);

            return [
                (int) $colors['red'],
                (int) $colors['green'],
                (int) $colors['blue'],
                (int) ($colors['alpha'] ?? 0),
            ];
        }

        return [
            ($rgba >> 16) & 0xFF,
            ($rgba >> 8) & 0xFF,
            $rgba & 0xFF,
            $a,
        ];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $box
     */
    private function encodeCrop(GdImage $src, array $box, int $maxSide): ?string
    {
        $scale = $maxSide / max($box['w'], $box['h']);
        $dstW = max(1, (int) round($box['w'] * $scale));
        $dstH = max(1, (int) round($box['h'] * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            return null;
        }
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            $box['x'],
            $box['y'],
            $dstW,
            $dstH,
            $box['w'],
            $box['h'],
        );

        return $this->encodeJpeg($dst);
    }

    private function encodeResized(GdImage $src, int $maxSide): ?string
    {
        $width = imagesx($src);
        $height = imagesy($src);
        $scale = min(1.0, $maxSide / max($width, $height));
        $dstW = max(1, (int) round($width * $scale));
        $dstH = max(1, (int) round($height * $scale));
        if ($dstW === $width && $dstH === $height) {
            $copy = imagecreatetruecolor($width, $height);
            if ($copy === false) {
                return null;
            }
            imagecopy($copy, $src, 0, 0, 0, 0, $width, $height);

            return $this->encodeJpeg($copy);
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            return null;
        }
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $width, $height);

        return $this->encodeJpeg($dst);
    }

    private function encodeJpeg(GdImage $im): ?string
    {
        ob_start();
        $ok = imagejpeg($im, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        if ($ok === false || $bytes === '') {
            return null;
        }

        return $bytes;
    }
}
