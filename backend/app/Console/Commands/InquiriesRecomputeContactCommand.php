<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClientInquiry;
use App\Services\ClientInquiryService;
use Illuminate\Console\Command;

/**
 * Przeliczenie kontaktu ze stopki maila dla zapisanych zapytań. Kontakt liczy się raz, przy zakładaniu zapytania,
 * a „Ponów analizę” go nie rusza — po poprawce cięcia stopki (56e4e85, 06.10.2026) zapytania #91 i #93 dalej
 * pokazywały adresy handlowców z cytatu i numer zapytania jako telefon.
 *
 * Domyślnie podgląd: liczy i pokazuje różnice, nic nie zapisuje. Z --apply zapisuje sam `contact` (bez zmiany
 * updated_at — to poprawka danych, nie praca handlowca). Nowego kontaktu z naszym adresem nie zapisuje: przy mailu
 * z naszej skrzynki stopka to podpis pracownika, a nie dane klienta — zostaje to, co było.
 */
final class InquiriesRecomputeContactCommand extends Command
{
    protected $signature = 'inquiries:recompute-contact
                            {ids?* : Numery zapytań (bez numerów — wszystkie)}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Przelicza kontakt ze stopki maila zapisanych zapytań i pokazuje, co się zmienia (zapis tylko z --apply)';

    private const FIELDS = [
        'person' => 'Osoba',
        'company' => 'Firma',
        'emails' => 'E-maile',
        'phones' => 'Telefony',
        'address' => 'Adres',
        'website' => 'Strona',
    ];

    public function handle(ClientInquiryService $service): int
    {
        $apply = (bool) $this->option('apply');
        $ids = [];
        foreach ((array) $this->argument('ids') as $raw) {
            $id = (int) $raw;
            if ($id <= 0 || (string) $id !== trim((string) $raw)) {
                $this->error("„{$raw}” to nie jest numer zapytania.");

                return self::INVALID;
            }
            $ids[] = $id;
        }

        $query = ClientInquiry::query()
            ->select(['id', 'source_body', 'source_channel', 'source_subject', 'source_from_email', 'contact'])
            ->when($ids !== [], fn ($q) => $q->whereKey($ids));
        $found = [];
        $counts = ['all' => 0, 'changed' => 0, 'skipped' => 0];

        foreach ($query->lazyById(100) as $inquiry) {
            /** @var ClientInquiry $inquiry */
            $found[] = (int) $inquiry->id;
            $counts['all']++;
            $before = is_array($inquiry->contact) ? $inquiry->contact : null;
            $after = $service->recomputedContact($inquiry);
            if ($before == $after) {
                continue;
            }

            $internal = array_values(array_filter(
                $after['emails'] ?? [],
                static fn (string $email): bool => ClientInquiryService::isInternalEmail($email),
            ));
            $saved = $apply && $internal === [];
            if ($saved) {
                $inquiry->timestamps = false;
                $inquiry->forceFill(['contact' => $after])->save();
            }

            $counts[$internal === [] ? 'changed' : 'skipped']++;
            $this->line('#'.$inquiry->id.' — '.($internal !== [] ? 'pominięte' : ($saved ? 'zapisane' : 'podgląd')));
            $this->table(['Pole', 'Było', 'Będzie'], $this->rows($before, $after));
            if ($internal !== []) {
                $this->warn('  W nowym kontakcie jest nasz adres ('.implode(', ', $internal).') — to podpis pracownika, nie klienta. Zostaje to, co było.');
            }
        }

        foreach (array_diff($ids, $found) as $missing) {
            $this->warn("#{$missing}: nie ma takiego zapytania");
        }

        $this->newLine();
        $this->line("Sprawdzone zapytania: {$counts['all']}. ".($apply ? 'Zapisane' : 'Kontakt do zmiany').": {$counts['changed']}. Pominięte (nasz adres w nowym kontakcie): {$counts['skipped']}.");
        if (! $apply && $counts['changed'] > 0) {
            $this->line('Nic nie zapisano. Żeby zapisać zmiany, uruchom polecenie jeszcze raz z --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Wiersze tabeli: tylko pola, które się zmieniają, i długość surowej stopki, gdy zmienia się sama ona.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function rows(?array $before, ?array $after): array
    {
        $rows = [];
        foreach (self::FIELDS as $key => $label) {
            $was = $this->value($before[$key] ?? null);
            $will = $this->value($after[$key] ?? null);
            if ($was !== $will) {
                $rows[] = [$label, $was, $will];
            }
        }
        $rawBefore = (string) ($before['raw'] ?? '');
        $rawAfter = (string) ($after['raw'] ?? '');
        if ($rows === [] && $rawBefore !== $rawAfter) {
            $rows[] = ['Stopka (znaków)', (string) mb_strlen($rawBefore), (string) mb_strlen($rawAfter)];
        }

        return $rows;
    }

    private function value(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn (mixed $v): string => (string) $v, $value));
        }

        return is_string($value) && trim($value) !== '' ? $value : '—';
    }
}
