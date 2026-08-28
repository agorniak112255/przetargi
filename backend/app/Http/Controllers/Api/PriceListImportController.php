<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettingsService;
use App\Services\AssortmentGroupService;
use App\Services\PriceListAiAnalyzer;
use App\Services\PriceListImportService;
use App\Services\PriceListMetaDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PriceListImportController extends Controller
{
    private const ALLOWED_EXT = ['xlsx', 'xls', 'csv', 'pdf'];

    public function __construct(
        private readonly PriceListImportService $importer,
        private readonly PriceListAiAnalyzer $analyzer,
        private readonly AiSettingsService $aiSettings,
        private readonly PriceListMetaDetector $metaDetector,
        private readonly AssortmentGroupService $assortmentGroups,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'], // do 100 MB
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'use_ai' => ['sometimes', 'boolean'],
            'mapping' => ['nullable'],
            'products' => ['nullable'],
            'group_options' => ['nullable'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $this->assertAllowedFile($file);

        @set_time_limit(3600);

        $useAi = $request->boolean('use_ai');
        $mapping = $this->decodeJsonField($request->input('mapping'));
        $products = $this->decodeJsonField($request->input('products'));
        $groupOptions = $this->decodeGroupOptions($request->input('group_options'));
        $productsList = is_array($products) ? ($products['products'] ?? $products) : null;
        if (! is_array($productsList)) {
            $productsList = null;
        }

        $isPdf = $this->extensionOf($file) === 'pdf';
        [$manufacturer, $version] = $this->resolveManufacturerVersion(
            $file->getClientOriginalName(),
            is_array($mapping) && is_string($mapping['manufacturer_detected'] ?? null)
                ? $mapping['manufacturer_detected']
                : null,
            trim((string) ($data['manufacturer'] ?? '')),
            trim((string) ($data['version'] ?? '')),
        );

        if ($isPdf && $productsList === null && $useAi) {
            if (! $this->aiSettings->isReady()) {
                throw ValidationException::withMessages([
                    'file' => 'PDF wymaga AI — skonfiguruj Ustawienia AI i uruchom „Analizuj AI”.',
                ]);
            }
            try {
                $analysis = $this->analyzer->analyze(
                    $file->getRealPath() ?: '',
                    $manufacturer,
                    $file->getClientOriginalName(),
                );
                $productsList = $analysis['products'] ?? [];
                if (isset($analysis['meta']['manufacturer'])) {
                    $manufacturer = (string) $analysis['meta']['manufacturer'];
                }
                if (isset($analysis['meta']['version'])) {
                    $version = (string) $analysis['meta']['version'];
                }
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'file' => 'Analiza AI nieudana: '.$e->getMessage(),
                ]);
            }
        }

        if (is_array($productsList) && $productsList !== []) {
            $result = $this->importer->importFromProducts(
                $file,
                $manufacturer,
                $version,
                $request->user(),
                $productsList,
                $data['category'] ?? null,
                $groupOptions,
            );
        } elseif ($mapping !== null) {
            $result = $this->importer->importWithMapping(
                $file,
                $manufacturer,
                $version,
                $request->user(),
                $mapping,
                $data['category'] ?? null,
                $groupOptions,
            );
        } elseif ($isPdf) {
            throw ValidationException::withMessages([
                'file' => 'PDF: najpierw „Analizuj AI”, potem import.',
            ]);
        } elseif ($useAi && $mapping === null) {
            if (! $this->aiSettings->isReady()) {
                throw ValidationException::withMessages([
                    'use_ai' => 'Skonfiguruj i włącz API AI w Ustawieniach AI, albo najpierw uruchom analizę.',
                ]);
            }
            try {
                $analysis = $this->analyzer->analyze(
                    $file->getRealPath() ?: '',
                    $manufacturer,
                    $file->getClientOriginalName(),
                );
                if (isset($analysis['meta']['manufacturer'])) {
                    $manufacturer = (string) $analysis['meta']['manufacturer'];
                }
                if (isset($analysis['meta']['version'])) {
                    $version = (string) $analysis['meta']['version'];
                }
                if (str_starts_with((string) ($analysis['source'] ?? ''), 'pdf')) {
                    $result = $this->importer->importFromProducts(
                        $file,
                        $manufacturer,
                        $version,
                        $request->user(),
                        $analysis['products'] ?? [],
                        $data['category'] ?? null,
                        $groupOptions,
                    );
                } else {
                    $result = $this->importer->importWithMapping(
                        $file,
                        $manufacturer,
                        $version,
                        $request->user(),
                        $analysis['mapping'],
                        $data['category'] ?? null,
                        $groupOptions,
                    );
                    $mapping = $analysis['mapping'];
                }
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'file' => 'Analiza AI nieudana: '.$e->getMessage(),
                ]);
            }
        } else {
            $result = $this->importer->import(
                $file,
                $manufacturer,
                $version,
                $request->user(),
                $data['category'] ?? null,
                $groupOptions,
            );
        }

        if ($result['price_list'] === null) {
            throw ValidationException::withMessages([
                'file' => $result['errors'][0] ?? 'Błąd importu cennika.',
            ]);
        }

        return response()->json([
            ...$result,
            'mapping_used' => $mapping,
        ], 201);
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'], // do 100 MB
            'manufacturer' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $this->assertAllowedFile($file);

        $path = $file->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Nie można odczytać pliku.',
            ]);
        }

        $isPdf = $this->extensionOf($file) === 'pdf';
        if (! $isPdf && ! $this->aiSettings->isReady()) {
            throw ValidationException::withMessages([
                'file' => 'Skonfiguruj API AI (włączone + klucz + model) w Ustawieniach AI. '
                    .'Import prosty XLSX działa bez AI.',
            ]);
        }

        try {
            @set_time_limit(3600);
            $fromFile = $this->metaDetector->fromFilename($file->getClientOriginalName());
            // nie przekazuj „zastałego” producenta z formularza — najpierw nazwa pliku
            $result = $this->analyzer->analyze(
                $path,
                $fromFile['manufacturer'],
                $file->getClientOriginalName(),
            );
        } catch (RuntimeException|Throwable $e) {
            $msg = $e->getMessage();
            if (! $this->aiSettings->isReady() && ! $isPdf) {
                $msg = 'Brak konfiguracji AI: '.$msg;
            }
            throw ValidationException::withMessages([
                'file' => $msg,
            ]);
        }

        if (! isset($result['assortment_groups']) || ! is_array($result['assortment_groups'])) {
            $metaManufacturer = is_string($result['meta']['manufacturer'] ?? null)
                ? (string) $result['meta']['manufacturer']
                : (is_string($result['mapping']['manufacturer_detected'] ?? null)
                    ? (string) $result['mapping']['manufacturer_detected']
                    : ((string) ($fromFile['manufacturer'] ?? 'Nieznany')));
            $productsForGroups = is_array($result['products'] ?? null) && $result['products'] !== []
                ? $result['products']
                : (is_array($result['preview'] ?? null) ? $result['preview'] : []);
            $result['assortment_groups'] = $this->assortmentGroups->summarize(
                $productsForGroups,
                $metaManufacturer !== '' ? $metaManufacturer : 'Nieznany',
            );
        }

        return response()->json($result);
    }

    /**
     * @return array{
     *     groups: list<array{name: string, discount_percent: float}>,
     *     default_discount: float|null,
     *     ungrouped_group: string|null,
     *     product_assignments: array<string, string>
     * }|null
     */
    private function decodeGroupOptions(mixed $raw): ?array
    {
        $decoded = $this->decodeJsonField($raw);
        if ($decoded === null) {
            return null;
        }

        $groups = [];
        if (is_array($decoded['groups'] ?? null)) {
            foreach ($decoded['groups'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $groups[] = [
                    'name' => $name,
                    'discount_percent' => is_numeric($row['discount_percent'] ?? null)
                        ? (float) $row['discount_percent']
                        : 0.0,
                ];
            }
        }

        $assignments = [];
        if (is_array($decoded['product_assignments'] ?? null)) {
            foreach ($decoded['product_assignments'] as $sku => $groupName) {
                $skuKey = trim((string) $sku);
                $group = trim((string) $groupName);
                if ($skuKey !== '' && $group !== '') {
                    $assignments[$skuKey] = $group;
                }
            }
        }

        $ungrouped = isset($decoded['ungrouped_group'])
            ? trim((string) $decoded['ungrouped_group'])
            : '';
        $default = isset($decoded['default_discount']) && is_numeric($decoded['default_discount'])
            ? (float) $decoded['default_discount']
            : null;

        return [
            'groups' => $groups,
            'default_discount' => $default,
            'ungrouped_group' => $ungrouped !== '' ? $ungrouped : null,
            'product_assignments' => $assignments,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveManufacturerVersion(
        string $filename,
        ?string $aiDetected,
        string $formManufacturer,
        string $formVersion,
    ): array {
        $fromFile = $this->metaDetector->fromFilename($filename);
        $meta = $this->metaDetector->resolve(null, $filename, $aiDetected, null, null);

        $manufacturer = $meta['manufacturer'];
        $fileBrand = $fromFile['manufacturer'];
        // formularz tylko gdy plik/AI nie wskazują innej marki
        if ($formManufacturer !== '') {
            $formNorm = mb_strtolower($formManufacturer);
            $metaNorm = mb_strtolower($manufacturer);
            $fileNorm = $fileBrand !== null ? mb_strtolower($fileBrand === 'EMA' ? 'Ansell' : $fileBrand) : null;
            $aiNorm = $aiDetected !== null ? mb_strtolower($aiDetected) : null;
            $agreesWithFile = $fileNorm !== null && ($formNorm === $fileNorm || ($formNorm === 'ansell' && $fileNorm === 'ansell'));
            $agreesWithAi = $aiNorm !== null && $formNorm === $aiNorm;
            if ($fileBrand === null && $aiDetected === null) {
                $manufacturer = $formManufacturer;
            } elseif ($agreesWithFile || $agreesWithAi || $formNorm === $metaNorm) {
                $manufacturer = $formManufacturer;
            }
        }

        $version = $fromFile['version'] ?? ($formVersion !== '' ? $formVersion : $meta['version']);

        return [$manufacturer, $version];
    }

    private function assertAllowedFile(UploadedFile $file): void
    {
        $ext = $this->extensionOf($file);
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            throw ValidationException::withMessages([
                'file' => 'Dozwolone formaty: XLSX, XLS, CSV, PDF.',
            ]);
        }
    }

    private function extensionOf(UploadedFile $file): string
    {
        $name = mb_strtolower($file->getClientOriginalName());
        // obsługa "plik..pdf"
        if (preg_match('/\.([a-z0-9]+)$/', $name, $m) === 1) {
            return $m[1];
        }

        return mb_strtolower($file->getClientOriginalExtension());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonField(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
