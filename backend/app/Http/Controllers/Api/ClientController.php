<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Clients\ClientList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * Krótka lista (wybór klienta w przetargu); `details=1` — pełne dane z ERP XL; `page` — zakładka Klienci: jedna
     * strona z wyszukiwaniem, filtrami i sortowaniem (ClientList).
     */
    public function index(Request $request, ClientList $list): JsonResponse
    {
        if ($request->has('page')) {
            $params = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', Rule::in(ClientList::PER_PAGE)],
                'q' => ['nullable', 'string', 'max:200'],
                'source' => ['nullable', Rule::in(ClientList::SOURCES)],
                'city' => ['nullable', 'string', 'max:200'],
                'manager' => ['nullable', 'string', 'max:200'],
                'sort' => ['nullable', Rule::in(ClientList::SORTS)],
                'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            ], [
                'page.*' => 'Numer strony musi być liczbą od 1.',
                'per_page.*' => 'Na stronie można pokazać 25, 50, 100 albo 200 klientów.',
                'q.max' => 'Wyszukiwany tekst może mieć najwyżej 200 znaków.',
                '*.in' => 'Nieznana wartość filtra albo sortowania.',
                '*.max' => 'Wartość filtra jest za długa.',
            ]);

            return response()->json($list->page($params));
        }

        $query = Client::query();
        // select przed withCount — późniejszy select zastąpiłby kolumnę tenders_count
        if (! $request->boolean('details')) {
            $query->select(['id', 'name', 'nip', 'city', 'owner_id']);
        }

        return response()->json(
            $query->with(['owner:id,name'])->withCount('tenders')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::query()->create([
            ...$data,
            'owner_id' => $request->user()->id,
        ]);

        return response()->json(
            $client->load(['owner:id,name'])->loadCount('tenders'),
            201
        );
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            // opiekun w aplikacji (karta klienta, cele handlowców — ClientAssignment); null = bez opiekuna
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ], [
            'owner_id.exists' => 'Wybrany opiekun nie ma konta w aplikacji.',
        ]);

        $client->update($data);

        return response()->json(
            $client->fresh()->load(['owner:id,name'])->loadCount('tenders')
        );
    }
}
