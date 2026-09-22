<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AuditSourceIdentityCommand as Audit;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Raport źródeł i opisów na przypadkach z audytu ręcznych cenników 22.09.2026: ROXY z opisem i źródłem MERU 3420-115,
 * nasz sklep jako jedyne źródło, CAPTCHA z confidence 0, „brak danych” w parametrach, „done” bez opisu i karta ARTRA
 * z opisem z PDF B2B, która nie może trafić do listy dla products:recheck-skus.
 */
final class AuditSourceIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MERU = 'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-CXS-MERU-powlekane-w-polowie-lateks-3420-115/28015';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['prestashop.shop_url' => 'https://supon.rzeszow.pl', 'enrichment.blocked_source_hosts' => ['supon.rzeszow.pl']]);
    }

    public function test_classifies_audit_cases_and_writes_recheck_file(): void
    {
        // 12596: ROXY 3420-007 opisana ze strony MERU 3420-115
        $roxy = $this->card('3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.', 'Canis',
            'Rękawice Canis CXS MERU 3420-115 to rękawice robocze powlekane w połowie lateksem, przeznaczone do prac '
            .'montażowych i magazynowych. Zapewniają pewny chwyt przedmiotów suchych i lekko wilgotnych.',
            ['source_urls' => [self::MERU], 'confidence' => 0.9]);
        // CEDERROTH: jedynym źródłem nasz sklep (eksport wysyła tam nasze opisy)
        $ownShopOnly = $this->card('390102', 'First Aid Kit LARGE', 'CEDERROTH',
            'Apteczka przenośna CEDERROTH First Aid Kit LARGE to walizkowa apteczka pierwszej pomocy do zakładów pracy, '
            .'wyposażona w opatrunki, plastry i nożyczki.',
            ['source_urls' => ['https://supon.rzeszow.pl/apteczki/390102-apteczka-cederroth-first-aid-kit-large.html'], 'confidence' => 0.9]);
        // nasz sklep tylko jako dodatkowe źródło — kategoria 2, ale nie do --out
        $ownShopExtra = $this->card('390103', 'First Aid Kit X-LARGE', 'CEDERROTH',
            'Apteczka przenośna CEDERROTH First Aid Kit X-LARGE to duża walizkowa apteczka pierwszej pomocy do zakładów pracy, '
            .'wyposażona w opatrunki, plastry i nożyczki.',
            ['source_urls' => ['https://www.cederroth.com/pl/first-aid-kit-x-large-390103', 'https://www.supon.rzeszow.pl/apteczki/390103.html'], 'confidence' => 0.9]);
        // 12505: opisem został zrzut strony z CAPTCHA, model dał confidence 0
        $captcha = $this->card('6110-009-800-00', 'Tool belt CXS, black', 'Canis',
            "Warning: This page maybe requiring CAPTCHA, please make sure you are authorized to access this page.\n"
            .'## Kontrola, zda je připojení bezpečné Než budeme pokračovat, musíme zkontrolovat zabezpečení vašeho připojení.',
            ['source_urls' => ['https://www.e-canis.cz/opasek-na-naradi-6110-009'], 'confidence' => 0]);
        // „brak danych” w pozycji parametrów — do przejrzenia, nie do --out
        $missing = $this->card('34115038', 'VITAL 115', 'MAPA',
            'Rękawice ochronne Vital 115 marki Mapa Professional to wodoodporne rękawice lateksowe przeznaczone do prac '
            .'wymagających precyzji i zręczności w nieagresywnych środowiskach.',
            ['source_urls' => ['https://www.mapa-pro.com/pl/rekawice/vital-115'], 'confidence' => 0.9, 'specs' => ['Materiał: lateks', 'Mankiet: brak danych']]);
        // „done” bez opisu — nie wraca do kolejki
        $doneEmpty = $this->card('BM0101', 'Bubblemat Czarny 0.9m x 1.2m', 'Coba', null, []);
        // kod z przecinkiem nie przejdzie przez --file (recheck-skus dzieli wiersz po przecinku)
        $comma = $this->card('CXS 3410,011', 'Rukavice ABRAK', 'Canis',
            'Soubory cookie používáme ke shromažďování a analýze informací o výkonu a používání webu a ke zlepšení obsahu.',
            ['source_urls' => ['https://www.e-canis.cz/rukavice-abrak'], 'confidence' => 0.8]);
        // karta bez uwag: źródło z kodem, opis nazywa wyrób
        $clean = $this->card('34650008', 'BUTOFLEX 650', 'MAPA',
            'Rękawice chemoodporne Butoflex 650 marki Mapa Professional wykonane z kauczuku butylowego chronią dłonie przed '
            .'ketonami, estrami i aldehydami podczas prac z chemikaliami.',
            ['source_urls' => ['https://www.mapa-pro.com/pl/rekawice/butoflex-650-34650008'], 'confidence' => 0.9]);

        $ids = [$roxy->id, $ownShopOnly->id, $ownShopExtra->id, $captcha->id, $missing->id, $doneEmpty->id, $comma->id, $clean->id];
        $priceList = $this->priceList($ids);

        $command = app(Audit::class);
        $categories = static fn (Product $p): array => array_keys($command->audit($p->fresh())['findings']);
        $this->assertEqualsCanonicalizing([Audit::CATEGORY_GATE, Audit::CATEGORY_FOREIGN_MODEL], $categories($roxy));
        $this->assertSame([Audit::CATEGORY_OWN_SHOP], $categories($ownShopOnly));
        $this->assertSame([Audit::CATEGORY_OWN_SHOP], $categories($ownShopExtra));
        $this->assertSame([], $command->audit($ownShopExtra->fresh())['out']);
        $this->assertContains(Audit::CATEGORY_RAW_PAGE, $categories($captcha));
        $this->assertStringContainsString('confidence 0', $command->audit($captcha->fresh())['findings'][Audit::CATEGORY_RAW_PAGE]);
        $this->assertStringContainsString('CAPTCHA', $command->audit($captcha->fresh())['findings'][Audit::CATEGORY_RAW_PAGE]);
        $this->assertSame([Audit::CATEGORY_MISSING_DATA], $categories($missing));
        $this->assertSame([], $command->audit($missing->fresh())['out']);
        $this->assertSame([Audit::CATEGORY_DONE_EMPTY], $categories($doneEmpty));
        $this->assertSame([], $command->audit($doneEmpty->fresh())['out']);
        $this->assertSame([], $categories($clean));

        $before = Product::query()->orderBy('id')->get(['id', 'description', 'enrichment_payload', 'enrichment_status', 'updated_at'])->toArray();
        $out = storage_path('app/repair-backups/test-source-identity.txt');
        @unlink($out);
        $this->artisan('products:audit-source-identity', ['--price-list' => $priceList->id, '--out' => $out])
            ->expectsOutputToContain('Sprawdzone karty: 8.')
            ->expectsOutputToContain('Do ponownego wzbogacenia (--out): 4 kart.')
            ->expectsOutputToContain('kasuje opis, normy, zdjęcia, dokumenty')
            ->expectsOutputToContain('wypada z dopasowania przetargowego')
            ->expectsOutputToContain('products:queue-enrichment --done-without-description')
            ->expectsOutputToContain('Zapisano 3 kodów')
            ->expectsOutputToContain('Pominięte (kod nie przejdzie przez --file): id '.$comma->id)
            ->assertSuccessful();
        // tylko raport
        $this->assertSame($before, Product::query()->orderBy('id')->get(['id', 'description', 'enrichment_payload', 'enrichment_status', 'updated_at'])->toArray());

        $file = (string) file_get_contents($out);
        foreach ([$roxy, $ownShopOnly, $captcha] as $listed) {
            $this->assertStringContainsString($listed->sku.'   # id '.$listed->id, $file);
        }
        foreach ([$ownShopExtra, $missing, $doneEmpty, $clean] as $notListed) {
            $this->assertStringNotContainsString('# id '.$notListed->id.':', $file);
        }

        // plik przyjmuje products:recheck-skus --file= (podgląd bez --apply)
        $this->artisan('products:recheck-skus', ['--file' => $out])
            ->expectsOutputToContain('Do ponownego wzbogacenia: 3 kart')
            ->doesntExpectOutputToContain('Nie ma w katalogu')
            ->assertSuccessful();
        @unlink($out);
    }

    public function test_cards_described_from_b2b_datasheets_stay_out_of_the_recheck_file(): void
    {
        // ARTRA: powiązanie z kontem łącznika, który opisuje karty wyłącznie z PDF
        $artra = $this->card('AROX 7333 641460 S1 PL ESD', 'AROX 7333 641460 S1 PL ESD', 'ARTRA',
            'Obuwie ochronne AROX 7333 641460 S1 PL ESD to półbuty typu metal free. Cena netto: 250,00 zł/szt.',
            ['source_urls' => ['https://artra.pl/cdn/shop/files/PL-KP-AROX_7333_641460_S1_PL_ESD.pdf'], 'confidence' => 0]);
        // Polstar: bez powiązania, ale opis z PDF B2B (ślad b2b_sources)
        $polstar = $this->card('BRYZA S1-P', 'Półbuty BRYZA S1-P SRC', 'Polstar',
            'Półbuty BRYZA S1-P SRC marki Polstar. Cena netto: 180,00 zł/szt.',
            ['source_urls' => ['https://polstar.com.pl/media/bryza.pdf'], 'confidence' => 0, 'b2b_sources' => ['described_at' => '2026-09-20 10:00:00']]);
        // zwykła karta z tym samym problemem — idzie do --out
        $plain = $this->card('RUSPSI', 'Okulary RUSH bezbarwne', 'Bolle',
            'Okulary ochronne Bolle RUSH z bezbarwnymi soczewkami. Cena netto: 40,00 zł/szt.',
            ['source_urls' => ['https://www.bolle-safety.com/rush-ruspsi.html'], 'confidence' => 0.9]);
        $account = B2bAccount::query()->create(['username' => 'ARTRA', 'sites' => ['artra.pl'], 'connector' => 'artra']);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => '3815678-arox-7333-641460-s1-pl-esd',
            'product_id' => $artra->id,
            'remote_sku' => $artra->sku,
            'remote_name' => $artra->name,
        ]);
        $priceList = $this->priceList([$artra->id, $polstar->id, $plain->id]);

        $out = storage_path('app/repair-backups/test-source-identity-pdf.txt');
        @unlink($out);
        $this->artisan('products:audit-source-identity', ['--price-list' => $priceList->id, '--out' => $out])
            ->expectsOutputToContain(Audit::CATEGORY_DATASHEET_ONLY.': 2')
            ->expectsOutputToContain('NIE przepuszczaj przez products:recheck-skus')
            ->expectsOutputToContain('b2b:redescribe-from-datasheets')
            ->expectsOutputToContain('Zapisano 1 kodów')
            ->assertSuccessful();

        $file = (string) file_get_contents($out);
        $this->assertStringContainsString($plain->sku.'   # id '.$plain->id, $file);
        $this->assertStringNotContainsString($artra->sku, $file);
        $this->assertStringNotContainsString($polstar->sku, $file);
        @unlink($out);
    }

    public function test_without_scope_it_refuses(): void
    {
        $this->artisan('products:audit-source-identity')
            ->expectsOutputToContain('Podaj --price-list')
            ->assertFailed();
    }

    /**
     * @param  list<int>  $ids
     */
    private function priceList(array $ids): PriceList
    {
        return PriceList::query()->create([
            'original_filename' => 'reczny.xlsx',
            'manufacturer' => 'mix',
            'version' => '2026',
            'rows_total' => count($ids),
            'products_created' => count($ids),
            'product_ids' => $ids,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function card(string $sku, string $name, string $manufacturer, ?string $description, array $payload): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'description' => $description,
            'enrichment_payload' => $payload === [] ? null : $payload,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
