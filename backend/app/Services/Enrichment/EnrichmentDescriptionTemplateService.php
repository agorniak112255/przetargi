<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\EnrichmentDescriptionTemplate;
use App\Models\Product;
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
        foreach (EnrichmentDescriptionTemplates::keys() as $key) {
            $default = EnrichmentDescriptionTemplates::defaultInstructions($key);
            $row = $rows->get($key);
            $instructions = is_string($row?->instructions) && trim((string) $row->instructions) !== ''
                ? (string) $row->instructions
                : $default;
            $out[] = $this->present($key, $instructions, $default, $row?->updated_at?->toIso8601String());
        }

        return $out;
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
    public function update(string $kategoria, string $instructions): array
    {
        $key = $this->requireKey($kategoria);
        if (! $this->tableReady()) {
            throw new RuntimeException('Tabela szablonów nie istnieje — uruchom migracje na serwerze.');
        }
        $this->ensureSeeded();
        $text = trim($instructions);
        EnrichmentDescriptionTemplate::query()->updateOrCreate(
            ['kategoria_bhp' => $key],
            ['instructions' => $text]
        );

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
        return $this->update($kategoria, EnrichmentDescriptionTemplates::defaultInstructions($this->requireKey($kategoria)));
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
        foreach (EnrichmentDescriptionTemplates::keys() as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            EnrichmentDescriptionTemplate::query()->create([
                'kategoria_bhp' => $key,
                'instructions' => EnrichmentDescriptionTemplates::defaultInstructions($key),
            ]);
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
     *     updated_at: ?string
     * }>
     */
    private function defaultsAsList(): array
    {
        $out = [];
        foreach (EnrichmentDescriptionTemplates::keys() as $key) {
            $default = EnrichmentDescriptionTemplates::defaultInstructions($key);
            $out[] = $this->present($key, $default, $default, null);
        }

        return $out;
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
    private function present(string $key, string $instructions, string $default, ?string $updatedAt): array
    {
        return [
            'kategoria_bhp' => $key,
            'label' => EnrichmentDescriptionTemplates::label($key),
            'instructions' => $instructions,
            'default_instructions' => $default,
            'is_customized' => trim($instructions) !== trim($default),
            'is_fallback' => $key === EnrichmentDescriptionTemplates::FALLBACK,
            'updated_at' => $updatedAt,
        ];
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
}
