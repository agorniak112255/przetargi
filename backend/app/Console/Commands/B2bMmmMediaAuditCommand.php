<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\B2b\MmmB2bConnector;
use App\Services\B2b\MmmDocumentGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Zdjęcia i pliki, które konto 3M zapisało na kartach, zanim łącznik zaczął je odsiewać (audyt 08.10.2026). Łącznik
 * pilnuje już nowych przebiegów, ale synchronizacja tylko dokłada media — zastane wiersze zostają, dopóki ich nie
 * usunie ta komenda.
 *
 * Zdjęcia konta 3M:
 * - zaślepka 3M (MmmB2bConnector::isPlaceholderImageUrl — „Scott Spares and Accessories”, „Spare Part”) albo szara
 *   ikona pliku 240×175 z multimedia.3m.com → usunięte ze śladem odrzucenia (ProductImageRejection), żeby nic ich
 *   nie dołożyło z powrotem;
 * - plik na dysku, który jest stroną HTML/XML/SVG, nie obrazem („404 Resource not found” zapisane jako .jpg) →
 *   usunięty bez śladu odrzucenia: adres może kiedyś oddać prawdziwe zdjęcie, a stron i tak nic już nie zapisze.
 * Pliki konta 3M: materiał marketingowy albo plik innej pozycji 3M (MmmDocumentGate — te same reguły co w łączniku)
 * → wiersz usunięty (model sam zleca odświeżenie wektora karty, gdy plik niósł tekst).
 *
 * Bez --apply tylko liczy i pokazuje przykłady. Ruszane są wyłącznie wiersze z b2b_account_id konta 3M — zdjęcia
 * i pliki innych kont, z sieci i dodane ręcznie zostają. Pliki na dysku zostają (sprząta je products:media-report).
 */
final class B2bMmmMediaAuditCommand extends Command
{
    protected $signature = 'b2b:mmm-media-audit
                            {--apply : Usuń wskazane wiersze (bez tej flagi tylko raport)}
                            {--account= : Tylko to konto (domyślnie każde konto z łącznikiem 3m)}
                            {--show=15 : Ile przykładów wypisać w każdej grupie}';

    protected $description = 'Zaślepki, strony HTML i obce pliki zapisane przez konto 3M na kartach (usuwa tylko z --apply)';

    public function handle(): int
    {
        $accounts = B2bAccount::query()
            ->where('connector', MmmB2bConnector::key())
            ->when($this->option('account') !== null, fn ($q) => $q->whereKey((int) $this->option('account')))
            ->orderBy('id')
            ->get();
        if ($accounts->isEmpty()) {
            $this->warn('Brak konta z łącznikiem 3m.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        foreach ($accounts as $account) {
            $this->line('Konto #'.$account->id.' ('.$account->username.')');
            $this->audit($account, $apply);
        }
        if (! $apply) {
            $this->newLine();
            $this->warn('Raport — nic nie usunięto. Żeby usunąć te wiersze: php artisan b2b:mmm-media-audit --apply');
        }

        return self::SUCCESS;
    }

    private function audit(B2bAccount $account, bool $apply): void
    {
        $accountId = (int) $account->id;
        $show = max(0, (int) $this->option('show'));

        // zdjęcia
        $rejected = [];
        $markup = [];
        $images = ProductImage::query()->where('b2b_account_id', $accountId)->orderBy('id');
        foreach ($images->lazyById(500) as $image) {
            $why = $this->imageProblem($image);
            if ($why === 'markup') {
                $markup[] = $image;
            } elseif ($why !== null) {
                $rejected[] = [$image, $why];
            }
        }

        // pliki
        $documents = ProductDocument::query()->where('b2b_account_id', $accountId)->orderBy('id')->get();
        $productIds = $documents->pluck('product_id')->unique()->values()->all();
        $gate = new MmmDocumentGate($this->accountCodes($accountId));
        $codes = $this->cardCodes($accountId, $productIds);
        $foreignDocuments = [];
        foreach ($documents as $document) {
            $reason = $gate->rejection($codes[(int) $document->product_id] ?? [], (string) $document->title, (string) $document->source_url);
            if ($reason !== null) {
                $foreignDocuments[] = [$document, $reason];
            }
        }

        $this->line('  Zdjęcia konta: '.$images->count().'; zaślepki i ikony pliku: '.count($rejected).'; strony zamiast obrazu: '.count($markup));
        foreach (array_slice($rejected, 0, $show) as [$image, $why]) {
            $this->line('    #'.$image->product_id.'  '.$why.'  ←  '.basename((string) $image->source_url));
        }
        foreach (array_slice($markup, 0, $show) as $image) {
            $this->line('    #'.$image->product_id.'  strona zamiast obrazu  ←  '.basename((string) $image->source_url));
        }
        $this->line('  Pliki konta: '.$documents->count().'; do usunięcia: '.count($foreignDocuments));
        $byReason = [];
        foreach ($foreignDocuments as [, $reason]) {
            $key = (string) preg_replace('/ \(.*$/u', '', $reason);
            $byReason[$key] = ($byReason[$key] ?? 0) + 1;
        }
        foreach ($byReason as $reason => $count) {
            $this->line('    '.$reason.': '.$count);
        }
        foreach (array_slice($foreignDocuments, 0, $show) as [$document, $reason]) {
            $this->line('    #'.$document->product_id.'  „'.$document->title.'”  —  '.$reason);
        }

        $emptied = $this->cardsLeftWithoutImages([...array_column($rejected, 0), ...$markup]);
        if ($emptied !== []) {
            $this->line('  Karty, które zostaną bez zdjęcia: '.count($emptied).' (np. #'.implode(', #', array_slice($emptied, 0, 15)).')');
        }

        if (! $apply) {
            return;
        }
        foreach ($rejected as [$image]) {
            ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_AUDIT);
        }
        $touched = [];
        foreach ($markup as $image) {
            $image->delete();
            $touched[(int) $image->product_id] = true;
        }
        foreach (array_keys($touched) as $productId) {
            ProductImage::resequence($productId);
        }
        foreach ($foreignDocuments as [$document]) {
            $document->delete();
        }
        $this->info('  Usunięto: zdjęć '.(count($rejected) + count($markup)).', plików '.count($foreignDocuments)
            .'. Pliki na dysku zostały — miejsce odzyskuje products:media-report --apply.');
    }

    /** Powód usunięcia zdjęcia: opis zaślepki, 'markup' (strona zamiast obrazu) albo null = zdjęcie zostaje. */
    private function imageProblem(ProductImage $image): ?string
    {
        $url = (string) $image->source_url;
        if (MmmB2bConnector::isPlaceholderImageUrl($url)) {
            return 'zaślepka 3M';
        }
        $disk = Storage::disk('public');
        if (! $disk->exists((string) $image->path)) {
            return null;
        }
        $path = $disk->path((string) $image->path);
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        $start = strtolower(ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head));
        foreach (['<!doctype', '<html', '<head', '<body', '<?xml', '<svg'] as $tag) {
            if (str_starts_with($start, $tag)) {
                return 'markup';
            }
        }
        $info = @getimagesize($path);
        if (is_array($info) && str_contains($url, 'multimedia.3m.com') && MmmB2bConnector::isFileIcon($info)) {
            return 'ikona pliku 240×175';
        }

        return null;
    }

    /**
     * Kody wszystkich pozycji konta (numer katalogowy, kod z końca nazwy) — do rozpoznania pliku innej pozycji.
     *
     * @return list<string>
     */
    private function accountCodes(int $accountId): array
    {
        $codes = [];
        ProductIdentifier::query()
            ->where('b2b_account_id', $accountId)
            ->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)
            ->pluck('value')
            ->each(static function ($value) use (&$codes): void {
                array_push($codes, ...MmmDocumentGate::accountCodes((string) $value, ''));
            });
        B2bProductLink::query()
            ->where('b2b_account_id', $accountId)
            ->pluck('remote_name')
            ->each(static function ($name) use (&$codes): void {
                array_push($codes, ...MmmDocumentGate::accountCodes('', (string) $name));
            });

        return $codes;
    }

    /**
     * Kody każdej karty: SKU, numery 3M konta (wszystkie pozycje, także z kart scalonych), numery pozycji
     * z powiązań i nazwy — karty i ze źródła.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<string>>
     */
    private function cardCodes(int $accountId, array $productIds): array
    {
        $numbers = [];
        $names = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get(['id', 'sku', 'name']) as $product) {
                $numbers[$product->id][] = (string) $product->sku;
                $names[$product->id][] = (string) $product->name;
            }
            foreach (ProductIdentifier::query()->where('b2b_account_id', $accountId)->whereIn('product_id', $chunk)->get(['product_id', 'value']) as $row) {
                $numbers[$row->product_id][] = (string) $row->value;
            }
            foreach (B2bProductLink::query()->where('b2b_account_id', $accountId)->whereIn('product_id', $chunk)->get(['product_id', 'remote_id', 'remote_sku', 'remote_name']) as $link) {
                $numbers[$link->product_id][] = (string) $link->remote_id;
                $numbers[$link->product_id][] = (string) $link->remote_sku;
                $names[$link->product_id][] = (string) $link->remote_name;
            }
        }

        $out = [];
        foreach ($productIds as $productId) {
            $out[$productId] = MmmDocumentGate::cardCodes(
                array_values(array_filter($numbers[$productId] ?? [], static fn (string $v): bool => $v !== '')),
                array_values(array_filter($names[$productId] ?? [], static fn (string $v): bool => $v !== '')),
            );
        }

        return $out;
    }

    /**
     * Karty, na których po usunięciu nie zostanie żadne zdjęcie — człowiek ma to widzieć przed --apply.
     *
     * @param  list<ProductImage>  $removed
     * @return list<int>
     */
    private function cardsLeftWithoutImages(array $removed): array
    {
        $removedBy = [];
        foreach ($removed as $image) {
            $removedBy[(int) $image->product_id][] = (int) $image->id;
        }
        $emptied = [];
        foreach ($removedBy as $productId => $ids) {
            if (! ProductImage::query()->where('product_id', $productId)->whereNotIn('id', $ids)->exists()) {
                $emptied[] = $productId;
            }
        }
        sort($emptied);

        return $emptied;
    }
}
