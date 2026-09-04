<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Układ wizualny opisu — karta w systemie i HTML eksportu do Presty.
 */
final class EnrichmentDescriptionLayouts
{
    public const DEFAULT_KEY = 'domyslny';

    public const SURFACE_CARD = 'card';

    public const SURFACE_EXPORT = 'export';

    /** @var list<string> */
    public const SURFACES = [self::SURFACE_CARD, self::SURFACE_EXPORT];

    /** @var list<string> */
    public const EMPHASIS = ['none', 'highlight', 'accent', 'muted', 'strong'];

    /** @var array<string, array{label: string, surfaces: list<string>}> */
    public const BLOCKS = [
        'description' => ['label' => 'Opis', 'surfaces' => ['card', 'export']],
        'attributes' => ['label' => 'Atrybuty BHP', 'surfaces' => ['card', 'export']],
        'specs' => ['label' => 'Specyfikacja', 'surfaces' => ['card', 'export']],
        'features' => ['label' => 'Cechy', 'surfaces' => ['card', 'export']],
        'materials' => ['label' => 'Materiały', 'surfaces' => ['card', 'export']],
        'norms' => ['label' => 'Normy', 'surfaces' => ['card', 'export']],
        'certificates' => ['label' => 'Certyfikaty', 'surfaces' => ['card', 'export']],
        'use_cases' => ['label' => 'Zastosowanie', 'surfaces' => ['card', 'export']],
        'documents' => ['label' => 'Pliki PDF', 'surfaces' => ['card']],
        'sources' => ['label' => 'Źródła', 'surfaces' => ['card', 'export']],
    ];

    /**
     * @return list<array{id: string, visible: bool, emphasis: string}>
     */
    public static function defaultBlocks(string $surface): array
    {
        $ids = $surface === self::SURFACE_EXPORT
            ? ['description', 'attributes', 'specs', 'features', 'materials', 'norms', 'certificates', 'use_cases', 'sources']
            : ['description', 'attributes', 'specs', 'features', 'materials', 'norms', 'certificates', 'use_cases', 'documents', 'sources'];

        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'visible' => true,
                'emphasis' => $id === 'sources' ? 'muted' : 'none',
            ];
        }

        return $out;
    }

    /**
     * @return array{inherit_card: bool, inherit_export: bool, card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}
     */
    public static function defaultLayout(bool $inherit = true): array
    {
        return [
            'inherit_card' => $inherit,
            'inherit_export' => $inherit,
            'card' => self::defaultBlocks(self::SURFACE_CARD),
            'export' => self::defaultBlocks(self::SURFACE_EXPORT),
        ];
    }

    /**
     * @return array{inherit_card: bool, inherit_export: bool, card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}
     */
    public static function defaultStoredLayout(): array
    {
        $layout = self::defaultLayout(false);
        $layout['inherit_export'] = true;

        return $layout;
    }

    /**
     * @param  mixed  $raw
     * @return array{inherit_card: bool, inherit_export: bool, card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}
     */
    public static function normalize(mixed $raw, bool $isVisualDefault = false): array
    {
        $base = $isVisualDefault ? self::defaultStoredLayout() : self::defaultLayout(true);
        if (! is_array($raw)) {
            return $base;
        }

        $inheritCard = array_key_exists('inherit_card', $raw)
            ? (bool) $raw['inherit_card']
            : $base['inherit_card'];
        $inheritExport = array_key_exists('inherit_export', $raw)
            ? (bool) $raw['inherit_export']
            : $base['inherit_export'];
        if ($isVisualDefault) {
            $inheritCard = false;
        }

        return [
            'inherit_card' => $inheritCard,
            'inherit_export' => $inheritExport,
            'card' => self::normalizeBlocks($raw['card'] ?? null, self::SURFACE_CARD),
            'export' => self::normalizeBlocks($raw['export'] ?? null, self::SURFACE_EXPORT),
        ];
    }

    /**
     * @param  array{inherit_card: bool, inherit_export: bool, card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}  $layout
     * @param  array{inherit_card: bool, inherit_export: bool, card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}  $fallback
     * @return array{card: list<array{id: string, visible: bool, emphasis: string}>, export: list<array{id: string, visible: bool, emphasis: string}>}
     */
    public static function resolve(array $layout, array $fallback): array
    {
        $card = $layout['inherit_card'] ? $fallback['card'] : $layout['card'];
        $export = self::followsCard($layout, $card)
            ? self::exportFromCard($card)
            : $layout['export'];

        return [
            'card' => $card,
            'export' => $export,
        ];
    }

    /**
     * @param  list<array{id: string, visible: bool, emphasis: string}>  $card
     * @return list<array{id: string, visible: bool, emphasis: string}>
     */
    public static function exportFromCard(array $card): array
    {
        $allowed = [];
        foreach (self::BLOCKS as $id => $meta) {
            if (in_array(self::SURFACE_EXPORT, $meta['surfaces'], true)) {
                $allowed[$id] = true;
            }
        }
        $seen = [];
        $out = [];
        foreach ($card as $block) {
            $id = $block['id'] ?? '';
            if (! is_string($id) || $id === '' || ! isset($allowed[$id]) || isset($seen[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'visible' => (bool) ($block['visible'] ?? true),
                'emphasis' => self::isValidEmphasis((string) ($block['emphasis'] ?? 'none'))
                    ? (string) $block['emphasis']
                    : 'none',
            ];
            $seen[$id] = true;
        }
        foreach (self::defaultBlocks(self::SURFACE_EXPORT) as $block) {
            if (! isset($seen[$block['id']])) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, surfaces: list<string>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::BLOCKS as $id => $meta) {
            $out[] = [
                'id' => $id,
                'label' => $meta['label'],
                'surfaces' => $meta['surfaces'],
            ];
        }

        return $out;
    }

    public static function label(string $id): string
    {
        return self::BLOCKS[$id]['label'] ?? $id;
    }

    public static function isValidEmphasis(string $value): bool
    {
        return in_array($value, self::EMPHASIS, true);
    }

    /**
     * @param  array{inherit_export: bool, export: list<array{id: string, visible: bool, emphasis: string}>}  $layout
     * @param  list<array{id: string, visible: bool, emphasis: string}>  $card
     */
    public static function followsCard(array $layout, array $card): bool
    {
        if ($layout['inherit_export']) {
            return true;
        }

        return self::sameBlocks($layout['export'], self::defaultBlocks(self::SURFACE_EXPORT))
            && ! self::sameBlocks($card, self::defaultBlocks(self::SURFACE_CARD));
    }

    /**
     * @param  list<array{id: string, visible: bool, emphasis: string}>  $a
     * @param  list<array{id: string, visible: bool, emphasis: string}>  $b
     */
    public static function sameBlocks(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $block) {
            $other = $b[$i] ?? null;
            if (! is_array($other)
                || ($block['id'] ?? null) !== ($other['id'] ?? null)
                || (bool) ($block['visible'] ?? true) !== (bool) ($other['visible'] ?? true)
                || (string) ($block['emphasis'] ?? 'none') !== (string) ($other['emphasis'] ?? 'none')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{id: string, visible: bool, emphasis: string}>
     */
    private static function normalizeBlocks(mixed $raw, string $surface): array
    {
        $defaults = self::defaultBlocks($surface);
        $allowed = [];
        foreach (self::BLOCKS as $id => $meta) {
            if (in_array($surface, $meta['surfaces'], true)) {
                $allowed[$id] = true;
            }
        }

        $seen = [];
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
                if ($id === '' || ! isset($allowed[$id]) || isset($seen[$id])) {
                    continue;
                }
                $emphasis = isset($row['emphasis']) && is_string($row['emphasis']) && self::isValidEmphasis($row['emphasis'])
                    ? $row['emphasis']
                    : 'none';
                $out[] = [
                    'id' => $id,
                    'visible' => (bool) ($row['visible'] ?? true),
                    'emphasis' => $emphasis,
                ];
                $seen[$id] = true;
            }
        }

        foreach ($defaults as $block) {
            if (! isset($seen[$block['id']])) {
                $out[] = $block;
            }
        }

        return $out;
    }
}
