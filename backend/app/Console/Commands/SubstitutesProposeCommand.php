<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Substitutes\SubstituteMatcher;
use App\Services\Substitutes\SubstituteProfiler;
use App\Services\Substitutes\SubstituteProposalService;
use Illuminate\Console\Command;

/**
 * Propozycje zamienników z katalogu: karty główne największych producentów i do każdej kilka kart innych producentów,
 * które spełniają wprost każdy parametr ochronny karty głównej. Rodzinę podaje się jawnie (--family): obuwie,
 * rękawice powlekane, półmaski FFP, ochronniki słuchu — pierwszą partię małą (--max-pairs) i ocenioną przez człowieka
 * (SubstituteProfiler::DEFAULT_FAMILIES). Bez --write tylko pokazuje, co by zapisało. Zapisane wiersze mają status
 * „oczekuje” — decyduje człowiek.
 */
class SubstitutesProposeCommand extends Command
{
    protected $signature = 'substitutes:propose
        {--write : Zapisz propozycje (bez tej opcji — tylko podgląd)}
        {--manufacturers=12 : Ilu największych producentów}
        {--per-manufacturer=10 : Ile kart głównych na producenta}
        {--per-main=3 : Ile zamienników na kartę główną}
        {--family=* : Rodziny: footwear, gloves, respiratory, hearing (wymagane, dopóki żadna nie przeszła pełnego audytu)}
        {--max-pairs=0 : Najwyżej tyle par w przebiegu (0 = bez limitu) — na pierwszą, małą partię nowej rodziny}
        {--only=* : Zawęź do producentów (nazwa jak w karcie)}
        {--details : Pokaż parametry każdej pary}';

    protected $description = 'Proponuje zamienniki innych producentów dla kart głównych największych producentów (bez --write tylko podgląd)';

    public function handle(SubstituteProposalService $service): int
    {
        $families = array_values(array_filter((array) $this->option('family')));
        $families = $families !== [] ? $families : SubstituteProfiler::DEFAULT_FAMILIES;
        if ($families === []) {
            $this->error('Podaj rodzinę: --family=footwear (albo gloves, respiratory, hearing). Pierwsza partia mała, np. --max-pairs=40, '
                .'i oceniona na ekranie Zamienniki, zanim zapiszesz całość.');

            return self::FAILURE;
        }
        $unknown = array_diff($families, SubstituteProfiler::FAMILIES);
        if ($unknown !== []) {
            $this->error('Nieznane rodziny: '.implode(', ', $unknown).'. Dostępne: '.implode(', ', SubstituteProfiler::FAMILIES));

            return self::FAILURE;
        }
        $write = (bool) $this->option('write');
        $started = microtime(true);
        $this->info(($write ? 'Liczę propozycje i zapisuję…' : 'Podgląd (bez zapisu) — liczę propozycje…').' Rodziny: '.implode(', ', $families));

        $plan = $service->plan([
            'manufacturers' => max(1, (int) $this->option('manufacturers')),
            'per_manufacturer' => max(1, (int) $this->option('per-manufacturer')),
            'per_main' => max(1, min(6, (int) $this->option('per-main'))),
            'families' => $families,
            'max_pairs' => max(0, (int) $this->option('max-pairs')),
            'only' => array_values(array_filter((array) $this->option('only'))),
        ], function (string $brand, string $main, int $picked): void {
            $this->line("  {$brand}: {$main} — {$picked}");
        });

        $this->newLine();
        $this->line('Karty w rodzinach automatu: '.$plan['profiles']);
        $this->table(['Producent', 'Karty', 'Karty główne z zamiennikami'], array_map(
            static fn (array $b): array => [$b['name'], $b['cards'], count(array_filter($plan['mains'], static fn (array $m): bool => $m['brand'] === $b['name']))],
            $plan['brands'],
        ));

        $rows = [];
        $pairs = 0;
        foreach ($plan['mains'] as $main) {
            $mainProduct = Product::query()->find($main['main_id'], ['id', 'sku', 'name', 'manufacturer']);
            foreach ($main['picks'] as $pick) {
                $pairs++;
                $sub = Product::query()->find($pick['product_id'], ['id', 'sku', 'name', 'manufacturer']);
                $rows[] = [
                    "#{$mainProduct?->id} {$mainProduct?->manufacturer} ".mb_strimwidth((string) $mainProduct?->name, 0, 50, '…'),
                    "#{$sub?->id} {$sub?->manufacturer} ".mb_strimwidth((string) $sub?->name, 0, 50, '…'),
                    $pick['result']['verdict'],
                    $this->option('details') ? $this->paramsLine($pick['result']['params']) : count($pick['result']['params']).' param.',
                ];
            }
        }
        $this->table(['Karta główna', 'Zamiennik', 'Typ', 'Parametry'], $rows);
        $this->line('Karty główne: '.count($plan['mains']).", pary: {$pairs}");
        $this->line('Najczęstsze powody odrzucenia kandydata: '.implode('; ', array_map(
            static fn (string $reason, int $count): string => "{$reason} ({$count})",
            array_keys(array_slice($plan['rejections'], 0, 8, true)),
            array_slice($plan['rejections'], 0, 8, true),
        )));

        $this->line('Karty producentów pominięte jako główne: '.implode('; ', array_map(
            static fn (string $reason, int $count): string => "{$reason} ({$count})",
            array_keys($plan['skipped_mains']),
            $plan['skipped_mains'],
        )));

        if (! $write) {
            $this->warn('Nic nie zapisano. Zapis: dodaj --write.');
            $this->line(sprintf('Czas: %.1f s, pamięć: %d MB', microtime(true) - $started, (int) (memory_get_peak_usage(true) / 1048576)));

            return self::SUCCESS;
        }

        $stats = $service->write($plan['mains']);
        $this->info(sprintf(
            'Zapisano: nowe %d, odświeżone %d, zatwierdzone (nowe dowody) %d; pominięte: odrzucone %d, ręczne %d. Stare propozycje: nadal aktualne %d, usunięte %d, oznaczone jako nieaktualne %d.',
            $stats['created'], $stats['refreshed'], $stats['kept_decided'], $stats['skipped_rejected'], $stats['skipped_manual'],
            $stats['rechecked_ok'], $stats['stale_removed'], $stats['stale_marked'],
        ));
        $this->line(sprintf('Czas: %.1f s, pamięć: %d MB, reguły: %s (%s)', microtime(true) - $started, (int) (memory_get_peak_usage(true) / 1048576), SubstituteMatcher::RULES_VERSION, substr(SubstituteProposalService::rulesHash(), 0, 8)));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $params
     */
    private function paramsLine(array $params): string
    {
        return implode(' · ', array_map(
            static fn (array $p): string => "{$p['label']}: {$p['main']['text']} → {$p['sub']['text']} ({$p['relation']})",
            $params,
        ));
    }
}
