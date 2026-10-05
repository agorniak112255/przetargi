<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\PriceList;

/**
 * Źródła opisów cennika z pliku dla jednego przebiegu wzbogacania karty (PriceListCards::sourceSettingsFor).
 * Strony cennika działają tylko wtedy, gdy w puli nie ma karty producenta — karta producenta tnie pulę jak dotąd.
 */
final readonly class PriceListSourceSettings
{
    /**
     * @param  list<string>  $hosts  w kolejności ważności
     */
    public function __construct(
        public int $priceListId,
        public string $manufacturer,
        public array $hosts,
        public string $mode,
        public string $hostsSha1,
    ) {}

    /** Ustawienia zapisane przy cenniku; null = cennik bez stron. */
    public static function fromList(PriceList $list): ?self
    {
        $hosts = $list->enrichmentHosts();
        if ($hosts === []) {
            return null;
        }

        return new self(
            (int) $list->id,
            (string) $list->manufacturer,
            $hosts,
            $list->enrichmentSitesMode(),
            $list->enrichmentHostsSha1(),
        );
    }

    public function onlyMode(): bool
    {
        return $this->mode === PriceList::MODE_ONLY;
    }

    /** Pozycja hosta adresu na liście cennika (0 = najważniejszy), także dla subdomeny; null = spoza listy. */
    public function position(string $url): ?int
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if ($host === '') {
            return null;
        }
        foreach ($this->hosts as $i => $listed) {
            if ($host === $listed || str_ends_with($host, '.'.$listed)) {
                return $i;
            }
        }

        return null;
    }

    public function covers(string $url): bool
    {
        return $this->position($url) !== null;
    }
}
