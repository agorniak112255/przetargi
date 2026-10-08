<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Grupa kart jednego modelu w planie partii (ModelGroupPlanner::groups): zadanie idzie do lidera, członkowie dostają
 * jego opis. Karta marki bez grupowania to grupa jednoelementowa z pustym kluczem ('' — pozycja partii bez model_key).
 */
final readonly class ModelGroup
{
    /**
     * @param  list<int>  $memberIds  z liderem na pierwszym miejscu, dalej w kolejności listy wejściowej
     */
    public function __construct(
        public string $key,
        public string $stem,
        public int $leaderId,
        public array $memberIds,
    ) {}
}
