<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\RealtimeConfig;
use Illuminate\Http\JsonResponse;

/**
 * Konfiguracja websocketu dla aplikacji i dodatku do Thunderbirda. Bez uprawnienia `chat`: dodatek słucha tym samym
 * połączeniem sygnału o pracy w kolejce (queue.updated). null = czas rzeczywisty wyłączony, klient odpytuje API.
 */
class RealtimeController extends Controller
{
    public function __invoke(RealtimeConfig $realtime): JsonResponse
    {
        return response()->json(['realtime' => $realtime->forClient()]);
    }
}
