<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Norms\ManufacturerNormIdentity;
use App\Services\Norms\ManufacturerNormReaders;
use App\Services\Norms\ManufacturerPageFinder;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use Illuminate\Console\Command;
use JsonException;

/**
 * Normy producenta z karty wyrobu na jego stronie WWW — bez modelu i bez ruszania opisu ani innych pól karty (plan norm
 * z 23.09.2026, etap 3). Strona musi przejść bramkę tożsamości: dokładny kod wyrobu albo EAN na stronie
 * (ManufacturerNormIdentity) — sama nazwa i producent nie wystarczą — a strona rodziny z kilkoma kodami EN 388 odpada.
 *
 * Podgląd (domyślnie) chodzi po witrynach producenta i zapisuje plik planu: dla każdej karty adres, dowód bramki,
 * czytnik, dosłowny fragment strony i jego sumę. `--apply=<plan>` zapisuje dokładnie to, co było w podglądzie — bez
 * ponownego pobierania — i pomija kartę, której normy producenta zmieniły się od podglądu (synchronizacja B2B albo
 * wzbogacanie w międzyczasie). Przed zapisem kopia poprzednich wartości; `--restore=<kopia>` je przywraca.
 */
final class NormsFromManufacturerPagesCommand extends Command
{
    protected $signature = 'norms:from-manufacturer-pages
        {--manufacturer= : Producent dokładnie jak na karcie (wymagany przy podglądzie), np. Canis}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--all : Także karty, które mają już kod EN 388 (domyślnie tylko karty z EN 388 bez kodu)}
        {--plan= : Plik planu zapisywany przez podgląd (domyślnie storage/app/norms-plans/<producent>-<data>.json)}
        {--apply= : Zapisz normy z tego pliku planu}
        {--backup= : Plik kopii przy --apply (domyślnie storage/app/repair-backups/norms-from-pages-<data>.json)}
        {--restore= : Przywróć normy producenta z pliku kopii i zakończ}';

    protected $description = 'Normy producenta ze strony WWW producenta, po bramce dokładnego kodu (podgląd zapisuje plan; --apply=plan)';

    private const MENTIONS_EN388 = '/EN\s?(ISO\s?)?388(?!\d)/iu';

    public function handle(
        ManufacturerNormIdentity $identity,
        ManufacturerPageFinder $finder,
        ManufacturerNormReaders $readers,
        ProductPageFetcher $pages,
        BhpAttributeNormalizer $normalizer,
    ): int {
        ini_set('memory_limit', '2048M');
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }
        $apply = trim((string) $this->option('apply'));
        if ($apply !== '') {
            return $this->apply($apply);
        }

        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer === '') {
            $this->error('Podaj --manufacturer= (podgląd chodzi po witrynie producenta, więc jednym producentem naraz).');

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $delayUs = max(0, (int) config('norms.host_delay_ms', 1500)) * 1000;
        $planPath = trim((string) $this->option('plan'))
            ?: storage_path('app/norms-plans/'.preg_replace('/[^a-z0-9]+/i', '-', $manufacturer).'-'.now()->format('Ymd-His').'.json');

        $entries = [];
        $report = [];
        $checked = 0;
        foreach (Product::query()->where('manufacturer', $manufacturer)->orderBy('id')->cursor() as $product) {
            /** @var Product $product */
            if (! $this->isCandidate($product, $normalizer)) {
                continue;
            }
            if ($limit > 0 && $checked >= $limit) {
                break;
            }
            $checked++;
            $result = $this->readCard($product, $identity, $finder, $readers, $pages, $delayUs);
            $report[$result['status']] = ($report[$result['status']] ?? 0) + 1;
            $entries[] = $result;
            $this->line(sprintf('#%d %s — %s%s', $product->id, $product->sku, $result['status'], isset($result['url']) ? ' ('.$result['url'].')' : ''));
        }

        $error = $this->writeJson($planPath, ['manufacturer' => $manufacturer, 'created_at' => now()->toIso8601String(), 'entries' => $entries]);
        if ($error !== null) {
            $this->error("Nie zapisano planu ({$error}).");

            return self::FAILURE;
        }
        arsort($report);
        $this->table(['wynik', 'kart'], array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($report), $report));
        foreach ($entries as $entry) {
            if ($entry['status'] === 'do zapisu') {
                $rows = array_map(
                    static fn (array $r): string => $r['label'].($r['value'] !== '' ? ': '.$r['value'] : ''),
                    ManufacturerNormFacts::rows($entry['column']),
                );
                $this->line("  #{$entry['product_id']} {$entry['url']} [{$entry['column']['source']['identity']['by']} {$entry['column']['source']['identity']['value']}]: ".implode(' | ', $rows));
            }
        }
        $this->info("Plan zapisany: {$planPath}");
        $this->line('Zapis: --apply="'.$planPath.'"');

        return self::SUCCESS;
    }

    /**
     * Karta ŚOI bez norm producenta z łącznika B2B i bez sprawdzonych norm ze strony; domyślnie tylko ta, która wspomina
     * EN 388 bez żadnego kodu (--all zdejmuje ten warunek).
     */
    private function isCandidate(Product $product, BhpAttributeNormalizer $normalizer): bool
    {
        $stored = $product->manufacturer_norms;
        if (ManufacturerNormFacts::rows($stored) !== []
            && (($stored['source']['connector'] ?? null) !== ManufacturerNormFacts::WEB_PAGE_CONNECTOR || ManufacturerNormFacts::verified($stored))) {
            return false;
        }
        $attrs = $normalizer->forProduct($product);
        if (in_array($attrs['kategoria_bhp'] ?? null, [null, 'inne'], true)) {
            return false;
        }
        if ((bool) $this->option('all')) {
            return true;
        }
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $texts = implode("\n", [(string) $product->description, (string) $product->shop_fields_summary,
            implode('; ', array_map('strval', (array) ($payload['norms'] ?? []))), (string) $product->norms]);

        return preg_match(self::MENTIONS_EN388, $texts) === 1 && ($attrs['poziomy_en388'] ?? null) === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readCard(
        Product $product,
        ManufacturerNormIdentity $identity,
        ManufacturerPageFinder $finder,
        ManufacturerNormReaders $readers,
        ProductPageFetcher $pages,
        int $delayUs,
    ): array {
        $base = ['product_id' => (int) $product->id, 'sku' => (string) $product->sku,
            'current_sha1' => sha1(json_encode($product->manufacturer_norms) ?: '')];
        $codes = $identity->codesFor($product);
        if ($codes === []) {
            return $base + ['status' => 'brak kodu producenta'];
        }
        $candidates = $finder->candidates($product, $codes);
        if ($candidates === []) {
            return $base + ['status' => 'brak strony producenta'];
        }
        $rejections = [];
        foreach ($candidates as $candidate) {
            usleep($delayUs);
            $raw = $pages->fetchRaw($candidate['url']);
            if ($raw === null) {
                $rejections[] = $candidate['url'].': nie pobrano';

                continue;
            }
            $confirmed = $identity->confirm($product, $raw['final_url'], $raw['html'], $codes);
            if ($confirmed === null) {
                $rejections[] = $candidate['url'].': bramka — brak kodu wyrobu na stronie';

                continue;
            }
            foreach ($readers->for($raw['final_url']) as $reader) {
                $reading = $reader->read($raw['html'], $raw['final_url']);
                if ($reading === null || $reading->rows === []) {
                    continue;
                }
                if ($identity->familyConflict($reading->block)) {
                    $rejections[] = $candidate['url'].': strona rodziny — kilka kodów EN 388';

                    continue 2;
                }
                $column = ManufacturerNormFacts::build(
                    $reading->rows,
                    ManufacturerNormFacts::WEB_PAGE_CONNECTOR,
                    (string) $product->manufacturer,
                    $candidate['url'],
                    null,
                    [
                        'kind' => 'page',
                        'final_url' => $raw['final_url'],
                        'found_via' => $candidate['via'],
                        'reader' => $reading->reader,
                        'identity' => $confirmed,
                        'block' => mb_substr($reading->block, 0, 1000),
                        'block_sha256' => hash('sha256', $reading->block),
                    ],
                );
                if ($column === null) {
                    continue;
                }
                $stored = $product->manufacturer_norms;
                if (ManufacturerNormFacts::rows($stored) !== [] && ! ManufacturerNormFacts::verified($stored)
                    && ManufacturerNormFacts::rows($stored) != ManufacturerNormFacts::rows($column)) {
                    // Normy ze strony zapisane przy wzbogacaniu (bez bramki) i inne niż odczyt sprawdzony — do przejrzenia.
                    return $base + ['status' => 'sprzeczne z normami strony z wzbogacania', 'url' => $candidate['url'], 'column' => $column];
                }

                return $base + ['status' => 'do zapisu', 'url' => $candidate['url'], 'column' => $column];
            }
            $rejections[] = $candidate['url'].': strona bez norm w odczytywalnej postaci';
        }

        return $base + ['status' => 'odrzucone', 'why' => $rejections];
    }

    private function apply(string $planPath): int
    {
        $plan = $this->readJson($planPath);
        if (! is_array($plan)) {
            $this->error("Nie odczytano planu: {$planPath}");

            return self::FAILURE;
        }
        $toWrite = [];
        $skipped = 0;
        foreach ((array) ($plan['entries'] ?? []) as $entry) {
            if (($entry['status'] ?? null) !== 'do zapisu' || ! is_array($entry['column'] ?? null)) {
                continue;
            }
            $product = Product::query()->find((int) $entry['product_id']);
            if ($product === null || sha1(json_encode($product->manufacturer_norms) ?: '') !== ($entry['current_sha1'] ?? null)
                || ! ManufacturerNormFacts::replaceableFromWebPage($product->manufacturer_norms, true)) {
                $this->warn("#{$entry['product_id']}: normy producenta zmieniły się od podglądu — pomijam");
                $skipped++;

                continue;
            }
            $toWrite[] = [$product, $entry['column']];
        }
        if ($toWrite === []) {
            $this->info('Nic do zapisania'.($skipped > 0 ? " (pominięte: {$skipped})" : '').'.');

            return self::SUCCESS;
        }
        $backupPath = trim((string) $this->option('backup'))
            ?: storage_path('app/repair-backups/norms-from-pages-'.now()->format('Ymd-His').'.json');
        $backup = [];
        foreach ($toWrite as [$product]) {
            $backup[(string) $product->id] = $product->manufacturer_norms;
        }
        $error = $this->writeJson($backupPath, ['created_at' => now()->toIso8601String(), 'manufacturer_norms' => $backup]);
        if ($error !== null) {
            $this->error("Nie zapisano kopii zapasowej ({$error}) — nic nie zmieniono.");

            return self::FAILURE;
        }
        foreach ($toWrite as [$product, $column]) {
            $product->manufacturer_norms = $column;
            $product->save();
        }
        $this->info('Zapisano normy producenta na '.count($toWrite).' kartach'.($skipped > 0 ? ", pominięte: {$skipped}" : '').'. Kopia: '.$backupPath);
        $this->line('Przywrócenie stanu sprzed: --restore="'.$backupPath.'"');

        return self::SUCCESS;
    }

    private function restore(string $path): int
    {
        $data = $this->readJson($path);
        if (! is_array($data)) {
            $this->error("Nie odczytano kopii zapasowej: {$path}");

            return self::FAILURE;
        }
        $restored = 0;
        foreach ((array) ($data['manufacturer_norms'] ?? []) as $id => $column) {
            $product = Product::query()->find((int) $id);
            if ($product === null) {
                continue;
            }
            $product->manufacturer_norms = is_array($column) ? $column : null;
            $product->save();
            $restored++;
        }
        $this->info("Przywrócono normy producenta na {$restored} kartach z kopii {$path}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return file_put_contents($path, $json) === false ? "nie można zapisać {$path}" : null;
    }

    private function readJson(string $path): mixed
    {
        if (! is_file($path)) {
            return null;
        }
        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
