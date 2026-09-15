<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Models\Product;
use App\Support\ProductDescriptionText;

/**
 * Fragmenty karty w kolejności pokazywania: nazwa, kolumna norm, specyfikacja, cechy, normy
 * i materiały z opisu pobranego, na końcu opis. `enrichment_payload.attributes` celowo pomijamy:
 * to dane pochodne bez cytatu i z błędami (karta 11202000: „bez lateksu” zapisane jako rodzina
 * materiału „lateks”), a porównanie ma pokazywać tylko to, co karta mówi wprost.
 */
final class CardSources
{
    /**
     * @return list<CardSource>
     */
    public static function fromProduct(Product $product): array
    {
        $out = [];
        self::push($out, CardSource::NAME, (string) $product->name);
        self::push($out, CardSource::NORMS, (string) ($product->norms ?? ''));

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        foreach ([
            CardSource::SPECS => 'specs',
            CardSource::FEATURES => 'features',
            CardSource::PAYLOAD_NORMS => 'norms',
            CardSource::MATERIALS => 'materials',
        ] as $source => $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $item) {
                if (is_string($item)) {
                    self::push($out, $source, $item);
                }
            }
        }

        self::push($out, CardSource::DESCRIPTION, ProductDescriptionText::plain($product->description));

        return $out;
    }

    /**
     * @param  list<CardSource>  $out
     */
    private static function push(array &$out, string $source, string $text): void
    {
        $text = trim($text);
        if ($text !== '') {
            $out[] = new CardSource($source, $text);
        }
    }
}
