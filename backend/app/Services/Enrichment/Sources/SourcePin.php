<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Sources;

/**
 * Karta przypięta do jednej strony źródłowej (10.10.2026) — wspólny kształt dla tabeli części producenta
 * (PartsTablePin, Coba) i mapy importera cennika (MappedSourcePin, product_source_pins). Pobieranie opisu karty
 * z przypięciem nie szuka niczego: czyta wyłącznie url(), tożsamość jest twarda z decyzji importera/tabeli.
 */
interface SourcePin
{
    public function url(): string;

    public function title(): ?string;

    /** Zdjęcie wskazane wprost (null = zdjęcia wybiera przebieg ze strony url()). */
    public function imageUrl(): ?string;

    /** @return array{verdict: 'hard', reason: string, key_type: string, key: string, where: string} */
    public function identity(): array;

    /** @return list<string> linie „Etykieta: wartość” — dane producenta/cennika tej karty */
    public function specLines(): array;

    /** Notatka do polecenia modelu: źródłem jest wyłącznie ta strona. */
    public function promptNote(): string;

    /** Klucz w enrichment_payload: 'parts_table' | 'source_map'. */
    public function payloadKey(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    /** Klucz grupy modelu (ModelGroupPlanner): 'coba|page:slug' | 'map:'.sha1(adres). */
    public function groupKey(): string;

    /** true = zdjęcia karty ustawia klasa przypięcia (PartsTableImages / MappedSourceImages), przebieg ich nie dobiera. */
    public function handlesImages(): bool;

    /** Krótki opis do logu przebiegu. */
    public function logLabel(): string;

    /** Powód publikacji w DescriptionVersionStore::decide. */
    public function publishReason(): string;
}
