<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductImageWhiteTrim;
use PHPUnit\Framework\TestCase;

final class ProductImageWhiteTrimTest extends TestCase
{
    public function test_crops_white_margin_so_product_fills_frame(): void
    {
        $this->requireGd();
        $src = $this->pngBytes(function ($im, int $black): void {
            imagefilledrectangle($im, 90, 70, 130, 140, $black);
        });

        $jpeg = (new ProductImageWhiteTrim)->toJpeg($src);
        $this->assertNotNull($jpeg);
        $out = imagecreatefromstring((string) $jpeg);
        $this->assertNotFalse($out);

        $ratio = $this->nonWhiteRatio($out);
        imagedestroy($out);
        $this->assertGreaterThan(0.55, $ratio);
        $this->assertGreaterThan(0.55, $ratio - $this->sourceNonWhiteRatio($src));
    }

    public function test_keeps_full_frame_when_image_is_only_white(): void
    {
        $this->requireGd();
        $src = $this->pngBytes(static function (): void {});

        $jpeg = (new ProductImageWhiteTrim)->toJpeg($src, 80);
        $this->assertNotNull($jpeg);
        $out = imagecreatefromstring((string) $jpeg);
        $this->assertNotFalse($out);
        $this->assertSame(80, imagesx($out));
        $this->assertSame(80, imagesy($out));
        imagedestroy($out);
    }

    private function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD jest wymagane');
        }
    }

    /**
     * @param  callable(\GdImage, int): void  $draw
     */
    private function pngBytes(callable $draw): string
    {
        $im = imagecreatetruecolor(200, 200);
        $this->assertNotFalse($im);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 20, 20, 20);
        imagefill($im, 0, 0, $white);
        $draw($im, $black);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function sourceNonWhiteRatio(string $bytes): float
    {
        $im = imagecreatefromstring($bytes);
        $this->assertNotFalse($im);
        $ratio = $this->nonWhiteRatio($im);
        imagedestroy($im);

        return $ratio;
    }

    private function nonWhiteRatio(\GdImage $im): float
    {
        $width = imagesx($im);
        $height = imagesy($im);
        $content = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if (min($r, $g, $b) < 240) {
                    $content++;
                }
            }
        }

        return $content / ($width * $height);
    }
}
