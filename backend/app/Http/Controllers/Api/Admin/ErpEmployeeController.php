<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pracownicy ERP XL (opiekunowie klientów, clients.xl_manager_gid) do przypisania kontom: GET /admin/erp-employees.
 * Strumień D.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class ErpEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
