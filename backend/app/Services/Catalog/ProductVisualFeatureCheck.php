<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVisualCheck;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\PpeAssortment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Zabudowana pięta ze zdjęcia karty (decyzja właściciela z 25.09.2026): tylko gdy karta nie mówi o pięcie słowami, tylko
 * ze zdjęcia dostawcy albo producenta (zdjęcie z sieci bywa innym modelem — przełącznik), tylko model z obsługą obrazu
 * (zejście na konfigurację główną = nic nie zapisujemy). Wynik to wniosek ze zdjęcia, nie tekst karty — trafia do
 * osobnej tabeli, nigdy do opisu, norm ani parametrów karty.
 */
final class ProductVisualFeatureCheck
{
    public const PROMPT_VERSION = 'heel-2026-09-25';

    private const MAX_IMAGE_BYTES = 2_500_000;

    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * Co zrobiłaby ocena tej karty, bez wywołania modelu: powód pominięcia albo zdjęcie do oceny.
     *
     * @return array{skip: ?string, image: ?ProductImage}
     */
    public function plan(Product $product, bool $includeWebImages = false): array
    {
        $wording = $this->assortment->heelWording($product);
        if ($wording !== null) {
            return ['skip' => 'karta mówi o pięcie słowami ('.$wording.')', 'image' => null];
        }
        $image = $this->primaryImage($product);
        if ($image === null) {
            return ['skip' => 'brak zdjęcia głównego', 'image' => null];
        }
        if ($image->b2b_account_id === null && ! $includeWebImages) {
            return ['skip' => 'zdjęcie z sieci (bez --include-web)', 'image' => $image];
        }
        $path = (string) $image->path;
        if ($path === '' || $path === 'remote' || str_starts_with($path, 'http') || ! Storage::disk('public')->exists($path)) {
            return ['skip' => 'brak pliku zdjęcia na dysku', 'image' => $image];
        }
        $exists = ProductVisualCheck::query()
            ->where('product_id', $product->id)
            ->where('feature', ProductVisualCheck::FEATURE_CLOSED_HEEL)
            ->where('product_image_id', $image->id)
            ->exists();

        return ['skip' => $exists ? 'już ocenione dla tego zdjęcia' : null, 'image' => $image];
    }

    /**
     * Ocena zabudowanej pięty. Zwraca zapisany wiersz albo powód, dla którego nic nie zapisano.
     *
     * `attempted` = poszło zapytanie do modelu (komenda liczy nim --limit).
     *
     * @return array{check: ?ProductVisualCheck, skip: ?string, attempted: bool}
     */
    public function checkClosedHeel(Product $product, bool $includeWebImages = false): array
    {
        $plan = $this->plan($product, $includeWebImages);
        if ($plan['skip'] !== null || $plan['image'] === null) {
            return ['check' => null, 'skip' => $plan['skip'] ?? 'brak zdjęcia', 'attempted' => false];
        }
        $image = $plan['image'];
        $bytes = (string) Storage::disk('public')->get((string) $image->path);
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES || ! in_array($mime, self::IMAGE_MIME, true)) {
            return ['check' => null, 'skip' => 'zdjęcie w nieobsługiwanym formacie albo za duże', 'attempted' => false];
        }
        $size = @getimagesizefromstring($bytes);
        if (is_array($size) && ((int) $size[0] < 200 || (int) $size[1] < 200)) {
            return ['check' => null, 'skip' => 'zdjęcie mniejsze niż 200 px', 'attempted' => false];
        }

        $tally = app(AiServedProviderTally::class);
        $tally->forgetJsonOrigin();
        try {
            $raw = $this->llm->chatJson($this->messages($bytes, $mime), 0.0, 300, null, AiTask::ImageVerification);
        } catch (Throwable $e) {
            Log::warning('visual-check.failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);

            return ['check' => null, 'skip' => 'model nie odpowiedział', 'attempted' => true];
        }
        $origin = $tally->lastJsonOrigin();
        // Konfiguracja główna może nie widzieć obrazu i odpowiedzieć na ślepo — taki wniosek nie może trafić do bazy.
        if (($origin['fallback'] ?? false) === true) {
            return ['check' => null, 'skip' => 'odpowiedź z konfiguracji głównej, nie z modelu obrazu — bez zapisu', 'attempted' => true];
        }

        $heel = is_string($raw['heel'] ?? null) ? strtolower(trim($raw['heel'])) : null;
        $visible = filter_var($raw['shoe_visible'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        // Odpowiedź poza schematem nie zamyka zdjęcia na stałe jako „unclear” — następny przebieg spróbuje znowu.
        if ($visible === null || ! in_array($heel, ['closed', 'open', 'unclear'], true)) {
            return ['check' => null, 'skip' => 'odpowiedź modelu poza schematem — bez zapisu', 'attempted' => true];
        }
        $answer = $visible && $heel !== 'unclear' ? $heel : ProductVisualCheck::ANSWER_UNCLEAR;

        $check = ProductVisualCheck::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'feature' => ProductVisualCheck::FEATURE_CLOSED_HEEL,
                'product_image_id' => $image->id,
            ],
            [
                'answer' => $answer,
                'image_checksum' => is_string($image->checksum) && strlen($image->checksum) === 64 ? $image->checksum : hash('sha256', $bytes),
                'image_source_url' => $image->source_url !== null ? mb_substr((string) $image->source_url, 0, 1024) : null,
                'model' => $origin['model'] ?? null,
                'prompt_version' => self::PROMPT_VERSION,
                'what_seen' => mb_substr(trim((string) ($raw['what_i_see'] ?? '')), 0, 255) ?: null,
                'raw' => $raw,
            ],
        );

        return ['check' => $check, 'skip' => null, 'attempted' => true];
    }

    /** Zdjęcie główne karty (is_primary) — to samo, które wyszukiwarka łączy z oceną. */
    public function primaryImage(Product $product): ?ProductImage
    {
        return ProductImage::query()
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Bez treści wymagania i bez słowa „sandał”: pytanie o sam tył buta, żeby prompt nie podpowiadał odpowiedzi.
     *
     * @return list<array{role: string, content: mixed}>
     */
    private function messages(string $bytes, string $mime): array
    {
        return [
            [
                'role' => 'system',
                // Krótko: klient przycina długie teksty wiadomości przy ponowieniu po ucięciu — format jest w części użytkownika.
                'content' => 'Oceniasz zdjęcie obuwia ochronnego, wyłącznie tył buta (okolica pięty). Nie zgadujesz. '
                    .'Zwracasz wyłącznie JSON.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'closed = tył cholewki zamknięty, pięta otoczona zapiętkiem; open = pięta '
                        .'odsłonięta, z tyłu tylko pasek albo nic; unclear = tyłu nie widać, zdjęcie niewyraźne albo to nie but. '
                        .'JSON: {"shoe_visible":true,"heel":"closed|open|unclear","what_i_see":"najwyżej 20 słów"}'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'detail' => 'high']],
                ],
            ],
        ];
    }
}
