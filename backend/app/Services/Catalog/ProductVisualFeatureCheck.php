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
 *
 * Dwa niezależne pytania o tył buta (budowa tyłu; budowa tyłu i pasek za piętą) — „zabudowana” tylko, gdy oba to
 * potwierdzają. Pomiar 25.09.2026 na 14 zdjęciach z oceną wzorcową (model deepseek-v4-flash): jedno pytanie
 * „closed/open” myliło 4 z 6 klapków z paskiem za piętą (ARTRA ART 702 Air, ARVA 6017) na „zabudowana”; każde
 * z dwóch nowych pytań myliło po jednym, każde inny — razem 0 groźnych pomyłek, 2 karty bez wniosku.
 */
final class ProductVisualFeatureCheck
{
    public const PROMPT_VERSION = ProductVisualCheck::CURRENT_PROMPT_VERSION;

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
        // Ocena starszą wersją pytania nie liczy się — przebieg ocenia zdjęcie od nowa (ten sam wiersz, nadpisany).
        $exists = ProductVisualCheck::query()
            ->where('product_id', $product->id)
            ->where('feature', ProductVisualCheck::FEATURE_CLOSED_HEEL)
            ->where('product_image_id', $image->id)
            ->where('prompt_version', self::PROMPT_VERSION)
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

        $answers = [];
        $model = null;
        foreach (['back' => $this->backQuestion(), 'strap' => $this->strapQuestion()] as $key => $question) {
            $tally = app(AiServedProviderTally::class);
            $tally->forgetJsonOrigin();
            try {
                $answers[$key] = $this->llm->chatJson($this->messages($question, $bytes, $mime), 0.0, 400, null, AiTask::ImageVerification);
            } catch (Throwable $e) {
                Log::warning('visual-check.failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);

                return ['check' => null, 'skip' => 'model nie odpowiedział', 'attempted' => true];
            }
            $origin = $tally->lastJsonOrigin();
            // Konfiguracja główna może nie widzieć obrazu i odpowiedzieć na ślepo — taki wniosek nie może trafić do bazy.
            if (($origin['fallback'] ?? false) === true) {
                return ['check' => null, 'skip' => 'odpowiedź z konfiguracji głównej, nie z modelu obrazu — bez zapisu', 'attempted' => true];
            }
            $model ??= $origin['model'] ?? null;
        }

        $answer = $this->decide($answers['back'], $answers['strap']);
        if ($answer === null) {
            // Odpowiedź poza schematem nie zamyka zdjęcia na stałe jako „unclear” — następny przebieg spróbuje znowu.
            return ['check' => null, 'skip' => 'odpowiedź modelu poza schematem — bez zapisu', 'attempted' => true];
        }
        $raw = ['back_question' => $answers['back'], 'strap_question' => $answers['strap']];

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
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'what_seen' => mb_substr(trim((string) ($answers['strap']['what_i_see'] ?? $answers['back']['what_i_see'] ?? '')), 0, 255) ?: null,
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
     * closed tylko, gdy oba pytania widzą pełny tył i drugie nie widzi paska za piętą; open, gdy oba widzą pasek albo
     * brak tyłu; w każdym innym razie unclear (bez wniosku w wyszukiwarce). null = odpowiedź poza schematem.
     *
     * @param  array<string, mixed>  $back
     * @param  array<string, mixed>  $strap
     */
    public function decide(array $back, array $strap): ?string
    {
        $shapes = [];
        foreach ([$back, $strap] as $raw) {
            $shape = is_string($raw['back'] ?? null) ? strtolower(trim($raw['back'])) : null;
            $visible = filter_var($raw['shoe_visible'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($visible === null || ! in_array($shape, ['full', 'strap', 'none', 'not_visible'], true)) {
                return null;
            }
            if (! $visible) {
                return ProductVisualCheck::ANSWER_UNCLEAR;
            }
            $shapes[] = $shape;
        }
        $strapSeen = filter_var($strap['strap_behind_heel'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($strapSeen === null) {
            return null;
        }
        if ($shapes === ['full', 'full'] && ! $strapSeen) {
            return ProductVisualCheck::ANSWER_CLOSED;
        }
        $open = ['strap', 'none'];
        if (in_array($shapes[0], $open, true) && in_array($shapes[1], $open, true)) {
            return ProductVisualCheck::ANSWER_OPEN;
        }

        return ProductVisualCheck::ANSWER_UNCLEAR;
    }

    private function backQuestion(): string
    {
        return 'Najpierw opisz tył buta, potem wybierz back. back: "full" = materiał cholewki (skóra, tkanina) ciągnie się z boków '
            .'i zamyka tył buta wokół pięty, jak w półbucie; "strap" = z tyłu jest tylko pasek (z klamrą, rzepem albo elastyczny) '
            .'przechodzący za piętą, a między nim a podeszwą widać pustą przestrzeń lub piętę; "none" = z tyłu nie ma nic, pięta '
            .'leży na podeszwie odsłonięta (klapek, drewniak); "not_visible" = tyłu nie widać. Pasek za piętą NIE jest zamkniętym '
            .'tyłem. JSON: {"what_i_see":"najwyżej 25 słów o tyle buta","back":"full|strap|none|not_visible","shoe_visible":true}';
    }

    private function strapQuestion(): string
    {
        return 'Najpierw opisz tył buta, potem odpowiedz. back: "full" = materiał cholewki (skóra, tkanina) ciągnie się z boków '
            .'i zamyka tył buta wokół pięty, jak w półbucie; "strap" = za piętą przechodzi tylko pasek (z klamrą, rzepem albo '
            .'elastyczny), a między nim a podeszwą jest pusta przestrzeń lub widać piętę; "none" = z tyłu nie ma nic, pięta leży '
            .'na podeszwie odsłonięta (klapek, drewniak); "not_visible" = tyłu nie widać. strap_behind_heel: true, gdy za piętą '
            .'widać pasek z klamrą, rzepem albo zapięciem (sama pętla do zakładania buta to nie pasek). Pasek za piętą NIE jest '
            .'zamkniętym tyłem. JSON: {"what_i_see":"najwyżej 25 słów o tyle buta","back":"full|strap|none|not_visible",'
            .'"strap_behind_heel":false,"shoe_visible":true}';
    }

    /**
     * Bez treści wymagania i bez słowa „sandał”: pytanie o sam tył buta, żeby prompt nie podpowiadał odpowiedzi. Prompt
     * systemowy krótki — klient przycina długie teksty wiadomości przy ponowieniu po ucięciu; pytanie jest w części
     * użytkownika.
     *
     * @return list<array{role: string, content: mixed}>
     */
    private function messages(string $question, string $bytes, string $mime): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'Oceniasz zdjęcie obuwia ochronnego. Patrzysz tylko na to, co jest bezpośrednio za piętą. '
                    .'Nie zgadujesz. Zwracasz wyłącznie JSON.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $question],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'detail' => 'high']],
                ],
            ],
        ];
    }
}
