<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Presta\PrestaCategoryRewriteService;
use Illuminate\Console\Command;

final class RewritePrestaCategoriesCommand extends Command
{
    protected $signature = 'presta:rewrite-categories';

    protected $description = 'Wycina śmieciowe kategorie z cenników i przepisuje produkty na drzewo Presty';

    public function handle(PrestaCategoryRewriteService $rewrite): int
    {
        $result = $rewrite->rewrite();
        $this->info(sprintf(
            'Zaktualizowano %d, wyczyszczono %d, pominięto %d.',
            $result['updated'],
            $result['cleared'],
            $result['skipped']
        ));

        return self::SUCCESS;
    }
}
