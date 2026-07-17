<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = App\Models\User::query()->firstOrFail()->createToken('bench')->plainTextToken;

foreach (['/api/me', '/api/products?per_page=100', '/api/dashboard'] as $path) {
    for ($i = 1; $i <= 3; $i++) {
        $t = microtime(true);
        $r = Illuminate\Support\Facades\Http::withToken($token)->get('http://127.0.0.1:8000'.$path);
        echo $path.' #'.$i.' '.$r->status().' '.round((microtime(true) - $t) * 1000).'ms'.PHP_EOL;
    }
}
