<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\B2b\AnsellAssetBankClient;
use App\Services\B2b\B2bFatalException;
use App\Services\Enrichment\AnsellAssetBankImagePicker;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListCards;
use Closure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Packshoty z portalu zdjęć Ansella (assetbank.ansell.com, konto B2B dystrybutora) na karty cennika Ansella.
 *
 * Prośba użytkownika 06.10.2026: ansell.com zasłania pliki zaporą, a sklepy dają zdjęcia różnej jakości — portal ma
 * oficjalne packshoty z modelem, regionem i prawami użycia. Zdjęcie trafia na kartę jako zdjęcie konta (b2b_account_id)
 * i staje się główne (ProductImage::resequence z tym kontem); zdjęcia ze sklepów zostają za nim. Kart nie zakłada,
 * cen nie dotyka. Jedno wyszukiwanie na model i jedno pobranie na plik w przebiegu — warianty rozmiaru tego samego
 * modelu dostają ten sam plik. Bez --force karta, która ma już zdjęcie z tego konta, jest pomijana.
 */
final class AnsellAssetBankImagesCommand extends Command
{
    protected $signature = 'products:ansell-assetbank-images
                            {--account= : Konto B2B portalu (domyślnie konto z adresem assetbank.ansell.com)}
                            {--price-list=5 : Cennik, którego karty uzupełniamy}
                            {--id=* : Tylko te karty}
                            {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
                            {--force : Także karty, które mają już zdjęcie z portalu}
                            {--dry-run : Tylko pokaż wybrane pliki — bez pobierania i zapisu}';

    protected $description = 'Packshoty z portalu zdjęć Ansella (Asset Bank) na karty cennika Ansella';

    /** @var Closure(B2bAccount): AnsellAssetBankClient|null — w testach klient z atrapą */
    public static ?Closure $clientFactory = null;

    public function handle(AnsellAssetBankImagePicker $picker, ProductImageDownloader $images, PriceListCards $cards): int
    {
        $account = $this->option('account') !== null
            ? B2bAccount::query()->find((int) $this->option('account'))
            : B2bAccount::query()->get()->first(
                static fn (B2bAccount $a): bool => collect((array) $a->sites)->contains(fn ($s): bool => str_contains((string) $s, AnsellAssetBankClient::HOST))
            );
        if ($account === null) {
            $this->error('Brak konta B2B portalu '.AnsellAssetBankClient::HOST.' — dodaj je w Cenniki → B2B.');

            return self::FAILURE;
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        if ($ids === []) {
            $list = PriceList::query()->find((int) $this->option('price-list'));
            if ($list === null) {
                $this->error('Nie ma cennika #'.$this->option('price-list'));

                return self::FAILURE;
            }
            $ids = $cards->ids($list);
        }
        $query = Product::query()->whereKey($ids)->orderBy('id');
        if (! $this->option('force')) {
            $query->whereDoesntHave('images', static fn ($q) => $q->where('b2b_account_id', $account->id));
        }
        $limit = max(0, (int) $this->option('limit'));
        $products = $limit > 0 ? $query->limit($limit)->get() : $query->get();
        $dryRun = (bool) $this->option('dry-run');

        $client = self::$clientFactory !== null
            ? (self::$clientFactory)($account)
            : new AnsellAssetBankClient((string) $account->username, (string) $account->password);

        $searches = [];
        $rights = [];
        // pobrane pliki na dysku, nie w pamięci — CLI na serwerze ma 128 MB, a plików jest kilkaset po ~300 KB
        $files = [];
        $counts = ['saved' => 0, 'found' => 0, 'none' => 0, 'no_model' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($products as $product) {
            $target = $picker->target($product);
            if ($target === null) {
                $counts['no_model']++;
                $this->line("  #{$product->id} {$product->sku}: bez modelu do wyszukania w portalu");

                continue;
            }
            try {
                $candidates = [];
                foreach ($target['needles'] as $needle) {
                    $searches[$needle] ??= $client->search($needle);
                    $candidates = $picker->ranked($product, $searches[$needle]);
                    if ($candidates !== []) {
                        break;
                    }
                }
                $chosen = null;
                foreach ($candidates as $candidate) {
                    $rights[$candidate['id']] ??= $client->assetDetails($candidate['id'])['rights'];
                    if (AnsellAssetBankImagePicker::rightsAllowed($rights[$candidate['id']])) {
                        $chosen = $candidate;

                        break;
                    }
                }
                if ($chosen === null) {
                    $counts['none']++;
                    $this->line("  #{$product->id} {$product->sku} [{$target['label']}]: brak packshotu w portalu");

                    continue;
                }
                $url = AnsellAssetBankClient::assetUrl($chosen['id']);
                if ($dryRun) {
                    $counts['found']++;
                    $this->line("  #{$product->id} {$product->sku} [{$target['label']}]: {$chosen['title']} (#{$chosen['id']}, ".($rights[$chosen['id']] ?: 'prawa nieznane').')');

                    continue;
                }
                if (ProductImageRejection::blocksUrl((int) $product->id, $url)
                    || ProductImage::query()->where('product_id', $product->id)->where('source_url', $url)->exists()) {
                    $counts['skipped']++;
                    $this->line("  #{$product->id} {$product->sku}: plik #{$chosen['id']} już był na karcie albo został usunięty");

                    continue;
                }
                if (! isset($files[$chosen['id']])) {
                    $bytes = $client->downloadJpg($chosen['id']);
                    $path = (string) tempnam(sys_get_temp_dir(), 'assetbank');
                    file_put_contents($path, $bytes);
                    $files[$chosen['id']] = $path;
                }
                $image = $images->storeBytes($product, (string) file_get_contents($files[$chosen['id']]), 'image/jpeg', $url, 0, (int) $account->id);
                if ($image === null) {
                    $counts['skipped']++;

                    continue;
                }
                ProductImage::resequence((int) $product->id, (int) $account->id);
                $counts['saved']++;
                $this->line("  #{$product->id} {$product->sku}: {$chosen['title']}");
            } catch (B2bFatalException $e) {
                $this->error($e->getMessage());
                self::removeFiles($files);

                return self::FAILURE;
            } catch (Throwable $e) {
                $counts['error']++;
                $this->warn("  #{$product->id} {$product->sku}: ".$e->getMessage());
            }
        }
        self::removeFiles($files);

        $this->info(sprintf(
            'Kart %d: %s %d, brak packshotu %d, bez modelu %d, pominięte %d, błędy %d.',
            $products->count(),
            $dryRun ? 'znaleziono' : 'zapisano',
            $dryRun ? $counts['found'] : $counts['saved'],
            $counts['none'],
            $counts['no_model'],
            $counts['skipped'],
            $counts['error'],
        ));

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $files */
    private static function removeFiles(array $files): void
    {
        foreach ($files as $path) {
            @unlink($path);
        }
    }
}
