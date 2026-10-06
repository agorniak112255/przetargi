<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\OfferInspectionLine;
use App\Support\PolishTime;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Tabela „Co wymaga przeglądu” w mailu oferty przeglądu (Offer kind = inspection): urządzenie lub usługa, ilość,
 * ostatni przegląd lub zakup u nas, termin przeglądu — bez cen (oferta zaczepna). Reszta maila (baner, wstęp, podpis,
 * stopka) to szablon kampanii (CampaignRenderer::renderItems) — OfferRenderer wstawia tam blok tekstowy ze znacznikiem,
 * a ta klasa podmienia go na tabelę w HTML i w wersji tekstowej. Style inline jak w szablonie emails/campaign (klienci
 * poczty nie czytają <style>); wszystkie treści escapowane. Nie final — testy podmieniają zależności.
 */
class InspectionOfferRenderer
{
    private const TEXT = '#1b2328';

    private const MUTED = '#5d6970';

    private const LINE = '#e2e7ea';

    private const FONT = 'Arial,Helvetica,sans-serif';

    /**
     * Termin w tabeli to wyliczenie (ostatnia sprzedaż u nas + interwał pozycji), nie fakt — klient mógł zrobić przegląd
     * gdzie indziej. Stałe zdanie pod tabelą, którego handlowiec nie usunie razem ze wstępem.
     */
    public const BASIS = 'Proponowany termin wyliczyliśmy z daty ostatniego przeglądu lub zakupu w naszej firmie i odstępu przeglądów przyjętego dla danego urządzenia. Jeśli przegląd był już wykonany gdzie indziej, prosimy o informację.';

    /** Znacznik miejsca tabeli — losowy, żeby nie trafić na ten sam napis we wstępie handlowca. */
    public function token(): string
    {
        return 'INSPECTIONTABLE'.bin2hex(random_bytes(8));
    }

    /**
     * Podmienia akapit ze znacznikiem na tabelę wierszy oferty (świeżo z bazy). Brak znacznika w HTML albo w tekście
     * = zmieniony szablon kampanii; wtedy wyjątek — mail bez tabeli nie może pójść do klienta.
     *
     * @param  array{subject: string, html: string, text: string}  $rendered
     * @return array{subject: string, html: string, text: string}
     */
    public function insertTable(array $rendered, string $token, Offer $offer): array
    {
        $lines = $offer->inspectionLines()->get();
        $table = $this->html($lines, $offer);
        // callback, nie napis zastępujący — „$1” albo „\1” w uwadze handlowca nie może być czytane jako odwołanie
        $html = preg_replace_callback('#<p\b[^>]*>'.preg_quote($token, '#').'</p>#', static fn (): string => $table, $rendered['html'], -1, $htmlCount);
        if (! is_string($html) || $htmlCount !== 1 || substr_count($rendered['text'], $token) !== 1) {
            throw new RuntimeException('Szablon maila oferty nie ma miejsca na tabelę przeglądów.');
        }

        return [
            'subject' => $rendered['subject'],
            'html' => $html,
            'text' => str_replace($token, $this->text($lines, $offer), $rendered['text']),
        ];
    }

    /** @param  Collection<int, OfferInspectionLine>  $lines */
    private function html(Collection $lines, Offer $offer): string
    {
        $th = 'padding:8px 10px;background:#f6f8f9;font-size:11px;font-weight:700;color:'.self::MUTED.';';
        $td = 'padding:8px 10px;border-top:1px solid '.self::LINE.';font-size:13px;color:'.self::TEXT.';';

        $out = '<div style="margin:6px 0 8px;font-family:'.self::FONT.';font-size:15px;font-weight:700;color:'.self::TEXT.';">Co wymaga przeglądu</div>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid '.self::LINE.';border-collapse:collapse;font-family:'.self::FONT.';margin:0 0 10px;">'
            .'<tr>'
            .'<td style="'.$th.'">Urządzenie lub usługa</td>'
            .'<td align="right" style="'.$th.'white-space:nowrap;">Ilość</td>'
            .'<td style="'.$th.'">Ostatni przegląd lub zakup u nas</td>'
            .'<td style="'.$th.'">Proponowany termin przeglądu</td>'
            .'</tr>';
        foreach ($lines as $line) {
            $note = $this->note($line);
            $out .= '<tr>'
                .'<td valign="top" style="'.$td.'"><b>'.e((string) $line->name).'</b>'
                .($note !== null ? '<div style="font-size:12px;color:'.self::MUTED.';">'.e($note).'</div>' : '')
                .'</td>'
                .'<td valign="top" align="right" style="'.$td.'white-space:nowrap;">'.e($this->quantity($line, "\u{00A0}")).'</td>'
                .'<td valign="top" style="'.$td.'white-space:nowrap;">'.e($this->date($line->last_on)).'</td>'
                .'<td valign="top" style="'.$td.'white-space:nowrap;font-weight:700;">'.e($this->dueDate($line->due_on)).'</td>'
                .'</tr>';
        }
        $out .= '</table>'
            .'<div style="margin:0 0 6px;font-family:'.self::FONT.';font-size:12px;color:'.self::MUTED.';">'.e(self::BASIS).'</div>';
        $valid = $this->validUntil($offer);
        if ($valid !== null) {
            $out .= '<div style="font-family:'.self::FONT.';font-size:12px;color:'.self::MUTED.';">'.e($valid).'</div>';
        }

        return $out;
    }

    /** @param  Collection<int, OfferInspectionLine>  $lines */
    private function text(Collection $lines, Offer $offer): string
    {
        $out = "Co wymaga przeglądu:\n";
        foreach ($lines as $line) {
            $note = $this->note($line);
            $out .= "\n* ".$this->oneLine((string) $line->name)."\n"
                .($note !== null ? '  '.$note."\n" : '')
                .'  Ilość: '.$this->quantity($line, ' ')."\n"
                .'  Ostatni przegląd lub zakup u nas: '.$this->date($line->last_on)."\n"
                .'  Proponowany termin przeglądu: '.$this->dueDate($line->due_on)."\n";
        }
        $out .= "\n".self::BASIS."\n";
        $valid = $this->validUntil($offer);

        return rtrim($valid !== null ? $out."\n".$valid : $out);
    }

    private function validUntil(Offer $offer): ?string
    {
        return $offer->valid_until !== null ? 'Oferta ważna do '.$offer->valid_until->format('d.m.Y').'.' : null;
    }

    private function note(OfferInspectionLine $line): ?string
    {
        $note = $this->oneLine((string) $line->note);

        return $note !== '' ? $note : null;
    }

    /** Termin sprzed dziś (polska data) — z dopiskiem, żeby klient nie czytał daty z przeszłości jako propozycji. */
    private function dueDate(mixed $date): string
    {
        $text = $this->date($date);
        if ($date instanceof \DateTimeInterface && $date->format('Y-m-d') < PolishTime::today()->toDateString()) {
            return $text.' (termin minął)';
        }

        return $text;
    }

    private function date(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('d.m.Y') : '—';
    }

    /** Ilość z jednostką (10 szt, 2,5 kg); brak ilości = „—” (handlowiec wyczyścił pole). */
    private function quantity(OfferInspectionLine $line, string $space): string
    {
        if ($line->quantity === null) {
            return '—';
        }
        $value = (float) $line->quantity;
        $decimals = abs($value - round($value)) < 0.0005 ? 0 : 3;
        $formatted = number_format($value, $decimals, ',', $space);
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }
        $unit = trim((string) $line->unit);

        return $unit !== '' ? $formatted.$space.$unit : $formatted;
    }

    private function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/u', ' ', $value));
    }
}
