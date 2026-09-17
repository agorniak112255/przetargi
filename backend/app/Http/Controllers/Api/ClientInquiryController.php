<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeClientInquiryRequest;
use App\Http\Requests\MarkClientInquiryRepliedRequest;
use App\Http\Requests\StoreClientInquiryRequest;
use App\Http\Requests\UpdateClientInquiryRequest;
use App\Models\ClientInquiry;
use App\Services\ClientInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ClientInquiryController extends Controller
{
    public function __construct(
        private readonly ClientInquiryService $inquiries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = ClientInquiry::query()
            ->where('user_id', $request->user()->id)
            ->with('client:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ClientInquiry $row): array => [
                'id' => $row->id,
                'source_subject' => $row->source_subject,
                'reply_subject' => $row->reply_subject,
                'client' => $row->client ? ['id' => $row->client->id, 'name' => $row->client->name] : null,
                'created_at' => $row->created_at?->toIso8601String(),
                'has_reply' => $row->reply_body !== null && $row->reply_body !== '',
                'replied_at' => $row->replied_at?->toIso8601String(),
                'attention_count' => $this->inquiries->attentionCount($row),
            ]);

        return response()->json($rows);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json($this->inquiries->lastPreferences($request->user()));
    }

    public function store(StoreClientInquiryRequest $request): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();

        // Powtórne kliknięcie w dodatku ma otworzyć istniejące zapytanie,
        // a nie uruchomić drugiej analizy tego samego maila.
        $existing = $this->inquiries->existingForMessage(
            $request->user(),
            isset($data['source_message_id']) ? (string) $data['source_message_id'] : null,
        );
        if ($existing instanceof ClientInquiry) {
            return response()->json($this->inquiries->present($existing->load('client')));
        }

        try {
            $inquiry = $this->inquiries->analyze(
                $request->user(),
                (string) $data['body'],
                (string) $data['tone'],
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                isset($data['subject']) ? (string) $data['subject'] : null,
                [
                    'message_id' => isset($data['source_message_id']) ? (string) $data['source_message_id'] : null,
                    'channel' => isset($data['source_channel']) ? (string) $data['source_channel'] : null,
                ],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd analizy zapytania: '.$e->getMessage()], 422);
        }

        return response()->json($this->inquiries->present($inquiry), 201);
    }

    public function show(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        return response()->json($this->inquiries->present($inquiry->load('client')));
    }

    public function compose(ComposeClientInquiryRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();
        /** @var array<string, array{option_id: string, custom?: string|null}> $answers */
        $answers = $data['answers'];

        try {
            $inquiry = $this->inquiries->compose(
                $inquiry,
                $answers,
                // brak klucza w żądaniu = nie ruszaj zapisanego dopisku
                array_key_exists('extra_note', $data) ? $data['extra_note'] : false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd pisania odpowiedzi: '.$e->getMessage()], 422);
        }

        return response()->json($this->inquiries->present($inquiry));
    }

    /** Ręczne poprawki tematu/treści listu przez pracownika. */
    public function update(UpdateClientInquiryRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        $data = $request->validated();
        $changes = [];
        if (array_key_exists('reply_subject', $data)) {
            $changes['reply_subject'] = (string) $data['reply_subject'];
        }
        if (array_key_exists('reply_body', $data)) {
            $changes['reply_body'] = (string) $data['reply_body'];
        }
        if ($changes !== []) {
            $inquiry->forceFill($changes)->save();
        }

        return response()->json($this->inquiries->present($inquiry->load('client')));
    }

    /** Oznaczenie „wysłano” (idempotentne): true ustawia raz, false kasuje. */
    public function replied(MarkClientInquiryRepliedRequest $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        $replied = (bool) $request->validated()['replied'];
        if ($replied && $inquiry->replied_at === null) {
            $inquiry->forceFill(['replied_at' => now()])->save();
        } elseif (! $replied && $inquiry->replied_at !== null) {
            $inquiry->forceFill(['replied_at' => null])->save();
        }

        return response()->json($this->inquiries->present($inquiry->load('client')));
    }

    private function assertOwner(Request $request, ClientInquiry $inquiry): void
    {
        if ((int) $inquiry->user_id !== (int) $request->user()->id) {
            abort(403, 'Brak dostępu do tego zapytania.');
        }
    }
}
