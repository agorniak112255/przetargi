<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeClientInquiryRequest;
use App\Http\Requests\StoreClientInquiryRequest;
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
            ->limit(20)
            ->get()
            ->map(static fn (ClientInquiry $row): array => [
                'id' => $row->id,
                'source_subject' => $row->source_subject,
                'reply_subject' => $row->reply_subject,
                'client' => $row->client ? ['id' => $row->client->id, 'name' => $row->client->name] : null,
                'created_at' => $row->created_at?->toIso8601String(),
                'has_reply' => $row->reply_body !== null && $row->reply_body !== '',
            ]);

        return response()->json($rows);
    }

    public function store(StoreClientInquiryRequest $request): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $data = $request->validated();

        try {
            $inquiry = $this->inquiries->analyze(
                $request->user(),
                (string) $data['body'],
                (string) $data['tone'],
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                isset($data['subject']) ? (string) $data['subject'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd analizy zapytania: '.$e->getMessage()], 422);
        }

        return response()->json($inquiry->toApiArray(), 201);
    }

    public function show(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);

        return response()->json($inquiry->load('client')->toApiArray());
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
                isset($data['extra_note']) ? (string) $data['extra_note'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Błąd pisania odpowiedzi: '.$e->getMessage()], 422);
        }

        return response()->json($inquiry->toApiArray());
    }

    private function assertOwner(Request $request, ClientInquiry $inquiry): void
    {
        if ((int) $inquiry->user_id !== (int) $request->user()->id) {
            abort(403, 'Brak dostępu do tego zapytania.');
        }
    }
}
