<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Przywraca powiązaniom B2B odcisk opisu karty, gdy przebieg zapisał opis, ale powiązania już nie (przerwany
 * zapis przed 17.09.2026 — karta i powiązanie szły osobno, więc błąd między nimi zostawiał stary hash;
 * od tej pory idą w jednej transakcji, zob. B2bCatalogSync::syncProduct).
 *
 * Skutek takiego rozjazdu jest trwały: karta z opisem, którego hash się nie zgadza, wygląda jak zmieniona
 * ręcznie, więc synchronizacja nigdy więcej jej opisu nie rusza (B2bCatalogSync::mayWriteDescription), a panel
 * nie liczy jej jako „opis z B2B”.
 *
 * Bierzemy tylko karty, których opis nosi ślad naszej synchronizacji (sekcje, których nikt nie pisze ręcznie)
 * i które w ogóle dostały kiedyś opis z tego konta (powiązanie ma hash). Opisu polecenie nie zmienia — ustawia
 * hash na opis, który karta ma teraz, i oddaje kartę kolejnemu przebiegowi: to on rozstrzyga, czy tekst
 * u dostawcy jest inny.
 */
final class B2bRelinkDescriptionsCommand extends Command
{
    /**
     * Sekcje, które do opisu dopisuje sama synchronizacja: nagłówek tekstu z karty technicznej (B2bCatalogSync),
     * jednostka sprzedaży i opis ze strony producenta (UVEX) oraz nagłówki zakładek JSP. Opis bez żadnej z nich
     * mógł powstać gdzie indziej — takiej karty nie ruszamy, bo przypisanie jej do B2B pozwoliłoby nadpisać
     * cudzy tekst.
     *
     * JSP nie pisze już „Jednostka: ” — jednostka sprzedaży i wagi przeniosły się do tabelki karty wyrobu
     * u dostawcy (product_shop_cards). Z opisów JSP zostają nagłówki zakładek, więc to one są tu znacznikiem.
     * „Jednostka: ” zostaje na liście, bo polecenie ogląda opisy już zapisane w bazie, a te z wcześniejszych
     * przebiegów nadal ten wiersz mają. Opis JSP złożony z samej prozy zakładki Overview nie ma znacznika i taka
     * karta zostaje pominięta — to świadomy wybór: lepiej nie naprawić kilku kart niż przypisać do B2B opis,
     * który ktoś mógł napisać ręcznie.
     */
    private const SYNC_MARKS = [
        'Z karty technicznej (',
        'Jednostka: ',
        'Opis ze strony producenta',
        'Cechy w skrócie:',
        'Cechy i zalety:',
        'W zestawie:',
    ];

    protected $signature = 'b2b:relink-descriptions
                            {--account= : Tylko to konto B2B (id)}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--limit=0 : Maksymalna liczba wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Przywraca powiązaniom B2B odcisk opisu karty po przerwanym zapisie, żeby synchronizacja znów mogła opis odświeżać';

    public function handle(): int
    {
        $accountId = trim((string) $this->option('account'));
        $accounts = B2bAccount::query()
            ->when($accountId !== '', static fn ($q) => $q->whereKey((int) $accountId))
            ->orderBy('id')
            ->get();
        if ($accounts->isEmpty()) {
            $this->error($accountId !== '' ? 'Nie ma konta B2B o id '.$accountId.'.' : 'Nie ma żadnego konta B2B.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $total = 0;
        $withoutMark = 0;

        foreach ($accounts as $account) {
            [$found, $skipped] = $this->candidates($account);
            $withoutMark += $skipped;
            if ($found === []) {
                $this->line(sprintf('Konto #%d %s: nie ma czego naprawiać.', $account->id, $account->username));

                continue;
            }

            $this->info(sprintf('Konto #%d %s — kart z rozjechanym odciskiem opisu: %d', $account->id, $account->username, count($found)));
            $rows = $limit > 0 ? array_slice($found, 0, $limit) : $found;
            $this->table(
                ['ID', 'SKU', 'Początek opisu'],
                array_map(static fn (array $row): array => [
                    $row['id'],
                    $row['sku'],
                    mb_substr((string) strtok($row['description'], "\n"), 0, 80),
                ], $rows),
            );

            if ($apply) {
                foreach ($found as $row) {
                    B2bProductLink::query()
                        ->where('b2b_account_id', $account->id)
                        ->where('product_id', $row['id'])
                        ->update(['description_hash' => sha1($row['description'])]);
                }
            }
            $total += count($found);
        }

        if ($withoutMark > 0) {
            $this->warn(sprintf('Pominięto %d kart bez śladu synchronizacji w opisie — te mogły zostać zmienione ręcznie.', $withoutMark));
        }
        if ($total === 0) {
            return self::SUCCESS;
        }
        $this->info($apply
            ? sprintf('Naprawiono %d kart — najbliższe pobranie cennika znów może odświeżyć ich opisy.', $total)
            : sprintf('Do naprawy: %d kart. Uruchom z --apply, żeby zapisać.', $total));

        return self::SUCCESS;
    }

    /**
     * Karty konta, których opis nie zgadza się z żadnym odciskiem powiązania.
     *
     * @return array{0: list<array{id: int, sku: string, description: string}>, 1: int} [do naprawy, pominięte bez śladu synchronizacji]
     */
    private function candidates(B2bAccount $account): array
    {
        /** @var array<int, list<string>> $hashes */
        $hashes = [];
        B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereNotNull('product_id')
            ->whereNotNull('description_hash')
            ->orderBy('id')
            ->chunk(1000, static function (Collection $links) use (&$hashes): void {
                foreach ($links as $link) {
                    $hashes[(int) $link->product_id][] = (string) $link->description_hash;
                }
            });
        if ($hashes === []) {
            return [[], 0];
        }

        $found = [];
        $skipped = 0;
        Product::query()
            ->whereIn('id', array_keys($hashes))
            ->orderBy('id')
            ->chunkById(500, function (Collection $products) use ($hashes, &$found, &$skipped): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $description = (string) $product->description;
                    if (trim($description) === '') {
                        continue;
                    }
                    $current = sha1($description);
                    foreach ($hashes[(int) $product->id] as $hash) {
                        if (hash_equals($hash, $current)) {
                            continue 2;
                        }
                    }
                    if (! self::writtenBySync($description)) {
                        $skipped++;

                        continue;
                    }
                    $found[] = ['id' => (int) $product->id, 'sku' => (string) $product->sku, 'description' => $description];
                }
            });

        return [$found, $skipped];
    }

    private static function writtenBySync(string $description): bool
    {
        foreach (self::SYNC_MARKS as $mark) {
            if (str_contains($description, $mark)) {
                return true;
            }
        }

        return false;
    }
}
