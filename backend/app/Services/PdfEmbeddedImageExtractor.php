<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Wyciąga osadzone JPEG/JP2 ze skanów PDF bez katalogu/xref (smalot pada na „Missing catalog”).
 */
final class PdfEmbeddedImageExtractor
{
    /**
     * @return list<array{bytes: string, mime: string, label: string}>
     */
    public function extract(string $path, int $maxImages = 20): array
    {
        if ($maxImages < 1 || ! is_file($path)) {
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

            $dictFrom = max(0, $markerAt - 400);
            $dict = substr($data, $dictFrom, $streamKw - $dictFrom);
            $filter = $this->filterName($dict);
            $mime = match ($filter) {
                'DCTDecode' => 'image/jpeg',
                'JPXDecode' => 'image/jp2',
                default => null,
            };
            if ($mime === null || preg_match('/\/Length\s+(\d+)/', $dict, $lenMatch) !== 1) {
                $offset = $streamKw + 6;

                continue;
            }

            $start = $this->streamPayloadStart($data, $streamKw);
            $length = (int) $lenMatch[1];
            $bytes = substr($data, $start, $length);
            $offset = $start + $length;
            if ($bytes === '' || ($mime === 'image/jpeg' && ! str_starts_with($bytes, "\xFF\xD8"))) {
                continue;
            }

            $page++;
            $images[] = [
                'bytes' => $bytes,
                'mime' => $mime,
                'label' => 'Strona '.$page,
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

    private function filterName(string $dict): string
    {
        if (preg_match('/\/Filter\s*\[\s*\/(\w+)/', $dict, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\/Filter\s*\/(\w+)/', $dict, $m) === 1) {
            return $m[1];
        }

        return '';
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
