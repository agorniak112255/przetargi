<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\NormListSanity;
use App\Services\Enrichment\SourceClaimGuard;
use App\Support\ProductDescriptionText;
use App\Support\PromptEcho;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pomiar M1 etapu 3 (plan z 08.10.2026, §5): które zapisane opisy i pola norm złapałyby nowe sprawdzenia tekstu
 * przed zapisem. Tylko odczyt — niczego nie zmienia w bazie; wynik na ekranie albo w CSV (--out).
 *
 * Flagi (jeden wiersz CSV na kartę i flagę):
 * - prompt_echo — zdanie opisu albo pozycja norm powtarza polecenie (PromptEcho);
 * - unfinished_tail — opis urwany; fragment = to, co obetnie ProductDescriptionText::withoutUnfinishedTail;
 * - paragraph_lowercase_start — wiersz albo zdanie zaczyna się małą literą (hipoteza H2: wycięty wiersz „nazwa + wymiar”);
 * - norms_invalid — NormListSanity odrzuciłoby albo poprawiło pozycję norm, albo zdanie opisu (13999 + litera,
 *   EN ISO 374-1 z typem sprzecznym z literami).
 *
 * Kolumna opis_z: „wzbogacanie” (payload ze źródłami — opis napisał model, przez te sprawdzenia przejdzie przy
 * następnym pobraniu) albo „inny” (opis z B2B, importu, ręczny — nowe sprawdzenia go nie dotyczą). Próbkę M1 do progu
 * fałszywych trafień bierzemy z „wzbogacania”.
 */
final class AuditDescriptionTextCommand extends Command
{
    public const FLAGS = ['prompt_echo', 'unfinished_tail', 'paragraph_lowercase_start', 'norms_invalid'];

    /** Tyle wierszy pokazujemy na ekranie bez --out; pełna lista idzie do pliku. */
    private const SCREEN_ROWS = 200;

    protected $signature = 'products:audit-description-text
                            {--price-list= : Numer cennika — karty z tego importu}
                            {--manufacturer= : Producent kart}
                            {--all : Wszystkie karty z opisem albo normami}
                            {--out= : Zapisz wiersze do pliku CSV (ścieżka względna od katalogu backend)}';

    protected $description = 'Pomiar M1: opisy z echem polecenia, urwanym końcem, akapitem od małej litery i błędnym polem norm (tylko odczyt)';

    public function handle(): int
    {
        $ids = null;
        $priceListId = (int) $this->option('price-list');
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($priceListId <= 0 && $manufacturer === '' && ! $this->option('all')) {
            $this->error('Podaj --price-list=<numer>, --manufacturer=<nazwa> albo --all.');

            return self::FAILURE;
        }
        if ($priceListId > 0) {
            $list = PriceList::query()->find($priceListId);
            if ($list === null) {
                $this->error("Nie ma cennika numer {$priceListId}.");

                return self::FAILURE;
            }
            $ids = array_values(array_unique(array_map('intval', $list->product_ids ?? [])));
            if ($ids === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import) — użyj --manufacturer=.');

                return self::FAILURE;
            }
        }

        $handle = null;
        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            $path = $this->absolute($out);
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $handle = @fopen($path, 'wb');
            if ($handle === false) {
                $this->error("Nie da się zapisać pliku: {$path}");

                return self::FAILURE;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'sku', 'producent', 'flaga', 'fragment', 'opis_z'], ';');
            $out = $path;
        }

        $counts = array_fill_keys(self::FLAGS, 0);
        $stats = ['cards' => 0, 'flagged' => 0, 'paragraphs' => 0, 'lowercase_paragraphs' => 0];
        $shown = 0;
        Product::query()
            ->select(['id', 'sku', 'manufacturer', 'description', 'norms', 'enrichment_payload'])
            ->when($ids !== null, static fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === null && $manufacturer !== '', static fn ($q) => $q->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]))
            ->where(static fn ($q) => $q->where('description', '!=', '')->orWhere('norms', '!=', ''))
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($handle, &$counts, &$stats, &$shown): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $stats['cards']++;
                    $findings = $this->inspect($product, $stats);
                    if ($findings !== []) {
                        $stats['flagged']++;
                    }
                    foreach ($findings as $flag => $fragment) {
                        $counts[$flag]++;
                        $row = [(string) $product->id, (string) $product->sku, (string) $product->manufacturer, $flag, $fragment, $this->descriptionOrigin($product)];
                        if ($handle !== null) {
                            fputcsv($handle, $row, ';');
                        } elseif ($shown < self::SCREEN_ROWS) {
                            $this->line(implode(' | ', $row));
                            $shown++;
                        }
                    }
                }
            });
        if ($handle !== null) {
            fclose($handle);
            $this->info('Zapisano: '.$out);
        }

        $this->newLine();
        $this->table(['Flaga', 'Kart'], array_map(static fn (string $flag): array => [$flag, $counts[$flag]], self::FLAGS));
        $share = $stats['paragraphs'] > 0 ? number_format(100 * $stats['lowercase_paragraphs'] / $stats['paragraphs'], 2, ',', '') : '0,00';
        $this->info("Kart: {$stats['cards']}, z flagą: {$stats['flagged']}. Akapity od małej litery (H2): {$stats['lowercase_paragraphs']} z {$stats['paragraphs']} ({$share}%).");

        return self::SUCCESS;
    }

    /**
     * @param  array{cards: int, flagged: int, paragraphs: int, lowercase_paragraphs: int}  $stats
     * @return array<string, string> flaga => fragment
     */
    private function inspect(Product $product, array &$stats): array
    {
        $description = trim((string) $product->description);
        $findings = [];
        $echo = [];
        $normProblems = [];
        if ($description !== '') {
            $echo = SourceClaimGuard::filterSentences($description, static fn (string $s): array => PromptEcho::isEcho($s) ? ['echo'] : [])['dropped'];
            $normProblems = SourceClaimGuard::filterSentences($description, NormListSanity::sentenceProblems(...))['dropped'];

            $tail = ProductDescriptionText::withoutUnfinishedTail($description)['cut'];
            if ($tail !== '') {
                $findings['unfinished_tail'] = $this->fragment($tail, true);
            }

            $lowercase = [];
            foreach (preg_split('/\n[ \t]*\n\s*/u', $description) ?: [] as $paragraph) {
                $stats['paragraphs']++;
                if (preg_match('/^\p{Ll}/u', trim($paragraph)) === 1) {
                    $stats['lowercase_paragraphs']++;
                }
            }
            foreach (preg_split('/\R/u', $description) ?: [] as $line) {
                $line = trim($line);
                if (preg_match('/^\p{Ll}/u', $line) === 1 && preg_match('#^https?://#iu', $line) !== 1) {
                    $lowercase[] = $line;
                }
                // „…First Aid Station. o symbolu 51011011…” — wiersz sklejony z następnym; „np. rękawice” to skrót
                // (wspólna lista ProductDescriptionText::ABBREVIATIONS)
                if (preg_match_all('/(?<=[.!?])\s+\p{Ll}\S*/u', $line, $m, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($m[0] as [$hit, $offset]) {
                        $before = substr($line, 0, $offset);
                        $afterAbbreviation = str_ends_with($before, '.') && ProductDescriptionText::endsWithAbbreviation(substr($before, 0, -1));
                        if (! $afterAbbreviation && preg_match('/\d\.$/u', $before) !== 1) {
                            // przesunięcie jest w bajtach — mb_strcut nie przetnie polskiej litery
                            $lowercase[] = mb_substr(mb_strcut($line, max(0, $offset - 60)), 0, 160);
                        }
                    }
                }
            }
            if ($lowercase !== []) {
                $findings['paragraph_lowercase_start'] = $this->fragment(implode(' | ', $lowercase));
            }
        }

        $items = $this->normItems($product);
        $echo = array_merge($echo, array_map(static fn (string $i): string => 'pole norm: '.$i, array_values(array_filter($items, PromptEcho::isEcho(...)))));
        if ($echo !== []) {
            $findings['prompt_echo'] = $this->fragment(implode(' | ', $echo));
        }
        $clean = NormListSanity::clean(array_values(array_filter($items, static fn (string $i): bool => ! PromptEcho::isEcho($i))));
        $normProblems = array_merge($normProblems, array_map(static fn (string $d): string => 'pole norm: '.$d, [...$clean['dropped'], ...$clean['fixed']]));
        if ($normProblems !== []) {
            $findings['norms_invalid'] = $this->fragment(implode(' | ', $normProblems));
        }

        return $findings;
    }

    /**
     * Pozycje norm karty: lista z opisu (enrichment_payload.norms) i attributes.normy_en; bez nich kolumna norms
     * (pierwsze 8 pozycji po przecinku — przecinek w nawiasie nie dzieli pozycji).
     *
     * @return list<string>
     */
    private function normItems(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $items = [];
        foreach ([$payload['norms'] ?? null, ($payload['attributes'] ?? [])['normy_en'] ?? null] as $list) {
            if (is_array($list)) {
                foreach ($list as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $items[] = trim($item);
                    }
                }
            }
        }
        if ($items === [] && trim((string) $product->norms) !== '') {
            $items = array_values(array_filter(
                array_map('trim', preg_split('/,(?![^()]*\))/u', (string) $product->norms) ?: []),
                static fn (string $i): bool => $i !== ''
            ));
        }

        return array_values(array_unique($items));
    }

    private function descriptionOrigin(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return isset($payload['source_urls']) || isset($payload['primary_source_kind']) ? 'wzbogacanie' : 'inny';
    }

    private function fragment(string $text, bool $tail = false): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= 300) {
            return $text;
        }

        return $tail ? '…'.mb_substr($text, -299) : mb_substr($text, 0, 299).'…';
    }

    private function absolute(string $path): string
    {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1 ? $path : base_path($path);
    }
}
