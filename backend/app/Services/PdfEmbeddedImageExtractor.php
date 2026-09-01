<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Wyciąga osadzone JPEG/JP2 ze skanów PDF bez katalogu/xref (smalot pada na „Missing catalog”).
 */
final class PdfEmbeddedImageExtractor
{
    /**
     * @param  'pages'|'price_bitmaps'  $kind
     * @return list<array{bytes: string, mime: string, label: string}>
     */
    public function extract(string $path, int $maxImages = 20, string $kind = 'pages'): array
    {
        if ($maxImages < 1 || ! is_file($path) || ! in_array($kind, ['pages', 'price_bitmaps'], true)) {
            return [];
        }
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            return [];
        }

        $images = [];
        $offset = 0;
        $page = 0;
        while ($page < $maxImages) {
            $found = $this->nextImageMarker($data, $offset);
            if ($found === null) {
                break;
            }
            [$markerAt, $markerLen] = $found;
            $streamKw = strpos($data, 'stream', $markerAt + $markerLen);
            if ($streamKw === false || ($streamKw - $markerAt) > 4000) {
                $offset = $markerAt + $markerLen;

                continue;
            }

            $dict = $this->imageDictionary($data, $markerAt, $streamKw);
            if ($dict === null) {
                $offset = $markerAt + $markerLen;

                continue;
            }
            $filters = $this->filterChain($dict);
            $hasPhoto = $this->hasPhotoFilter($filters);
            if ($kind === 'pages' && ! $hasPhoto) {
                $offset = $streamKw + 6;

                continue;
            }
            if ($kind === 'price_bitmaps' && (! in_array('FlateDecode', $filters, true) || $hasPhoto)) {
                $offset = $streamKw + 6;

                continue;
            }
            $length = $this->streamLength($data, $dict);
            if ($length === null || $length < 8) {
                $offset = $streamKw + 6;

                continue;
            }

            $start = $this->streamPayloadStart($data, $streamKw);
            $bytes = substr($data, $start, $length);
            $offset = $start + $length;
            $decoded = $this->decodeImageBytes($bytes, $filters, $dict);
            if ($decoded === null) {
                continue;
            }

            $page++;
            $images[] = [
                'bytes' => $decoded['bytes'],
                'mime' => $decoded['mime'],
                'label' => $this->imageLabel($page, $dict),
            ];
        }

        return $images;
    }

    /**
     * @param  list<array{bytes: string, mime: string, label: string}>  $images
     * @return list<array{bytes: string, mime: string, label: string}>
     */
    public function prepareForVision(array $images, int $maxEdge = 1400, int $quality = 72): array
    {
        $out = [];
        foreach ($images as $image) {
            $out[] = $this->downscaleImage($image, $maxEdge, $quality);
        }

        return $out;
    }

    /**
     * @param  array{bytes: string, mime: string, label: string}  $image
     * @return array{bytes: string, mime: string, label: string}
     */
    private function downscaleImage(array $image, int $maxEdge, int $quality): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return $image;
        }
        $src = @imagecreatefromstring($image['bytes']);
        if ($src === false) {
            return $image;
        }
        $width = imagesx($src);
        $height = imagesy($src);
        $edge = max($width, $height);
        if ($edge <= $maxEdge) {
            imagedestroy($src);

            return $image;
        }
        $scale = $maxEdge / $edge;
        $dstW = max(1, (int) round($width * $scale));
        $dstH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            imagedestroy($src);

            return $image;
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
        ob_start();
        imagejpeg($dst, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if ($bytes === '' || strlen($bytes) >= strlen($image['bytes'])) {
            return $image;
        }

        return [
            'bytes' => $bytes,
            'mime' => 'image/jpeg',
            'label' => $image['label'],
        ];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function nextImageMarker(string $data, int $offset): ?array
    {
        $a = strpos($data, '/Subtype /Image', $offset);
        $b = strpos($data, '/Subtype/Image', $offset);
        if ($a === false && $b === false) {
            return null;
        }
        if ($a === false) {
            return [$b, strlen('/Subtype/Image')];
        }
        if ($b === false || $a < $b) {
            return [$a, strlen('/Subtype /Image')];
        }

        return [$b, strlen('/Subtype/Image')];
    }

    /**
     * Słownik obiektu Image — nie okno wstecz (tam bywa /Filter /FlateDecode z Contents).
     */
    private function imageDictionary(string $data, int $markerAt, int $streamKw): ?string
    {
        $searchFrom = max(0, $markerAt - 800);
        $region = substr($data, $searchFrom, $markerAt - $searchFrom);
        $dictOpen = strrpos($region, '<<');
        if ($dictOpen === false) {
            return null;
        }
        $start = $searchFrom + $dictOpen;
        if ($streamKw <= $start) {
            return null;
        }

        return substr($data, $start, $streamKw - $start);
    }

    private function streamLength(string $data, string $dict): ?int
    {
        if (preg_match('/\/Length\s+(\d+)\s+(\d+)\s+R/', $dict, $m) === 1) {
            return $this->resolveIndirectInteger($data, (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/\/Length\s+(\d+)/', $dict, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function resolveIndirectInteger(string $data, int $objectId, int $generation): ?int
    {
        $needle = $objectId.' '.$generation.' obj';
        $offset = 0;
        while (($pos = strpos($data, $needle, $offset)) !== false) {
            $before = $pos === 0 ? '' : $data[$pos - 1];
            if ($pos > 0 && ctype_digit($before)) {
                $offset = $pos + 1;

                continue;
            }
            $after = substr($data, $pos + strlen($needle), 80);
            if (preg_match('/^\s+(\d+)\s+endobj/', $after, $m) === 1) {
                return (int) $m[1];
            }
            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * @param  list<string>  $filters
     * @return array{bytes: string, mime: string}|null
     */
    private function decodeImageBytes(string $bytes, array $filters, string $dict): ?array
    {
        if ($bytes === '' || $filters === []) {
            return null;
        }
        $data = $bytes;
        $last = array_key_last($filters);
        foreach ($filters as $i => $filter) {
            if ($filter === 'FlateDecode') {
                $raw = @gzuncompress($data);
                if ($raw === false) {
                    $raw = @gzinflate($data);
                }
                if (! is_string($raw) || $raw === '') {
                    return null;
                }
                $data = $raw;
                if ($i === $last) {
                    return $this->rasterToJpeg($data, $dict);
                }

                continue;
            }
            if ($filter === 'DCTDecode') {
                return str_starts_with($data, "\xFF\xD8")
                    ? ['bytes' => $data, 'mime' => 'image/jpeg']
                    : null;
            }
            if ($filter === 'JPXDecode') {
                return ['bytes' => $data, 'mime' => 'image/jp2'];
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function filterChain(string $dict): array
    {
        if (preg_match('/\/Filter\s*\[([^\]]+)\]/', $dict, $m) === 1
            && preg_match_all('/\/(\w+)/', $m[1], $names) > 0) {
            return array_values($names[1]);
        }
        if (preg_match('/\/Filter\s*\/(\w+)/', $dict, $m) === 1) {
            return [$m[1]];
        }

        return [];
    }

    /**
     * @param  list<string>  $filters
     */
    private function hasPhotoFilter(array $filters): bool
    {
        return in_array('DCTDecode', $filters, true) || in_array('JPXDecode', $filters, true);
    }

    /**
     * Ceny w cennikach (np. RENEX) bywają 1-bit DeviceGray, nie JPEG.
     *
     * @return array{bytes: string, mime: string}|null
     */
    private function rasterToJpeg(string $raw, string $dict): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        if (preg_match('/\/Width\s+(\d+)/', $dict, $wm) !== 1
            || preg_match('/\/Height\s+(\d+)/', $dict, $hm) !== 1) {
            return null;
        }
        $width = (int) $wm[1];
        $height = (int) $hm[1];
        if ($width < 40 || $height < 20 || $width > 4000 || $height > 4000) {
            return null;
        }
        $bpc = preg_match('/\/BitsPerComponent\s+(\d+)/', $dict, $bm) === 1 ? (int) $bm[1] : 8;
        $gray = preg_match('/\/ColorSpace\s*\/DeviceGray/', $dict) === 1;
        $invert = preg_match('/\/Decode\s*\[\s*1(?:\.0)?\s+0(?:\.0)?\s*\]/', $dict) === 1;
        if (! $gray || $bpc !== 1) {
            return null;
        }

        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            return null;
        }
        $rowBytes = (int) ceil($width / 8);
        $need = $rowBytes * $height;
        $raw = str_pad($raw, $need, "\x00");
        $black = imagecolorallocate($im, 0, 0, 0);
        $white = imagecolorallocate($im, 255, 255, 255);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $byte = ord($raw[$y * $rowBytes + intdiv($x, 8)]);
                $bit = ($byte >> (7 - ($x % 8))) & 1;
                if ($invert) {
                    $bit = $bit === 1 ? 0 : 1;
                }
                imagesetpixel($im, $x, $y, $bit === 1 ? $black : $white);
            }
        }

        ob_start();
        imagejpeg($im, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        if ($jpeg === '' || ! str_starts_with($jpeg, "\xFF\xD8")) {
            return null;
        }

        return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
    }

    private function imageLabel(int $index, string $dict): string
    {
        $w = preg_match('/\/Width\s+(\d+)/', $dict, $m) === 1 ? (int) $m[1] : 0;
        $h = preg_match('/\/Height\s+(\d+)/', $dict, $m) === 1 ? (int) $m[1] : 0;
        if ($h > 0 && $h < 400 && $w > 0 && $w < 2000) {
            return 'Fragment '.$index;
        }

        return 'Strona '.$index;
    }

    private function streamPayloadStart(string $data, int $streamKw): int
    {
        $i = $streamKw + 6;
        $len = strlen($data);
        if ($i < $len && $data[$i] === "\r") {
            $i++;
            if ($i < $len && $data[$i] === "\n") {
                $i++;
            }

            return $i;
        }
        if ($i < $len && $data[$i] === "\n") {
            return $i + 1;
        }

        return $i;
    }
}
