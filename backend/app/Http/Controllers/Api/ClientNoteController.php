<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Services\Clients\ClientTimeline;
use App\Support\PolishTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Notatki na karcie klienta: POST /clients/{client}/notes (clients.view), PATCH|DELETE /clients/{client}/notes/{note}
 * (scopeBindings — notatka innego klienta = 404; autor albo clients.manage, inaczej 403).
 *
 * Przypomnienie (remind_on) — dzień w Polsce, dziś albo później; przypomina crm:remind autorowi notatki. Zmiana dnia
 * zeruje reminded_at, więc przypomnienie wyjdzie znowu w nowym dniu.
 */
class ClientNoteController extends Controller
{
    private const BODY_MAX = 5000;

    public function store(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate($this->rules(true), $this->messages());
        $body = trim((string) $data['body']);
        $remindOn = $data['remind_on'] ?? null;
        $this->assertNotPast($remindOn);

        $note = ClientNote::query()->create([
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'body' => $body,
            'remind_on' => $remindOn,
        ]);
        $note->load('author:id,name');

        return response()->json(ClientTimeline::noteEvent($note, $request->user()), 201);
    }

    public function update(Request $request, Client $client, ClientNote $note): JsonResponse
    {
        $this->assertCanEdit($request, $note);
        $data = $request->validate($this->rules(false), $this->messages());

        $changes = [];
        if (array_key_exists('body', $data)) {
            $changes['body'] = trim((string) $data['body']);
        }
        if (array_key_exists('remind_on', $data)) {
            $remindOn = $data['remind_on'];
            $current = $note->remind_on?->format('Y-m-d');
            if ($remindOn !== $current) {
                // dzień w przeszłości tylko wtedy, gdy się nie zmienia (np. formularz wysłał dawne przypomnienie)
                $this->assertNotPast($remindOn);
                $changes['remind_on'] = $remindOn;
                $changes['reminded_at'] = null;
            }
        }
        if ($changes !== []) {
            $note->forceFill($changes)->save();
        }
        $note->load('author:id,name');

        return response()->json(ClientTimeline::noteEvent($note, $request->user()));
    }

    public function destroy(Request $request, Client $client, ClientNote $note): JsonResponse
    {
        $this->assertCanEdit($request, $note);
        $note->delete();

        return response()->json(['ok' => true]);
    }

    private function assertCanEdit(Request $request, ClientNote $note): void
    {
        if (! ClientTimeline::canEditNote($note, $request->user())) {
            abort(403, 'Notatkę może zmienić albo usunąć jej autor albo osoba z prawem edycji klientów.');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating): array
    {
        return [
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:'.self::BODY_MAX, 'regex:/\S/u'],
            'remind_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'body.required' => 'Wpisz treść notatki.',
            'body.regex' => 'Wpisz treść notatki.',
            'body.max' => 'Notatka może mieć najwyżej '.self::BODY_MAX.' znaków.',
            'remind_on.date_format' => 'Dzień przypomnienia podaj jako RRRR-MM-DD.',
        ];
    }

    /** Przypomnienie najwcześniej dziś (czas polski). */
    private function assertNotPast(?string $remindOn): void
    {
        if ($remindOn !== null && $remindOn < PolishTime::today()->format('Y-m-d')) {
            throw ValidationException::withMessages(['remind_on' => 'Dzień przypomnienia nie może być w przeszłości.']);
        }
    }
}
