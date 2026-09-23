<?php

declare(strict_types=1);

namespace App\Services\Norms;

/**
 * Czytniki norm dla adresu strony: najpierw czytniki witryny (CXS), na końcu ogólny czytnik ramki norm. Czytnik
 * witryny zna miejsce norm na stronie (CXS: wiersz „normy:” w opisie), a ogólny łapie ramkę z klasą „norms”, jeśli
 * witryna ją ma — polecenie bierze pierwszy odczyt z parami.
 */
final class ManufacturerNormReaders
{
    public function __construct(
        private readonly CxsNormPageReader $cxs,
        private readonly AnsellNormPageReader $ansell,
        private readonly GenericNormFrameReader $generic,
    ) {}

    /**
     * @return list<ManufacturerNormPageReader>
     */
    public function for(string $url): array
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $out = [];
        foreach ([$this->cxs, $this->ansell] as $reader) {
            if ($host !== '' && $reader->supports($host)) {
                $out[] = $reader;
            }
        }
        $out[] = $this->generic;

        return $out;
    }
}
