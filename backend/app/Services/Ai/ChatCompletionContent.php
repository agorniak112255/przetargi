<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Wyciąga tekst odpowiedzi z chat/completions — też z modeli reasoning (GPT-5, o-series).
 */
final class ChatCompletionContent
{
    public function fromPayload(mixed $payload): string
    {
        $message = data_get($payload, 'choices.0.message');
        if (! is_array($message)) {
            return '';
        }

        foreach ([
            $this->stringify($message['content'] ?? null),
            $this->stringify($message['reasoning_content'] ?? null),
            $this->jsonFromReasoning($message['reasoning'] ?? null),
            $this->fromReasoningDetails($message['reasoning_details'] ?? null),
        ] as $text) {
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    public function finishReason(mixed $payload): string
    {
        $reason = data_get($payload, 'choices.0.finish_reason');

        return is_string($reason) ? $reason : '';
    }

    private function stringify(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part)) {
                $parts[] = (string) ($part['text'] ?? $part['content'] ?? '');
            }
        }

        return trim(implode('', $parts));
    }

    private function jsonFromReasoning(mixed $reasoning): string
    {
        $text = $this->stringify($reasoning);
        if ($text === '' || ! str_contains($text, '{')) {
            return '';
        }

        return $text;
    }

    private function fromReasoningDetails(mixed $details): string
    {
        if (! is_array($details)) {
            return '';
        }

        $parts = [];
        foreach ($details as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (['text', 'summary', 'content'] as $key) {
                if (is_string($block[$key] ?? null) && trim((string) $block[$key]) !== '') {
                    $parts[] = trim((string) $block[$key]);
                    break;
                }
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '' || ! str_contains($text, '{')) {
            return '';
        }

        return $text;
    }
}
