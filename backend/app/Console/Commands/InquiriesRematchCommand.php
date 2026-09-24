<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClientInquiry;
use App\Services\ClientInquiryService;
use Illuminate\Console\Command;

/**
 * Ponowne szukanie w katalogu dla zapisanych zapytań klientów. 24.09.2026 kilka zapytań
 * (#64, #65, #67) przeanalizowano, gdy model nie odpowiadał albo wyszukiwarka miała błąd —
 * ekran pokazywał „brak w katalogu” przy wyrobach, które w katalogu są.
 *
 * Domyślnie podgląd: wyszukiwarka i model są pytane naprawdę, ale nic się nie zapisuje.
 * Z --apply zapisuje nowe wyniki i składa list od nowa. Zapytań z wysłaną odpowiedzią
 * albo z listem czekającym na wysłanie nie rusza.
 */
final class InquiriesRematchCommand extends Command
{
    protected $signature = 'inquiries:rematch
                            {ids* : Numery zapytań}
                            {--apply : Zapisz wynik (bez tego tylko podgląd)}';

    protected $description = 'Ponownie szuka wyrobów w katalogu dla zapisanych zapytań klientów i pokazuje, co się zmienia (zapis tylko z --apply)';

    public function handle(ClientInquiryService $service): int
    {
        $apply = (bool) $this->option('apply');

        foreach ((array) $this->argument('ids') as $raw) {
            $id = (int) $raw;
            $inquiry = $id > 0 ? ClientInquiry::query()->find($id) : null;
            if ($inquiry === null) {
                $this->warn("#{$raw}: nie ma takiego zapytania");

                continue;
            }

            $report = $service->rematch($inquiry, $apply);
            if ($report['skipped'] !== null) {
                $this->line("#{$id}: pominięte — {$report['skipped']}");

                continue;
            }

            $this->line("#{$id}".($apply ? ' — zapisane' : ' — podgląd'));
            $this->table(
                ['Pozycja', 'Było', 'Jest', 'Odpowiedź było', 'Odpowiedź jest'],
                array_map(fn (array $item): array => [
                    $item['id'],
                    $this->candidateLabel($item['before']),
                    $this->candidateLabel($item['after']),
                    $item['answer_before'] ?? '—',
                    $item['answer_after'] ?? '—',
                ], $report['items']),
            );
            foreach ($report['warnings'] as $warning) {
                $this->warn('  '.$warning);
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Nic nie zapisano. Żeby zapisać wynik, uruchom polecenie jeszcze raz z --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{sku: string, name: string, score: int}|null  $candidate
     */
    private function candidateLabel(?array $candidate): string
    {
        if ($candidate === null) {
            return 'brak kandydata';
        }

        return $candidate['sku'].' '.mb_substr($candidate['name'], 0, 40).' ('.$candidate['score'].')';
    }
}
