<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego karta ma u dostawcy więcej niż jedno zdjęcie (galeria na stronie produktu). Synchronizacja
 * zapisuje wszystkie, w kolejności ze sklepu — pierwsze zostaje zdjęciem głównym.
 *
 * Listowanie jest oddzielone od pobierania, tak jak przy plikach (B2bDocumentSource): adresy są darmowe (strona
 * produktu i tak bywa pobierana dla opisu), a bajty ściągamy tylko dla zdjęć, których karta jeszcze nie ma.
 */
interface B2bImageGallery
{
    /**
     * Adresy zdjęć karty u dostawcy — bez tokenów, w kolejności ze sklepu (pierwszy = główne zdjęcie);
     * [] gdy dostawca nie podaje żadnego.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array;

    /** Zdjęcie spod adresu z imageUrls(); null = dostawca nie wydał obrazu (np. zaślepka). */
    public function imageAt(string $url): ?B2bRemoteImage;
}
