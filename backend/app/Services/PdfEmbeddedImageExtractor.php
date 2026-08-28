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

            $dict = $this->imageDictionary($data, $markerAt, $streamKw);
            if ($dict === null) {
                $offset = $markerAt + $markerLen;

                continue;
            }
            $filter = $this->filterName($dict);
            $mime = match ($filter) {
                'DCTDecode' => 'image/jpeg',
                'JPXDecode' => 'image/jp2',
                default => null,
            };
            $length = $this->streamLength($data, $dict);
            if ($mime === null || $length === null || $length < 32) {
                $offset = $streamKw + 6;

                continue;
            }

            $start = $this->streamPayloadStart($data, $streamKw);
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
