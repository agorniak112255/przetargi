<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Services\Catalog\CardMatchFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Propozycje połączenia kart dystrybutora z kartami producenta (plan łączenia kart, etap B): przelicza propozycje
 * (CardMatchFinder::refresh) i pokazuje pary do decyzji oraz konflikty z powodem. Niczego nie łączy — łączy człowiek
 * na ekranie „Łączenie kart”. Uruchamiane codziennie z harmonogramu i ręcznie przed pomiarem trafności.
 */
final class MatchCandidatesCommand extends Command
{
    protected $signature = 'products:match-candidates
                            {--limit=50 : Ile par pokazać w tabeli (0 = wszystkie)}
                            {--csv= : Zapisz wszystkie pary do decyzji i konflikty do tego pliku CSV}';

    protected $description = 'Przelicza propozycje łączenia kart dystrybutora z kartami producenta (niczego nie łączy)';

    public function handle(): int
    {
        // z kontenera przy użyciu, bez podpowiedzi typu w handle(): CardMatchFinder jest final, a test polecenia
        // podmienia go atrapą (jak CardMatchMerger i CardMatchController)
        $summary = app(CardMatchFinder::class)->refresh();

        $rows = CardMatchCandidate::query()
            ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
            ->with(['source:id,sku,name,manufacturer,purchase_price,currency', 'target:id,sku,name,manufacturer,purchase_price,currency'])
            ->get()
            ->sortBy(static fn (CardMatchCandidate $c): string => ($c->status === CardMatchCandidate::STATUS_PENDING ? '0' : '1')
                .'|'.$c->brand.'|'.str_pad((string) $c->source_product_id, 10, '0', STR_PAD_LEFT))
            ->values();

        $csv = trim((string) $this->option('csv'));
        if ($csv !== '') {
            $error = $this->writeCsv($csv, $rows);
            if ($error !== null) {
                $this->error("Nie zapisano pliku CSV ({$error}).");

                return self::FAILURE;
            }
            $this->line('Zapisano '.$rows->count().' par do '.$csv.'.');
        }

        $limit = max(0, (int) $this->option('limit'));
        $shown = $limit > 0 ? $rows->take($limit) : $rows;
        if ($shown->isNotEmpty()) {
            $this->table(
                ['Status', 'Rodzaj', 'Sygnał', 'Karta dystrybutora', 'Karta producenta', 'Klucz', 'Marka', 'Trafione', 'Cena dystr.', 'Cena prod.', 'Powód'],
                $shown->map(fn (CardMatchCandidate $c): array => [
                    $c->status === CardMatchCandidate::STATUS_PENDING ? 'do decyzji' : 'niepewne',
                    self::kindLabel($c),
                    self::signalLabel(self::signal($c)),
                    $this->cardLabel($c->source, $c->source_snapshot),
                    $c->target_product_id !== null
                        ? $this->cardLabel($c->target, null)
                        : implode(', ', array_map(static fn ($id): string => '#'.$id, (array) $c->conflict_product_ids)),
                    self::keyLabel($c),
                    (string) $c->brand,
                    $c->hits.'/'.$c->positions,
                    self::price($c->source),
                    self::price($c->target),
                    mb_substr((string) $c->reason, 0, 80),
                ])->all(),
            );
            if ($shown->count() < $rows->count()) {
                $this->line('Pokazano '.$shown->count().' z '.$rows->count().' (--limit=0 pokazuje wszystkie, --csv zapisuje do pliku).');
            }
        }

        $this->info(sprintf(
            'Do decyzji: %d · niepewne: %d · usunięte nieaktualne: %d · odświeżono %s. Niczego nie połączono.',
            $summary['pending'],
            $summary['conflict'],
            $summary['removed'],
            $summary['refreshed_at'],
        ));
        // pomiar propozycji z planem „pozycja → karta” (do decyzji i niepewne, bez zwykłych par)
        $signals = is_array($summary['signals'] ?? null) ? $summary['signals'] : [];
        $this->line(sprintf(
            'Pomiar: rozmiary %d · kolory %d · niepewne %d',
            (int) ($signals[CardMatchCandidate::SIGNAL_SIZE] ?? 0),
            (int) ($signals[CardMatchCandidate::SIGNAL_COLOR] ?? 0),
            (int) ($signals[CardMatchCandidate::SIGNAL_UNKNOWN] ?? 0),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CardMatchCandidate>  $rows
     */
    private function writeCsv(string $path, Collection $rows): ?string
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            return 'nie można otworzyć '.$path;
        }
        fputcsv($handle, [
            'status', 'klucz', 'wartosc', 'zrodlo_klucza', 'marka', 'trafione', 'pozycje',
            'dystrybutor_id', 'dystrybutor_sku', 'dystrybutor_nazwa', 'dystrybutor_producent', 'dystrybutor_cena', 'dystrybutor_waluta',
            'producent_id', 'producent_sku', 'producent_nazwa', 'producent_producent', 'producent_cena', 'producent_waluta',
            'karty_konfliktu', 'powod',
            // krok 5 — dopisane na końcu, żeby arkusze czytające dotychczasowe kolumny się nie przesunęły
            'rodzaj', 'sygnal', 'plan_pozycje', 'podpowiedz_nazwy',
        ], ';');
        foreach ($rows as $c) {
            $snapshot = is_array($c->source_snapshot) ? $c->source_snapshot : [];
            fputcsv($handle, [
                $c->status,
                $c->matched_by,
                $c->matched_value,
                $c->matched_source_key,
                $c->brand,
                $c->hits,
                $c->positions,
                $c->source_product_id,
                $c->source?->sku ?? ($snapshot['sku'] ?? ''),
                $c->source?->name ?? ($snapshot['name'] ?? ''),
                $c->source?->manufacturer ?? ($snapshot['manufacturer'] ?? ''),
                $c->source?->purchase_price,
                $c->source?->currency,
                $c->target_product_id,
                $c->target?->sku,
                $c->target?->name,
                $c->target?->manufacturer,
                $c->target?->purchase_price,
                $c->target?->currency,
                implode(' ', (array) $c->conflict_product_ids),
                $c->reason,
                (string) ($c->kind ?? CardMatchCandidate::KIND_MERGE),
                self::signal($c) ?? '',
                self::planPositions($c),
                is_array($c->plan) && is_array($c->plan['suggested'] ?? null)
                    ? (string) ($c->plan['suggested']['common_name'] ?? '')
                    : '',
            ], ';');
        }
        fclose($handle);

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function cardLabel(?Product $card, ?array $snapshot): string
    {
        $sku = $card?->sku ?? ($snapshot['sku'] ?? '?');
        $name = $card?->name ?? ($snapshot['name'] ?? '');

        return '#'.($card?->id ?? '—').' ['.$sku.'] '.mb_substr((string) $name, 0, 40);
    }

    private static function kindLabel(CardMatchCandidate $c): string
    {
        return match ((string) ($c->kind ?? CardMatchCandidate::KIND_MERGE)) {
            CardMatchCandidate::KIND_MERGE => 'połącz',
            CardMatchCandidate::KIND_SIZE_MERGE => 'rozmiary',
            CardMatchCandidate::KIND_SPLIT => 'rozdzielanie',
            default => (string) $c->kind,
        };
    }

    private static function signal(CardMatchCandidate $c): ?string
    {
        return is_array($c->plan) && is_string($c->plan['signal'] ?? null) ? $c->plan['signal'] : null;
    }

    private static function signalLabel(?string $signal): string
    {
        return match ($signal) {
            null => '—',
            CardMatchCandidate::SIGNAL_SIZE => 'rozmiar',
            CardMatchCandidate::SIGNAL_COLOR => 'kolor',
            CardMatchCandidate::SIGNAL_UNKNOWN => 'nie wiadomo',
            default => $signal,
        };
    }

    /**
     * Plan „pozycja → karta” w jednej komórce: „6X00/S→#40819|6X00/M→#40820”. Pozycja bez kodu u dystrybutora —
     * klucz pozycji; trafiająca w kilka kart — „#a/#b”; bez karty — „—”.
     */
    private static function planPositions(CardMatchCandidate $c): string
    {
        if (! is_array($c->plan) || ! is_array($c->plan['positions'] ?? null)) {
            return '';
        }
        $parts = [];
        foreach ($c->plan['positions'] as $position) {
            if (! is_array($position)) {
                continue;
            }
            $code = trim((string) ($position['remote_sku'] ?? ''));
            if ($code === '') {
                $code = trim((string) ($position['position_key'] ?? ''));
            }
            if (isset($position['target_product_id'])) {
                $target = '#'.(int) $position['target_product_id'];
            } elseif (is_array($position['target_ids'] ?? null) && $position['target_ids'] !== []) {
                $target = implode('/', array_map(static fn (mixed $id): string => '#'.(int) $id, $position['target_ids']));
            } else {
                $target = '—';
            }
            $parts[] = $code.'→'.$target;
        }

        return implode('|', $parts);
    }

    private static function keyLabel(CardMatchCandidate $c): string
    {
        $label = $c->matched_by === CardMatchCandidate::BY_EAN ? 'EAN' : 'kod';

        return $label.' '.$c->matched_value.' ('.$c->matched_source_key.')';
    }

    private static function price(?Product $card): string
    {
        if ($card === null || $card->purchase_price === null) {
            return '—';
        }

        return number_format((float) $card->purchase_price, 2, ',', ' ').' '.($card->currency ?: 'PLN');
    }
}
