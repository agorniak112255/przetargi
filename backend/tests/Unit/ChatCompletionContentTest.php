<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\ChatCompletionContent;
use PHPUnit\Framework\TestCase;

final class ChatCompletionContentTest extends TestCase
{
    public function test_reads_standard_content(): void
    {
        $reader = new ChatCompletionContent;
        $text = $reader->fromPayload([
            'choices' => [
                ['message' => ['content' => '{"matches":[]}'], 'finish_reason' => 'stop'],
            ],
        ]);

        $this->assertSame('{"matches":[]}', $text);
    }

    public function test_reads_json_from_reasoning_when_content_empty(): void
    {
        $reader = new ChatCompletionContent;
        $text = $reader->fromPayload([
            'choices' => [
                [
                    'message' => [
                        'content' => '',
                        'reasoning' => 'Najpierw typ: fartuch. Wynik: {"matches":[{"id":1,"score":90}]}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $this->assertStringContainsString('"matches"', $text);
    }

    public function test_reads_reasoning_content_field(): void
    {
        $reader = new ChatCompletionContent;
        $text = $reader->fromPayload([
            'choices' => [
                [
                    'message' => [
                        'content' => null,
                        'reasoning_content' => '{"matches":[{"id":2,"score":80}]}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $this->assertSame('{"matches":[{"id":2,"score":80}]}', $text);
    }

    public function test_finish_reason_length(): void
    {
        $reader = new ChatCompletionContent;
        $reason = $reader->finishReason([
            'choices' => [['finish_reason' => 'length']],
        ]);

        $this->assertSame('length', $reason);
    }
}
