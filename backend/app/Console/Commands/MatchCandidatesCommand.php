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

    public function handle(CardMatchFinder $finder): int
    {
        $summary = $finder->refresh();

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
                ['Status', 'Karta dystrybutora', 'Karta producenta', 'Klucz', 'Marka', 'Trafione', 'Cena dystr.', 'Cena prod.', 'Powód'],
                $shown->map(fn (CardMatchCandidate $c): array => [
                    $c->status === CardMatchCandidate::STATUS_PENDING ? 'do decyzji' : 'niepewne',
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
