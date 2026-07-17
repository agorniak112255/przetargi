<?php

declare(strict_types=1);

use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\ProductMatchService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('email', 'arek@supon.local')->firstOrFail();

$tender = Tender::query()->create([
    'number' => 'PRZ/2026/TEST-AI',
    'title' => 'Test dopasowania AI',
    'client_id' => 1,
    'owner_id' => $user->id,
    'deadline' => '2026-08-01',
    'status' => 'draft',
    'ai_percent' => 0,
    'last_activity_at' => now(),
]);

TenderItem::query()->create([
    'tender_id' => $tender->id,
    'line_no' => 1,
    'requirement' => 'Rękawice antyprzecięciowe powlekane poliuretanem EN 388',
    'quantity' => 100,
    'status' => 'brak',
]);

TenderItem::query()->create([
    'tender_id' => $tender->id,
    'line_no' => 2,
    'requirement' => 'Rękawice barierowe do chemikaliów EN 374',
    'quantity' => 40,
    'status' => 'brak',
]);

$result = app(ProductMatchService::class)->matchTender($tender->fresh(), true);
$tender->refresh()->load('items.mainProduct');

echo json_encode([
    'create_ok' => true,
    'match' => $result,
    'items' => $tender->items->map(fn ($i) => [
        'req' => $i->requirement,
        'sku' => $i->mainProduct?->sku,
        'ai' => $i->ai_match_percent,
    ]),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

$token = $user->createToken('abc')->plainTextToken;
file_put_contents(__DIR__.'/../../token.txt', $token);
