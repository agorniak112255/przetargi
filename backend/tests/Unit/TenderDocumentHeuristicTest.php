<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\TenderDocumentAiAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TenderDocumentHeuristicTest extends TestCase
{
    public function test_heuristic_splits_items_and_conditions(): void
    {
        $llm = (new ReflectionClass(OpenAiCompatibleClient::class))->newInstanceWithoutConstructor();
        $parser = new JsonResponseParser;
        $analyzer = new TenderDocumentAiAnalyzer($llm, $parser);

        $text = "1. Rękawice nitrylowe 100 szt\nTermin dostawy: 14 dni\n2. Kombinezon Tyvek\nWymagany certyfikat ISO 9001";
        $result = $analyzer->heuristic($text, ['items', 'conditions']);

        $this->assertNotEmpty($result['items']);
        $this->assertNotEmpty($result['conditions']);
        $this->assertTrue(
            collect($result['conditions'])->contains(
                static fn (array $c): bool => str_contains(mb_strtolower($c['content']), 'termin')
            )
        );
    }
}
