<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego strona produktu ma pliki do pobrania (karty techniczne, instrukcje). Synchronizacja zapisuje je
 * przy karcie jako ProductDocument — z nazwą i adresem źródła, żeby każdy parametr dało się sprawdzić u dostawcy.
 *
 * Listowanie jest oddzielone od pobierania: lista jest darmowa (strona produktu i tak bywa pobierana dla opisu),
 * a bajty ściągamy tylko dla plików, których karta jeszcze nie ma.
 */
interface B2bDocumentSource
{
    /**
     * Pliki ze strony produktu; [] gdy nie ma czego pobierać.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array;

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array;
}
