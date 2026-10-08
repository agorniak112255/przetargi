<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PromptEcho;
use PHPUnit\Framework\TestCase;

final class PromptEchoTest extends TestCase
{
    /** Zdania i pozycje z audytów 08.10.2026, w których model powtarza polecenie albo swój tok sprawdzania. */
    public function test_prompt_echo_from_audits_is_detected(): void
    {
        foreach ([
            'MAPA Harpon 330' => 'Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje właściwości rękawic, co jest zgodne z wymaganiami.',
            'SECURA 151' => 'Produkt dostępny jest w rozmiarze S (zgodnie z numerem katalogowym S56T0SS0 w katalogu producenta), choć w opisach zestawów detalicznych dla tego samego SKU podawany jest rozmiar M.',
            'AJ GROUP 217' => 'Model ten stanowi dobre uzupełnienie kurtki model 285 (lub 1031 OC, zgodnie z różnymi źródłami).',
            'Ansell 11772' => "EN ISO 21420 (wymagania ogólne - implied by context of PPE, but specific cut level is EN ISO 13999 or similar, source says 'EN ISO D / A4' for cut resistance)",
            'strona opisuje' => 'Strona opisuje rękawice tego samego modelu w innym kolorze.',
            'ELTEN 64571' => 'Buty ELTEN ANTHONY red Mid ESD przeznaczone dla osób o normalnej szerokości stopy (Typ 1 w nazwie karty, choć źródło opisuje Typ 2).',
            'source says' => 'EN 388 (source says 4131X)',
        ] as $case => $text) {
            $this->assertTrue(PromptEcho::isEcho($text), $case);
        }
    }

    public function test_product_facts_are_not_echo(): void
    {
        foreach ([
            'Rękawice są zgodne z wymaganiami normy EN 388.',
            'Kategoria III ŚOI',
            'Rękawice należą do kategorii PPE III.',
            // pierwsze zdania prawdziwych opisów z produkcji (MAPA 70, Polstar 27173) — nie echo
            'Rękawice precyzyjne MAPA Ultrane 526 to produkt z kategorii PPE, zaprojektowany z myślą o pracy w środowiskach z olejami i smarami.',
            'Produkt należy do kategorii PPE zgodnie z rozporządzeniem UE 2016/425 i jest oznaczony znakiem CE.',
            'Obuwie, rękawice i odzież z tej serii łączą się w komplet.',
            'Tekst nadruku na mankiecie podaje rozmiar i normę.',
            'Rękawice kategorii III wg rozporządzenia 2016/425.',
            '',
        ] as $text) {
            $this->assertFalse(PromptEcho::isEcho($text), $text);
        }
    }
}
