<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInquiry extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'tone',
        'source_subject',
        'source_body',
        'analysis',
        'answers',
        'extra_note',
        'reply_subject',
        'reply_body',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'answers' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array{
     *     id: int,
     *     client_id: int|null,
     *     client: array{id: int, name: string}|null,
     *     tone: string,
     *     source_subject: string|null,
     *     source_body: string,
     *     questions: list<string>,
     *     matches: list<array<string, mixed>>,
     *     cards: list<array<string, mixed>>,
     *     answers: array<string, mixed>,
     *     extra_note: string|null,
     *     reply_subject: string|null,
     *     reply_body: string|null,
     *     created_at: string|null
     * }
     */
    public function toApiArray(): array
    {
        $analysis = is_array($this->analysis) ? $this->analysis : [];
        $client = $this->relationLoaded('client') ? $this->client : null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $client instanceof Client
                ? ['id' => $client->id, 'name' => $client->name]
                : null,
            'tone' => (string) $this->tone,
            'source_subject' => $this->source_subject,
            'source_body' => (string) $this->source_body,
            'questions' => $this->stringList($analysis['questions'] ?? null),
            'matches' => $this->matchGroups($analysis['matches'] ?? null),
            'cards' => $this->cardList($analysis['cards'] ?? null),
            'answers' => is_array($this->answers) ? $this->answers : [],
            'extra_note' => $this->extra_note,
            'reply_subject' => $this->reply_subject,
            'reply_body' => $this->reply_body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchGroups(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $group) {
            if (is_array($group)) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $card) {
            if (is_array($card) && isset($card['id'])) {
                $out[] = $card;
            }
        }

        return $out;
    }
}
