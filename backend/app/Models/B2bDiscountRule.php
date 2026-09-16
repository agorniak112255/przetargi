<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rabat konta B2B dla witryny, która podaje tylko cenę katalogową. Reguły sprawdzane są po kolei
 * (position rosnąco), pierwsza pasująca wygrywa. Karta bez pasującej reguły nie dostaje rabatu
 * — jest pomijana z powodem, żeby cena katalogowa nigdy nie trafiła do wycen jako cena zakupu.
 */
class B2bDiscountRule extends Model
{
    /** Numer katalogowy u dostawcy (dla protekt.pl: „Nr kat.”). */
    public const FIELD_CATALOG_NO = 'catalog_no';

    /** Kategoria produktu u dostawcy. */
    public const FIELD_CATEGORY = 'category';

    public const FIELD_NAME = 'name';

    public const FIELDS = [self::FIELD_CATALOG_NO, self::FIELD_CATEGORY, self::FIELD_NAME];

    public const TYPE_PREFIX = 'prefix';

    public const TYPE_EQUALS = 'equals';

    public const TYPE_CONTAINS = 'contains';

    /** Łapanka na końcu listy — pasuje do wszystkiego, wzorzec nieużywany. */
    public const TYPE_ANY = 'any';

    public const TYPES = [self::TYPE_PREFIX, self::TYPE_EQUALS, self::TYPE_CONTAINS, self::TYPE_ANY];

    protected $fillable = [
        'b2b_account_id',
        'position',
        'name',
        'match_field',
        'match_type',
        'pattern',
        'discount_percent',
        'assortment_group_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'last_matched_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    public function assortmentGroup(): BelongsTo
    {
        return $this->belongsTo(AssortmentGroup::class);
    }

    /**
     * Czy reguła pasuje do karty dostawcy. Porównania bez wielkości liter i bez otaczających
     * spacji — numery katalogowe Protektu bywają zapisane raz „AZ930 120”, raz „AZ930120”,
     * ale tego nie normalizujemy: zmiana znaczenia numeru byłaby zgadywaniem.
     */
    public function matches(string $catalogNo, ?string $category, string $name): bool
    {
        if ($this->match_type === self::TYPE_ANY) {
            return true;
        }

        $value = $this->normalize(match ($this->match_field) {
            self::FIELD_CATEGORY => $category,
            self::FIELD_NAME => $name,
            default => $catalogNo,
        });
        $pattern = $this->normalize($this->pattern);

        if ($value === '' || $pattern === '') {
            return false;
        }

        return match ($this->match_type) {
            self::TYPE_EQUALS => $value === $pattern,
            self::TYPE_CONTAINS => str_contains($value, $pattern),
            default => str_starts_with($value, $pattern),
        };
    }

    private function normalize(?string $text): string
    {
        return mb_strtolower(trim((string) $text));
    }
}
