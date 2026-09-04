<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\EnrichmentDescriptionTemplate;
use App\Models\Product;
use App\Support\EnrichmentDescriptionLayouts;
use App\Support\EnrichmentDescriptionTemplates;
use App\Support\PpeAssortment;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EnrichmentDescriptionTemplateService
{
    public function __construct(
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @return list<array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     is_visual_default: bool,
     *     layout: array<string, mixed>,
     *     resolved_layout: array{card: list<array<string, mixed>>, export: list<array<string, mixed>>},
     *     is_layout_customized: bool,
     *     updated_at: ?string
     * }>
     */
    public function list(): array
    {
        if (! $this->tableReady()) {
            return $this->defaultsAsList();
        }

        $this->ensureSeeded();
        $rows = EnrichmentDescriptionTemplate::query()
            ->get()
            ->keyBy('kategoria_bhp');

        $out = [];
        foreach ($this->listKeys() as $key) {
            $out[] = $this->presentFromRow($key, $rows->get($key), $rows);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     is_visual_default: bool,
     *     layout: array<string, mixed>,
     *     resolved_layout: array{card: list<array<string, mixed>>, export: list<array<string, mixed>>},
     *     is_layout_customized: bool,
     *     updated_at: ?string
     * }
     */
    public function update(string $kategoria, ?string $instructions = null, ?array $layout = null): array
    {
        $key = $this->requireKey($kategoria);
        if (! $this->tableReady()) {
            throw new RuntimeException('Tabela szablonów nie istnieje — uruchom migracje na serwerze.');
        }
        $this->ensureSeeded();
        $row = EnrichmentDescriptionTemplate::query()->firstOrNew(['kategoria_bhp' => $key]);
        $isDefault = $key === EnrichmentDescriptionLayouts::DEFAULT_KEY;
        if ($instructions !== null) {
            $text = trim($instructions);
            if (! $isDefault && $text === '') {
                throw new InvalidArgumentException('Instrukcje szablonu nie mogą być puste.');
            }
            if ($text !== '') {
                $row->instructions = $text;
            }
        }
        if ($row->instructions === null || trim((string) $row->instructions) === '') {
            $row->instructions = $isDefault
                ? 'Układ wizualny karty i eksportu — nie jest wysyłany do modelu.'
                : EnrichmentDescriptionTemplates::defaultInstructions($key);
        }
        if ($this->layoutColumnReady()) {
            if ($layout !== null) {
                $row->layout = EnrichmentDescriptionLayouts::normalize($layout, $isDefault);
            } elseif (! is_array($row->layout)) {
                $row->layout = $isDefault
                    ? EnrichmentDescriptionLayouts::defaultStoredLayout()
                    : EnrichmentDescriptionLayouts::defaultLayout(true);
            }
        } elseif ($layout !== null) {
            throw new RuntimeException('Kolumna układu nie istnieje — uruchom migracje na serwerze.');
        }
        $row->save();

        return $this->one($key);
    }

    /**
     * @return array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     updated_at: ?string
     * }
     */
    public function restore(string $kategoria): array
    {
        $key = $this->requireKey($kategoria);
        $isDefault = $key === EnrichmentDescriptionLayouts::DEFAULT_KEY;
        $instructions = $isDefault
            ? 'Układ wizualny karty i eksportu — nie jest wysyłany do modelu.'
            : EnrichmentDescriptionTemplates::defaultInstructions($key);
        $layout = $isDefault
            ? EnrichmentDescriptionLayouts::defaultStoredLayout()
            : EnrichmentDescriptionLayouts::defaultLayout(true);

        return $this->update($kategoria, $instructions, $layout);
    }

    /**
     * @return array{kategoria_bhp: string, label: string, card: list<array<string, mixed>>, export: list<array<string, mixed>>}
     */
    public function resolvedForProduct(Product $product): array
    {
        $key = $this->kategoriaForProduct($product);
        $resolved = $this->resolvedLayout($key);

        return [
            'kategoria_bhp' => $key,
            'label' => EnrichmentDescriptionTemplates::label($key),
            'card' => $resolved['card'],
            'export' => $resolved['export'],
        ];
    }

    public function kategoriaForProduct(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $stored = $this->normalizeKey($attrs['kategoria_bhp'] ?? null);
        $hay = trim(
            $product->name.' '.$product->sku.' '
            .(string) ($product->category ?? '').' '
            .(string) ($product->norms ?? '')
        );
        $family = $this->assortment->resolveFamily($hay, $stored);
        $fromFamily = $this->assortment->kategoriaFromFamily($family);
        if ($fromFamily !== null) {
            return $fromFamily;
        }

        return $stored ?? EnrichmentDescriptionTemplates::FALLBACK;
    }

    public function systemPrompt(Product $product): string
    {
        $key = $this->kategoriaForProduct($product);
        $instructions = $this->instructionsFor($key);
        $label = EnrichmentDescriptionTemplates::label($key);

        return "Jesteś ekspertem BHP/PPE. Wejście to OCZYSZCZONE fakty o produkcie (bez chrome sklepu).\n"
            ."Rodzina produktu do tej karty: {$label} ({$key}). Stosuj poniższe instrukcje tej rodziny.\n\n"
            .$instructions."\n\n"
            .EnrichmentDescriptionTemplates::jsonContract();
    }

    public function ensureSeeded(): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $existing = EnrichmentDescriptionTemplate::query()->pluck('kategoria_bhp')->all();
        foreach ($this->listKeys() as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $isDefault = $key === EnrichmentDescriptionLayouts::DEFAULT_KEY;
            $payload = [
                'kategoria_bhp' => $key,
                'instructions' => $isDefault
                    ? 'Układ wizualny karty i eksportu — nie jest wysyłany do modelu.'
                    : EnrichmentDescriptionTemplates::defaultInstructions($key),
            ];
            if ($this->layoutColumnReady()) {
                $payload['layout'] = $isDefault
                    ? EnrichmentDescriptionLayouts::defaultStoredLayout()
                    : EnrichmentDescriptionLayouts::defaultLayout(true);
            }
            EnrichmentDescriptionTemplate::query()->create($payload);
        }
    }

    private function instructionsFor(string $key): string
    {
        if ($this->tableReady()) {
            $this->ensureSeeded();
            $row = EnrichmentDescriptionTemplate::query()->where('kategoria_bhp', $key)->first();
            $text = trim((string) ($row?->instructions ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return EnrichmentDescriptionTemplates::defaultInstructions($key);
    }

    /**
     * @return array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     is_visual_default: bool,
     *     layout: array<string, mixed>,
     *     resolved_layout: array{card: list<array<string, mixed>>, export: list<array<string, mixed>>},
     *     is_layout_customized: bool,
     *     updated_at: ?string
     * }
     */
    private function one(string $key): array
    {
        foreach ($this->list() as $row) {
            if ($row['kategoria_bhp'] === $key) {
                return $row;
            }
        }

        throw new InvalidArgumentException('Nieznana rodzina BHP: '.$key);
    }

    /**
     * @return list<array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     is_visual_default: bool,
     *     layout: array<string, mixed>,
     *     resolved_layout: array{card: list<array<string, mixed>>, export: list<array<string, mixed>>},
     *     is_layout_customized: bool,
     *     updated_at: ?string
     * }>
     */
    private function defaultsAsList(): array
    {
        $out = [];
        foreach ($this->listKeys() as $key) {
            $out[] = $this->presentFromRow($key, null, collect());
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, EnrichmentDescriptionTemplate>|mixed  $rows
     * @return array{
     *     kategoria_bhp: string,
     *     label: string,
     *     instructions: string,
     *     default_instructions: string,
     *     is_customized: bool,
     *     is_fallback: bool,
     *     is_visual_default: bool,
     *     layout: array<string, mixed>,
     *     resolved_layout: array{card: list<array<string, mixed>>, export: list<array<string, mixed>>},
     *     is_layout_customized: bool,
     *     updated_at: ?string
     * }
     */
    private function presentFromRow(string $key, mixed $row, mixed $rows): array
    {
        $isDefault = $key === EnrichmentDescriptionLayouts::DEFAULT_KEY;
        $defaultInstructions = $isDefault
            ? 'Układ wizualny karty i eksportu — nie jest wysyłany do modelu.'
            : EnrichmentDescriptionTemplates::defaultInstructions($key);
        $instructions = is_string($row?->instructions ?? null) && trim((string) $row->instructions) !== ''
            ? (string) $row->instructions
            : $defaultInstructions;
        $stored = EnrichmentDescriptionLayouts::normalize(
            $this->layoutColumnReady() ? ($row?->layout ?? null) : null,
            $isDefault
        );
        $fallback = $isDefault
            ? EnrichmentDescriptionLayouts::defaultStoredLayout()
            : $this->storedDefaultLayout($rows);
        $resolved = EnrichmentDescriptionLayouts::resolve($stored, $fallback);

        return [
            'kategoria_bhp' => $key,
            'label' => $isDefault ? 'Domyślny układ' : EnrichmentDescriptionTemplates::label($key),
            'instructions' => $instructions,
            'default_instructions' => $defaultInstructions,
            'is_customized' => ! $isDefault && trim($instructions) !== trim($defaultInstructions),
            'is_fallback' => $key === EnrichmentDescriptionTemplates::FALLBACK,
            'is_visual_default' => $isDefault,
            'layout' => $stored,
            'resolved_layout' => $resolved,
            'is_layout_customized' => $isDefault
                ? $stored['card'] !== EnrichmentDescriptionLayouts::defaultBlocks('card')
                    || $stored['export'] !== EnrichmentDescriptionLayouts::defaultBlocks('export')
                : ! $stored['inherit_card'] || ! $stored['inherit_export'],
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{card: list<array<string, mixed>>, export: list<array<string, mixed>>}
     */
    private function resolvedLayout(string $key): array
    {
        $fallback = EnrichmentDescriptionLayouts::defaultStoredLayout();
        if ($this->layoutColumnReady()) {
            $this->ensureSeeded();
            $defaultRow = EnrichmentDescriptionTemplate::query()
                ->where('kategoria_bhp', EnrichmentDescriptionLayouts::DEFAULT_KEY)
                ->first();
            $fallback = EnrichmentDescriptionLayouts::normalize($defaultRow?->layout ?? null, true);
            if ($key === EnrichmentDescriptionLayouts::DEFAULT_KEY) {
                return [
                    'card' => $fallback['card'],
                    'export' => $fallback['export'],
                ];
            }
            $row = EnrichmentDescriptionTemplate::query()->where('kategoria_bhp', $key)->first();
            $stored = EnrichmentDescriptionLayouts::normalize($row?->layout ?? null, false);

            return EnrichmentDescriptionLayouts::resolve($stored, $fallback);
        }

        return [
            'card' => $fallback['card'],
            'export' => $fallback['export'],
        ];
    }

    /**
     * @param  mixed  $rows
     * @return array{inherit_card: bool, inherit_export: bool, card: list<array<string, mixed>>, export: list<array<string, mixed>>}
     */
    private function storedDefaultLayout(mixed $rows): array
    {
        if (! $this->layoutColumnReady()) {
            return EnrichmentDescriptionLayouts::defaultStoredLayout();
        }
        $row = is_object($rows) && method_exists($rows, 'get')
            ? $rows->get(EnrichmentDescriptionLayouts::DEFAULT_KEY)
            : null;

        return EnrichmentDescriptionLayouts::normalize($row?->layout ?? null, true);
    }

    /**
     * @return list<string>
     */
    private function listKeys(): array
    {
        return array_merge(
            [EnrichmentDescriptionLayouts::DEFAULT_KEY],
            EnrichmentDescriptionTemplates::keys()
        );
    }

    private function requireKey(string $kategoria): string
    {
        $key = $this->normalizeKey($kategoria);
        if ($key === null) {
            throw new InvalidArgumentException('Nieznana rodzina BHP.');
        }

        return $key;
    }

    private function normalizeKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $key = trim($value);
        if ($key === EnrichmentDescriptionLayouts::DEFAULT_KEY) {
            return $key;
        }

        return EnrichmentDescriptionTemplates::isValidKey($key) ? $key : null;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('enrichment_description_templates');
        } catch (Throwable) {
            return false;
        }
    }

    private function layoutColumnReady(): bool
    {
        try {
            return $this->tableReady() && Schema::hasColumn('enrichment_description_templates', 'layout');
        } catch (Throwable) {
            return false;
        }
    }
}
