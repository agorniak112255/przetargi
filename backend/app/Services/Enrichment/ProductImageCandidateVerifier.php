<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductImageCandidateVerifier
{
    private const MAX_AI_CANDIDATES = 4;

    private const MAX_IMAGE_BYTES = 2_500_000;

    private const MIN_CONFIDENCE = 0.85;

    private const VISION_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    /**
     * Najpierw zachowuje kandydatów jednoznacznych po SKU lub wskazanych przez
     * Product.image/og:image dopasowanej karty. Pozostałe ocenia AI Vision.
     *
     * @param  list<string>  $urls
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<string>  $trustedUrls
     * @return list<string>
     */
    public function select(
        Product $product,
        array $urls,
        array $pages,
        int $max = 1,
        array $trustedUrls = [],
    ): array {
        $max = max(1, min(5, $max));
        $urls = array_values(array_unique(array_filter(
            $urls,
            static fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'http')
        )));
        $trusted = [];
        foreach ($trustedUrls as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $trusted[mb_strtolower($url)] = true;
            }
        }

        $selected = [];
        $unverified = [];
        foreach ($urls as $url) {
            if ($this->identity->imageUrlMentionsForeignBrand($url, $product)
                || $this->identity->imageUrlHasForeignType($url, $product)) {
                continue;
            }
            if ($this->identity->imageUrlMentionsProduct($url, $product)) {
                $selected[] = $url;
                if (count($selected) >= $max) {
                    return array_slice($selected, 0, $max);
                }

                continue;
            }
            if (! $this->isPotentialProductImage($url)) {
                continue;
            }
            // og:image kolekcji (GRZMOT) bez typu w URL — Vision, nie ślepe zaufanie
            if (isset($trusted[mb_strtolower($url)]) && ! $this->identity->nameRequiresArticleType($product)) {
                $selected[] = $url;
                if (count($selected) >= $max) {
                    return array_slice($selected, 0, $max);
                }

                continue;
            }
            $unverified[] = $url;
        }

        usort(
            $unverified,
            fn (string $a, string $b): int => $this->layoutPenalty($a) <=> $this->layoutPenalty($b)
        );

        $loaded = [];
        foreach (array_slice($unverified, 0, self::MAX_AI_CANDIDATES) as $url) {
            $image = $this->loadForVision($url);
            if ($image !== null) {
                $loaded[] = $image + ['url' => $url];
            }
        }
        if ($loaded === []) {
            return $this->finishSelection($selected, $trusted, $urls, $max, $product);
        }

        try {
            $response = $this->llm->chatJsonWithImages(
                $this->verificationPrompt($product, $pages, count($loaded)),
                array_map(
                    static fn (array $image): array => [
                        'bytes' => $image['bytes'],
                        'mime' => $image['mime'],
                        'label' => $image['url'],
                    ],
                    $loaded
                ),
                AiTask::ImageVerification
            );
        } catch (Throwable $e) {
            Log::warning('Product image AI verification failed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $e->getMessage(),
            ]);

            return $this->finishSelection($selected, $trusted, $urls, $max, $product);
        }

        $verified = [];
        $rows = is_array($response['candidates'] ?? null) ? $response['candidates'] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $index = filter_var($row['index'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || ! isset($loaded[$index])) {
                continue;
            }
            $confidence = (float) ($row['confidence'] ?? 0);
            if ($confidence > 1) {
                $confidence /= 100;
            }
            if (($row['is_relevant_product'] ?? false) !== true
                || ($row['is_logo_or_banner'] ?? false) === true
                || $confidence < self::MIN_CONFIDENCE) {
                continue;
            }
            $verified[] = [
                'url' => $loaded[$index]['url'],
                'confidence' => $confidence,
            ];
        }

        usort(
            $verified,
            static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']
        );
        foreach ($verified as $candidate) {
            $selected[] = $candidate['url'];
            if (count($selected) >= $max) {
                break;
            }
        }

        Log::info('Product image AI verification completed', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'checked' => count($loaded),
            'accepted' => array_column($verified, 'url'),
        ]);

        return $this->finishSelection($selected, $trusted, $urls, $max, $product);
    }

    /**
     * @param  list<string>  $selected
     * @param  array<string, true>  $trusted
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function finishSelection(array $selected, array $trusted, array $urls, int $max, Product $product): array
    {
        $selected = array_values(array_filter(
            $selected,
            fn (string $url): bool => ! $this->identity->imageUrlMentionsForeignBrand($url, $product)
                && ! $this->identity->imageUrlHasForeignType($url, $product)
        ));
        if ($selected !== []) {
            return array_values(array_unique(array_slice($selected, 0, $max)));
        }
        foreach ($urls as $url) {
            if (isset($trusted[mb_strtolower($url)]) && $this->isPotentialProductImage($url)
                && ! $this->identity->imageUrlMentionsForeignBrand($url, $product)
                && ! $this->identity->imageUrlHasForeignType($url, $product)
                && ! $this->identity->nameRequiresArticleType($product)) {
                return [$url];
            }
        }

        return [];
    }

    private function isPotentialProductImage(string $url): bool
    {
        if (! ProductImageDownloader::looksLikeImageUrl($url)) {
            return false;
        }

        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return preg_match(
            '/(?:^|[\/_.-])(logo|icon|sprite|favicon|banner|newsletter|payment|shipping|avatar|flag|menu)(?:[\/_.-]|$)/u',
            $path
        ) !== 1;
    }

    /**
     * Grafiki nawigacji („/imgs/icons/menu-slab/ppe.webp”) przechodzą filtr nazw,
     * a zajmują miejsca w limicie kandydatów dla Vision — idą więc na koniec kolejki.
     */
    private function layoutPenalty(string $url): int
    {
        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return preg_match(
            '#(?:^|[/_.-])(icons?|menu|nav|header|footer|banners?|badges?|thumbs?|sprites?|social)(?:[/_.-]|$)#u',
            $path
        ) === 1 ? 1 : 0;
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    private function loadForVision(string $url): ?array
    {
        try {
            $response = Http::timeout(12)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-ImageVerifier/1.0)',
                    'Accept' => 'image/jpeg,image/png,image/webp,image/*;q=0.8',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($url);
            if (! $response->successful()) {
                return null;
            }

            $bytes = $response->body();
            if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                return null;
            }
            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0] ?? ''));
            if (! in_array($mime, self::VISION_MIME, true)) {
                $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
            }
            if (! in_array($mime, self::VISION_MIME, true)) {
                return null;
            }

            $dimensions = @getimagesizefromstring($bytes);
            if (is_array($dimensions)) {
                $width = (int) ($dimensions[0] ?? 0);
                $height = (int) ($dimensions[1] ?? 0);
                if ($width < 200 || $height < 200 || $width > $height * 5 || $height > $width * 5) {
                    return null;
                }
            }

            return ['bytes' => $bytes, 'mime' => $mime];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     */
    private function verificationPrompt(Product $product, array $pages, int $count): string
    {
        $context = [];
        foreach (array_slice($pages, 0, 4) as $page) {
            $context[] = 'URL: '.mb_substr((string) ($page['url'] ?? ''), 0, 500)
                ."\nTreść: ".mb_substr((string) ($page['text'] ?? ''), 0, 1000);
        }
        $countMinusOne = $count - 1;
        $typeLine = $this->identity->requiredArticleTypeLabel($product);
        $typeBlock = $typeLine !== null
            ? "Szukany rodzaj na zdjęciu: {$typeLine}. Inny rodzaj — is_relevant_product=false. Ta sama linia (np. GRZMOT) nie wystarczy."
            : 'Rodzaj bierz wyłącznie z nazwy (ręcznik ≠ kurtka ≠ kombinezon ≠ odzież robocza ≠ chemia).';

        return <<<PROMPT
Oceń {$count} kandydatów na GŁÓWNE zdjęcie katalogowe (packshot) tego produktu:
- SKU: {$product->sku}
- marka: {$product->manufacturer}
- nazwa: {$product->name}
- kategoria: {$product->category}
- normy: {$product->norms}

{$typeBlock}

Zaakceptuj TYLKO gdy widać sam ten produkt (pierwszy plan, ostro, studio/białe tło).
Zawsze is_relevant_product=false gdy:
- na zdjęciu są ludzie (twarz, ręce, kucharze, kelnerzy, personel, model w ubraniu, lifestyle, kuchnia, hotel jako motyw),
- widać inny asortyment niż nazwa (kurtka/kombinezon/słoik przy ręczniku; ręcznik przy odzieży),
- widać inną markę niż podana,
- to logo, ikona, baner, mapa, reklama, dokument lub miniatura kolekcji.

Kontekst stron jest wskazówką, nie dowodem. Gdy obraz nie zgadza się z nazwą/marką — odrzuć, nawet jeśli strona wygląda na kartę produktu. Nie zgaduj modelu z wyglądu. Wątpliwość → confidence poniżej 0.85 i is_relevant_product=false.

Kontekst stron:
{$this->joinContext($context)}

Zwróć:
{"candidates":[{"index":0,"is_relevant_product":true,"is_logo_or_banner":false,"confidence":0.0,"reason":"krótko"}]}
Uwzględnij każdy indeks od 0 do {$countMinusOne}.
PROMPT;
    }

    /** @param  list<string>  $context */
    private function joinContext(array $context): string
    {
        return implode("\n\n---\n\n", $context);
    }
}
