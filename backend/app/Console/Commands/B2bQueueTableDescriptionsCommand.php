<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

/**
 * Karty, w których opis to w praktyce sama tabelka ze sklepu B2B (pomiar 17.09.2026: 2818 kart — Protekt 1588,
 * SignProject 874, UVEX 299, JSP 43, Anro 13, Bollé 1). Od etapu 1 dane tabelaryczne mają własną tabelę
 * (product_shop_cards) i łączniki przestały je wklejać do opisu, ale opisy zapisane wcześniej zostały.
 *
 * Decyzja użytkownika 17.09.2026: takich opisów nie kasujemy — karta bez opisu wypada z propozycji przetargowych
 * (bramka Product::hasDescriptionText w ProductMatchService) — tylko zlecamy im uzupełnianie AI z potwierdzonym
 * nadpisaniem opisu z B2B, żeby tabelka ustąpiła miejsca prawdziwemu opisowi. Wdrażamy dostawca po dostawcy
 * (--account=), bez --apply tylko podgląd.
 */
final class B2bQueueTableDescriptionsCommand extends Command
{
    /**
     * Nagłówki sekcji, które łączniki dopisywały do opisu przed rozdzieleniem danych od opisu (etap 1) — ślad po
     * tamtym etapie, nie opis samego wyrobu. Po nagłówku zaczyna się tabelka, więc proza karty to tekst przed
     * pierwszym z nich. Lista jest zamknięta i historyczna: nowe przebiegi już nic takiego do opisu nie piszą.
     *
     * @var array<string, list<string>> klucz łącznika konta => nagłówki, które ten łącznik pisał do opisu
     */
    private const TABLE_SECTION_MARKERS = [
        'anro' => ['Parametry:'],
        'uvex' => ['Dane techniczne:', 'Parametry:', 'Jednostka: '],
        'jsp' => ['Wagi i wymiary:', 'Jednostka: '],
        'bolle' => ['Parametry:'],
        'protekt' => ['Normy:', 'Specyfikacja techniczna:'],
        'signproject' => ['Kategoria:', 'Dostępne formaty:', 'Podłoża:', 'Wersje:', 'Opis z danych katalogu SignProject'],
    ];

    private const SAMPLE_ROWS = 15;

    protected $signature = 'b2b:queue-table-descriptions
        {--account= : Jedno konto B2B — id albo klucz łącznika (anro, signproject, jsp, bolle, uvex, protekt)}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}
        {--apply : Zleć uzupełnianie (bez tej flagi tylko podgląd)}';

    protected $description = 'Zleca uzupełnianie AI kartom, których opis to sama tabelka ze sklepu B2B (podgląd bez --apply)';

    public function handle(ProductEnrichmentService $enrichment, AiSettingsService $settings): int
    {
        $accountIds = $this->resolveAccountIds();
        if ($accountIds === null) {
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $rows = $this->collect($accountIds, $limit);
        if ($rows === []) {
            $this->info('Brak kart z opisem-tabelką dla tego filtra.');

            return self::SUCCESS;
        }

        // „manual” to świadoma decyzja człowieka (karta ma być opisana ręcznie) — nie ruszamy jej wcale
        $manual = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === Product::ENRICHMENT_MANUAL));
        $queueable = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== Product::ENRICHMENT_MANUAL));
        $done = array_values(array_filter($queueable, static fn (array $r): bool => $r['status'] === Product::ENRICHMENT_DONE));

        $perAccount = [];
        foreach ($queueable as $row) {
            $perAccount[$row['account']] = ($perAccount[$row['account']] ?? 0) + 1;
        }
        ksort($perAccount);
        $this->table(
            ['Konto', 'Kart'],
            array_map(static fn (string $label, int $count): array => [$label, $count], array_keys($perAccount), array_values($perAccount)),
        );
        $this->table(
            ['ID', 'SKU', 'Konto', 'Status'],
            array_map(
                static fn (array $r): array => [$r['id'], $r['sku'], $r['account'], $r['status']],
                array_slice($queueable, 0, self::SAMPLE_ROWS),
            ),
        );

        if ($queueable === []) {
            $this->info(sprintf('Wszystkie %d kart z opisem-tabelką mają status „manual” — nic do zlecenia.', count($manual)));

            return self::SUCCESS;
        }

        $batchSize = $settings->enrichmentBatchLimit();
        $batches = (int) ceil(count($queueable) / $batchSize);
        $this->info(sprintf(
            'Do uzupełnienia opisu: %d kart — %d partii po %d (limit z Ustawień AI).',
            count($queueable),
            $batches,
            $batchSize,
        ));
        $this->line(sprintf('W tym ze statusem „done”: %d — %s status na „none”, inaczej kolejka by je pominęła.', count($done), $this->option('apply') ? 'ustawiam' : 'ustawię'));
        $this->line(sprintf('Pominięte karty ze statusem „manual”: %d (opis ręczny zostaje).', count($manual)));

        if (! $this->option('apply')) {
            $this->line('Podgląd — uruchom z --apply, żeby zlecić uzupełnianie.');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if (! $user instanceof User) {
            return self::FAILURE;
        }

        $doneIds = array_map(static fn (array $r): int => $r['id'], $done);
        foreach (array_chunk($doneIds, 500) as $chunk) {
            Product::query()->whereIn('id', $chunk)->update(['enrichment_status' => Product::ENRICHMENT_NONE]);
        }

        $ids = array_map(static fn (array $r): int => $r['id'], $queueable);
        $queued = 0;
        $batchIds = [];
        foreach (array_chunk($ids, $batchSize) as $chunk) {
            try {
                // potwierdzenie nadpisania opisu z B2B — to samo, co przy pojedynczej karcie z panelu
                $result = $enrichment->enqueueProductIds($chunk, $user, false, overwriteB2bDescription: true);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());

                continue;
            }
            $queued += count($result['product_ids']);
            $batchIds[] = (int) $result['batch']->id;
        }
        $this->info(sprintf('Zlecono uzupełnianie opisu: %d kart w %d partiach (#%s).', $queued, count($batchIds), implode(', #', $batchIds)));

        return self::SUCCESS;
    }

    /**
     * Karty do zlecenia. Warunki są zabezpieczeniami — każdy odpowiada za co innego:
     * 1. powiązanie konta B2B ma description_hash równy sha1 obecnego opisu — opis pochodzi z synchronizacji
     *    i nikt (ani człowiek, ani AI) go od tamtej pory nie zmienił;
     * 2. karta ma rekord w product_shop_cards dla tego samego konta — tabelka ze sklepu jest już zapisana osobno,
     *    więc nadpisanie opisu niczego nie niszczy;
     * 3. source_description_hash jest puste — karty z tłumaczonym opisem (Bollé, UVEX) pomijamy, bo nadpisanie
     *    zmarnowałoby tłumaczenie;
     * 4. proza opisu (tekst przed pierwszym nagłówkiem tabelki) nie jest opisem w rozumieniu karty — czyli po
     *    odjęciu tabelki nic sensownego nie zostaje.
     *
     * @param  list<int>  $accountIds  puste = wszystkie konta
     * @return list<array{id: int, sku: string, account: string, status: string}>
     */
    private function collect(array $accountIds, int $limit): array
    {
        $accounts = B2bAccount::query()
            ->when($accountIds !== [], static fn ($q) => $q->whereIn('id', $accountIds))
            ->get(['id', 'username', 'connector', 'sites']);
        $registry = app(B2bConnectorRegistry::class);
        /** @var array<int, array{key: string, label: string}> $meta */
        $meta = [];
        foreach ($accounts as $account) {
            $key = trim((string) $account->connector);
            if ($key === '') {
                $key = (string) $registry->keyForSites($account->sites ?? []);
            }
            $meta[(int) $account->id] = [
                'key' => $key,
                'label' => ($registry->label($key) ?? $key ?: '?').' ('.$account->username.')',
            ];
        }

        $rows = [];
        $seen = [];
        B2bProductLink::query()
            ->whereIn('b2b_account_id', array_keys($meta))
            ->whereNotNull('description_hash')
            ->where('description_hash', '!=', '')
            ->where(static fn ($q) => $q->whereNull('source_description_hash')->orWhere('source_description_hash', ''))
            ->orderBy('id')
            ->chunkById(500, function (Collection $links) use (&$rows, &$seen, $meta, $limit): bool {
                $productIds = $links->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
                $products = Product::query()->whereIn('id', $productIds)->get(['id', 'sku', 'description', 'enrichment_status'])->keyBy('id');
                $cards = [];
                foreach (ProductShopCard::query()->whereIn('product_id', $productIds)->get(['product_id', 'b2b_account_id']) as $card) {
                    $cards[(int) $card->product_id.':'.(int) $card->b2b_account_id] = true;
                }
                foreach ($links as $link) {
                    $productId = (int) $link->product_id;
                    $accountId = (int) $link->b2b_account_id;
                    if (isset($seen[$productId]) || ! isset($cards[$productId.':'.$accountId])) {
                        continue;
                    }
                    $product = $products->get($productId);
                    if (! $product instanceof Product) {
                        continue;
                    }
                    $description = (string) $product->description;
                    if ($description === '' || sha1($description) !== (string) $link->description_hash) {
                        continue;
                    }
                    $markers = self::TABLE_SECTION_MARKERS[$meta[$accountId]['key']] ?? [];
                    if ($markers === [] || Product::isDescriptionText($this->prose($description, $markers))) {
                        continue;
                    }
                    $seen[$productId] = true;
                    $rows[] = [
                        'id' => $productId,
                        'sku' => (string) $product->sku,
                        'account' => $meta[$accountId]['label'],
                        'status' => (string) $product->enrichment_status,
                    ];
                    if ($limit > 0 && count($rows) >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $rows;
    }

    /**
     * Tekst przed pierwszym nagłówkiem sekcji tabelarycznej — to, co z opisu zostaje po odjęciu tabelki.
     * Nagłówek liczy się tylko na początku wiersza: łączniki pisały sekcje osobnymi wierszami.
     *
     * @param  list<string>  $markers
     */
    private function prose(string $description, array $markers): string
    {
        $lines = preg_split('/\R/u', $description) ?: [];
        $prose = [];
        foreach ($lines as $line) {
            foreach ($markers as $marker) {
                if (str_starts_with(ltrim($line), $marker)) {
                    return trim(implode("\n", $prose));
                }
            }
            $prose[] = $line;
        }

        return trim(implode("\n", $prose));
    }

    /**
     * @return list<int>|null null = błąd (komunikat już wypisany); puste = wszystkie konta
     */
    private function resolveAccountIds(): ?array
    {
        $account = trim((string) $this->option('account'));
        if ($account === '') {
            return [];
        }

        $query = B2bAccount::query();
        if (ctype_digit($account)) {
            $query->where('id', (int) $account);
        } else {
            $query->where('connector', $account);
        }
        $ids = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            $this->error("Nie ma konta B2B „{$account}” — podaj id konta albo klucz łącznika (".implode(', ', app(B2bConnectorRegistry::class)->keys()).').');

            return null;
        }

        return $ids;
    }

    private function resolveUser(): ?User
    {
        $email = trim((string) $this->option('user'));
        try {
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $user = null;
        }
        if (! $user instanceof User) {
            $this->error($email !== '' ? "Nie ma użytkownika {$email}." : 'Nie ma administratora, na którego można zlecić partie — podaj --user=.');
        }

        return $user instanceof User ? $user : null;
    }
}
