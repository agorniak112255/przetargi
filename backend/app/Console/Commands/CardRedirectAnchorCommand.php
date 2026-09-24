<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CardRedirect;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Services\Catalog\CardRedirectStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Zmiana pozycji wiodącej karty modelu z łączenia rozmiarów (card_redirects, powód „size_merge”, is_anchor). Z pozycji
 * wiodącej synchronizacja konta odświeża cenę, opis, zdjęcia i tabelkę karty; pozostałe rozmiary odświeżają tylko swoje
 * powiązania. Gdy dostawca wycofa pozycję wiodącą (np. 3M przestanie podawać 6100 S na karcie #40819), przebieg ostrzega,
 * ale sam jej nie zamienia — inny rozmiar może mieć inną cenę albo opis, więc wybór należy do człowieka.
 *
 * Przykład: php artisan card-redirects:anchor 40819 7000146847 --source=b2b:3 --apply
 * Bez --apply tylko podgląd. Wpis w dzienniku aktywności: card_redirect.anchor_changed.
 */
final class CardRedirectAnchorCommand extends Command
{
    protected $signature = 'card-redirects:anchor
                            {product : Id karty modelu}
                            {position : Kod pozycji (position_key), która ma być wiodąca}
                            {--source= : Źródło b2b:{id} — wymagane, gdy karta ma pozycje łączenia rozmiarów kilku kont}
                            {--apply : Zapisz zmianę (bez tej flagi tylko podgląd)}';

    protected $description = 'Zmienia pozycję wiodącą karty modelu z łączenia rozmiarów (podgląd bez --apply)';

    public function handle(ActivityLogger $activity): int
    {
        $productId = filter_var($this->argument('product'), FILTER_VALIDATE_INT);
        if ($productId === false || $productId <= 0) {
            $this->error('Podaj id karty liczbą, np. card-redirects:anchor 40819 7000146847.');

            return self::FAILURE;
        }
        $position = trim((string) $this->argument('position'));
        $source = trim((string) ($this->option('source') ?? ''));

        $rows = CardRedirect::query()
            ->where('product_id', $productId)
            ->where('reason', CardRedirect::REASON_SIZE_MERGE)
            ->orderBy('source_key')
            ->orderBy('position_key')
            ->get();
        if ($rows->isEmpty()) {
            $this->error('Karta #'.$productId.' nie ma w mapie połączeń pozycji z łączenia rozmiarów.');

            return self::FAILURE;
        }

        $sources = $rows->pluck('source_key')->map(static fn ($key): string => (string) $key)->unique()->values()->all();
        if ($source === '') {
            if (count($sources) > 1) {
                $this->error('Karta #'.$productId.' ma pozycje łączenia rozmiarów kilku źródeł ('.implode(', ', $sources)
                    .') — podaj --source=.');

                return self::FAILURE;
            }
            $source = $sources[0];
        } elseif (! in_array($source, $sources, true)) {
            $this->error('Karta #'.$productId.' nie ma pozycji łączenia rozmiarów źródła '.$source
                .' (są: '.implode(', ', $sources).').');

            return self::FAILURE;
        }

        $rows = $rows->filter(static fn (CardRedirect $row): bool => (string) $row->source_key === $source)->values();
        $wanted = CardRedirectStore::key($source, $position);
        $chosen = $rows->first(static fn (CardRedirect $row): bool => CardRedirectStore::key($source, (string) $row->position_key) === $wanted);
        if ($position === '' || $chosen === null) {
            $this->error('Pozycja „'.$position.'” nie należy do karty #'.$productId.' w źródle '.$source
                .' (pozycje: '.$rows->pluck('position_key')->implode(', ').').');

            return self::FAILURE;
        }

        $product = Product::query()->find($productId, ['id', 'sku', 'name']);
        $oldAnchors = $rows->filter(static fn (CardRedirect $row): bool => (bool) $row->is_anchor)
            ->pluck('position_key')->map(static fn ($key): string => (string) $key)->values()->all();

        $this->info(sprintf('Karta #%d %s — źródło %s', $productId, $product !== null ? '('.$product->sku.')' : '(karty już nie ma)', $source));
        $this->table(
            ['Pozycja', 'Etykieta', 'Wiodąca teraz', 'Wiodąca po zmianie'],
            $rows->map(static fn (CardRedirect $row): array => [
                (string) $row->position_key,
                (string) ($row->position_label ?? ''),
                $row->is_anchor ? 'tak' : '',
                (int) $row->id === (int) $chosen->id ? 'tak' : '',
            ])->all(),
        );

        if ($oldAnchors === [(string) $chosen->position_key]) {
            $this->info('Pozycja '.$chosen->position_key.' już jest wiodąca — nic do zmiany.');

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->warn('Podgląd — nic nie zapisano. Dodaj --apply, żeby zmienić pozycję wiodącą.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $chosen, $activity, $productId, $product, $source, $oldAnchors): void {
            CardRedirect::query()
                ->whereIn('id', $rows->pluck('id')->all())
                ->where('id', '!=', $chosen->id)
                ->update(['is_anchor' => false]);
            CardRedirect::query()->whereKey($chosen->id)->update(['is_anchor' => true]);

            $activity->log(
                action: 'card_redirect.anchor_changed',
                user: null,
                subject: $product,
                meta: [
                    'label' => sprintf(
                        'Zmiana pozycji wiodącej karty modelu #%d%s (%s): %s → %s',
                        $productId,
                        $product !== null ? ' '.$product->sku : '',
                        $source,
                        $oldAnchors === [] ? '—' : implode(', ', $oldAnchors),
                        (string) $chosen->position_key,
                    ),
                    'product_id' => $productId,
                    'sku' => $product?->sku,
                    'source_key' => $source,
                    'old_position' => $oldAnchors[0] ?? null,
                    'old_positions' => $oldAnchors,
                    'new_position' => (string) $chosen->position_key,
                ],
            );
        });

        $this->info('Pozycja wiodąca: '.$chosen->position_key.' (poprzednio: '.($oldAnchors === [] ? '—' : implode(', ', $oldAnchors)).').'
            .' Działa od następnej synchronizacji konta.');

        return self::SUCCESS;
    }
}
