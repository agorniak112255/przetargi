<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CardMatchSignals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sygnał rozmiar/kolor/unknown propozycji „Łączenie kart” (krok 5) — bez zgadywania: etykiety pozycji P4S
 * i nazwy kart 3M z produkcji 23.09.2026.
 */
final class CardMatchSignalsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function labels(): iterable
    {
        yield 'rozmiar z nawiasem' => ['rozmiar S (mały)', 'size'];
        yield 'sama litera' => ['S', 'size'];
        yield 'zakres odzieży' => ['rozmiar 46/48', 'size'];
        yield 'uniwersalny' => ['rozmiar uniwersalny', 'size'];
        yield 'rozm. z dwukropkiem' => ['Rozm.: XL', 'size'];
        yield 'size' => ['size 9', 'size'];
        yield 'kolor i rozmiar' => ['kolor biały, rozmiar L', 'color'];
        yield 'kolor w nawiasie' => ['rozmiar M (czerwony)', 'color'];
        yield 'sam kolor' => ['żółta', 'color'];
        yield 'pusta' => ['', 'unknown'];
        yield 'wersja' => ['wersja krótka', 'unknown'];
        yield 'rozmiar i wersja' => ['rozmiar M długi', 'unknown'];
        yield 'rozmiar spoza zakresu' => ['rozmiar 20', 'unknown'];
        yield 'słowo zaczynające się od rozmiar' => ['rozmiarówka', 'unknown'];
    }

    #[DataProvider('labels')]
    public function test_label_signal(string $label, string $expected): void
    {
        $this->assertSame($expected, (new CardMatchSignals)->labelSignal($label)['signal']);
    }

    public function test_label_signal_why_names_source(): void
    {
        $signals = new CardMatchSignals;

        $this->assertSame('etykieta B2B P4S: rozmiar S (mały)', $signals->labelSignal('rozmiar S (mały)', 'B2B P4S')['why']);
        $this->assertSame('kolor w etykiecie B2B P4S: kolor biały', $signals->labelSignal('kolor biały', 'B2B P4S')['why']);
        $this->assertSame('unknown', $signals->labelSignal(null)['signal']);
    }

    public function test_3m_names_differ_by_size(): void
    {
        $result = (new CardMatchSignals)->nameSignals([
            'Półmaska wielokrotnego użytku 3M™, rozmiar mały, 6100',
            'Półmaska wielokrotnego użytku 3M™, rozmiar średni, 6200',
            'Półmaska wielokrotnego użytku 3M™, rozmiar duży, 6300',
        ], '3M');

        $this->assertSame(['size', 'size', 'size'], array_column($result, 'signal'));
        $this->assertSame(['mały', '6100'], $result[0]['differing']);
        $this->assertSame('nazwa 3M różni się: mały, 6100', $result[0]['why']);
    }

    public function test_padlock_names_differ_by_color(): void
    {
        $result = (new CardMatchSignals)->nameSignals(['KLODKA B CZERWONA RK', 'KLODKA B ZIELONA RK', 'KLODKA B ZOLTA RK']);

        $this->assertSame(['color', 'color', 'color'], array_column($result, 'signal'));
    }

    public function test_identical_names_and_preposition_bez_are_not_signals(): void
    {
        $signals = new CardMatchSignals;

        $this->assertSame(['unknown', 'unknown'], array_column($signals->nameSignals(['Rękawice X', 'Rękawice X']), 'signal'));
        $hood = $signals->nameSignals(['Kombinezon 3M 4520 z kapturem', 'Kombinezon 3M 4520 bez kaptura']);
        $this->assertNotContains('color', array_column($hood, 'signal'));
        $this->assertSame(['unknown', 'unknown'], array_column($hood, 'signal'));
        $this->assertSame('color', $signals->nameSignals(['Kurtka beżowa', 'Kurtka bezowa X', 'Kurtka czarna'])[1]['signal']);
    }

    public function test_word_right_after_size_keyword_is_size(): void
    {
        $result = (new CardMatchSignals)->nameSignals(['Rękawice robocze rozmiar A1', 'Rękawice robocze rozmiar B2']);

        $this->assertSame(['size', 'size'], array_column($result, 'signal'));
    }

    public function test_common_name(): void
    {
        $signals = new CardMatchSignals;

        $this->assertSame('Półmaska wielokrotnego użytku 3M™', $signals->commonName([
            'Półmaska wielokrotnego użytku 3M™, rozmiar mały, 6100',
            'Półmaska wielokrotnego użytku 3M™, rozmiar średni, 6200',
            'Półmaska wielokrotnego użytku 3M™, rozmiar duży, 6300',
        ]));
        $this->assertSame('Kombinezon 3M 4520', $signals->commonName(['Kombinezon 3M 4520, rozmiar M/L', 'Kombinezon 3M 4520, rozmiar XL/XXL']));
        $this->assertNull($signals->commonName(['Kłódka czerwona', 'Zawieszka czerwona']));
        $this->assertNull($signals->commonName(['Rękawice 7', 'Rękawice 8']));
    }

    public function test_size_label(): void
    {
        $signals = new CardMatchSignals;

        $this->assertSame('S (mały)', $signals->sizeLabel('rozmiar S (mały)'));
        $this->assertSame('XL', $signals->sizeLabel('Rozm.: XL'));
        $this->assertSame('46/48', $signals->sizeLabel('46/48'));
        $this->assertSame('kolor biały', $signals->sizeLabel('kolor biały'));
        $this->assertNull($signals->sizeLabel(''));
        $this->assertNull($signals->sizeLabel(null));
    }
}
