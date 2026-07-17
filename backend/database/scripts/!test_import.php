<?php

declare(strict_types=1);

use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Tender::query()->where('id', 1)->update(['status' => 'wycena']);

$user = User::query()->where('email', 'arek@supon.local')->firstOrFail();
$token = $user->createToken('import-test')->plainTextToken;
file_put_contents(__DIR__.'/../../token.txt', $token);
echo $token, PHP_EOL;
