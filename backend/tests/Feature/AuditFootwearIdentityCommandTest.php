<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AuditFootwearIdentityCommand;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Raport tożsamości kart obuwia na przypadkach z cennika ARTRA (22.09.2026): payload ze strony wariantu
 * o innej klasie, zamienione tabele dostawcy ARMEN 900 6060 i karta bez klasy mimo tabeli.
 */
final class AuditFootwearIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_classifies_artra_cases_and_writes_recheck_file(): void
    {
        // 9495: sklep opisał wariant O1 FO ESD (bez podnoska) zamiast S1 P ESD
        $arcasio = $this->card('ARCASIO 732 616560 S1 P ESD', 'norma: EN ISO 20345:2011 S1 P SRC', [
            'source_urls' => ['https://natare.pl/sandaly-robocze-artra/7717-buty-robocze-sandaly-arcasio-732-616560-o1-fo-esd-artra.html'],
            'specs' => ['Kod produktu: ARCASIO 732 616560', 'Klasa ochrony: O1 FO ESD'],
            'attributes' => ['klasa_ochrony' => 'O1'],
        ]);
        // 9463: strona wariantu S3L, klasa w atrybutach wzięta z nazwy — łapie ją adres
        $aryel = $this->card('ARYEL 320 Air 618080 S1 PL ESD', 'norma: EN ISO 20345:2022 S1 PL FO SR', [
            'source_urls' => ['https://artra.pl/products/3815338-aryel-320-618080-s3l-esd?srsltid=AfmBOop4'],
            'norms' => ['EN ISO 20345:2022 S3L FO SR'],
            'attributes' => ['klasa_ochrony' => 'S1PL'],
        ]);
        // 9577: adres zgodny, ale treść to S2 CI; tabela dostawcy zamieniona z wariantem S1 P
        $armenO1 = $this->card('ARMEN 900 6060 O1 FO', 'norma: EN ISO 20345:2011 S1 P SRC', [
            'source_urls' => ['https://www.empik.com/buty-robocze-sandaly-armen-900-6060-o1-fo-artra-41-inna-inny,p1567230006,dom-i-ogrod-p'],
            'norms' => ['EN ISO 20345:2011 S2 CI SRC'],
            'specs' => ['Kod producenta: ARMEN 900 6060 O1 FO', 'Norma: EN ISO 20345:2011 S2 CI SRC'],
            'features' => ['Klasa S2 CI SRC'],
            'attributes' => ['klasa_ochrony' => 'S2'],
        ]);
        // 9576: payload zgodny z nazwą, tabela dostawcy O1 (20347) — tylko do weryfikacji
        $armenS1p = $this->card('ARMEN 900 6060 S1 P', "norma: EN ISO 20347:2012 O1 FO SRC\nnorma: ESD według EN IEC 61340-4-3:2018", [
            'source_urls' => ['https://www.empik.com/buty-robocze-sandaly-armen-900-6060-s1-p-artra-39-inna-inny,p1567230422'],
            'norms' => ['EN ISO 20345:2011 S1 P SRC'],
            'attributes' => ['klasa_ochrony' => 'S1P'],
        ]);
        // 9524: payload bez klasy, tabela ją podaje
        $armen9003 = $this->card('ARMEN 9003 2360 S1', 'norma: EN ISO 20345:2022 S1 FO SR', [
            'attributes' => ['klasa_ochrony' => null],
        ]);
        // bez fałszywych trafień: „S1-P” w nazwie = S1P, S3 z 2011 = S3S z 2022, strona z listą wariantów zostaje
        $polstar = $this->card('Półbuty BRYZA S1-P SRC', 'norma: EN ISO 20345:2022 S1PS FO SR', [
            'source_urls' => ['https://sklep.test/polbuty-bryza-s1-p-src.html'],
            'norms' => ['EN ISO 20345:2011 S1 P SRC'],
            'attributes' => ['klasa_ochrony' => 'S1P'],
        ]);
        $s3 = $this->card('Trzewik ARLES 947 S3', 'norma: EN ISO 20345:2022 S3S FO SR', [
            'source_urls' => ['https://sklep.test/arles-947-s3-o2-fo.html'],
            'norms' => ['EN ISO 20345:2011 S3 SRC'],
            'attributes' => ['klasa_ochrony' => 'S3'],
        ]);
        // bez klasy w nazwie — poza zakresem
        $noClass = $this->card('Trzewik roboczy ARTRA', 'norma: EN ISO 20347:2012 O2 FO', [
            'attributes' => ['klasa_ochrony' => null],
        ]);

        $ids = [$arcasio->id, $aryel->id, $armenO1->id, $armenS1p->id, $armen9003->id, $polstar->id, $s3->id, $noClass->id];
        $priceList = PriceList::query()->create([
            'original_filename' => 'artra.xlsx',
            'manufacturer' => 'ARTRA',
            'version' => '2026',
            'rows_total' => count($ids),
            'products_created' => count($ids),
            'product_ids' => $ids,
        ]);

        $command = app(AuditFootwearIdentityCommand::class);
        $foreign = AuditFootwearIdentityCommand::CATEGORY_FOREIGN_PAYLOAD;
        $table = AuditFootwearIdentityCommand::CATEGORY_SUPPLIER_TABLE;
        $missing = AuditFootwearIdentityCommand::CATEGORY_MISSING_CLASS;
        $categories = fn (Product $p): array => array_keys($command->audit($p->fresh())['findings'] ?? []);

        $this->assertSame([$foreign], $categories($arcasio));
        $this->assertSame([$foreign], $categories($aryel));
        $this->assertSame([$foreign, $table], $categories($armenO1));
        $this->assertSame([$table], $categories($armenS1p));
        $this->assertSame([$missing], $categories($armen9003));
        $this->assertSame([], $categories($polstar));
        $this->assertSame([], $categories($s3));
        $this->assertNull($command->audit($noClass->fresh()));

        $before = Product::query()->orderBy('id')->get(['id', 'name', 'enrichment_payload', 'updated_at'])->toArray();
        $out = storage_path('app/repair-backups/test-footwear-identity.txt');
        @unlink($out);
        $this->artisan('products:audit-footwear-identity', ['--price-list' => $priceList->id, '--out' => $out])
            ->expectsOutputToContain('Sprawdzone karty obuwia z klasą w nazwie/kodzie: 7')
            ->expectsOutputToContain($foreign.': 3')
            ->expectsOutputToContain($table.': 2')
            ->expectsOutputToContain($missing.': 1')
            ->expectsOutputToContain('Zapisano 3 kodów')
            ->assertSuccessful();
        // tylko raport
        $this->assertSame($before, Product::query()->orderBy('id')->get(['id', 'name', 'enrichment_payload', 'updated_at'])->toArray());

        // plik przyjmuje products:recheck-skus --file= (podgląd bez --apply)
        $this->artisan('products:recheck-skus', ['--file' => $out])
            ->expectsOutputToContain('Do ponownego wzbogacenia: 3 kart')
            ->doesntExpectOutputToContain('Nie ma w katalogu')
            ->assertSuccessful();
        @unlink($out);
    }

    public function test_datasheet_only_account_cards_stay_out_of_the_recheck_file(): void
    {
        // ARTRA opisuje karty wyłącznie z PDF — recheck-skus skasowałby opis i wzbogacił kartę z internetu
        $arcasio = $this->card('ARCASIO 732 616560 S1 P ESD', 'norma: EN ISO 20345:2011 S1 P SRC', [
            'source_urls' => ['https://natare.pl/sandaly-robocze-artra/7717-buty-robocze-sandaly-arcasio-732-616560-o1-fo-esd-artra.html'],
            'attributes' => ['klasa_ochrony' => 'O1'],
        ]);
        $other = $this->card('Trzewik BRYZA S3 SRC', 'norma: EN ISO 20345:2011 S3 SRC', [
            'source_urls' => ['https://sklep.test/trzewik-bryza-o2-fo.html'],
            'attributes' => ['klasa_ochrony' => 'O2'],
        ]);
        $account = B2bAccount::query()->create(['username' => 'ARTRA', 'sites' => ['artra.pl'], 'connector' => 'artra']);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => '3813781-arcasio-732-616560-s1-p-esd',
            'product_id' => $arcasio->id,
            'remote_sku' => $arcasio->sku,
            'remote_name' => $arcasio->name,
        ]);

        $out = storage_path('app/repair-backups/test-footwear-identity-pdf.txt');
        @unlink($out);
        $this->artisan('products:audit-footwear-identity', ['--manufacturer' => 'ARTRA', '--out' => $out])
            ->expectsOutputToContain(AuditFootwearIdentityCommand::CATEGORY_FOREIGN_PAYLOAD.': 1')
            ->expectsOutputToContain(AuditFootwearIdentityCommand::CATEGORY_DATASHEET_ONLY.': 1')
            ->expectsOutputToContain('NIE przepuszczaj przez products:recheck-skus')
            ->expectsOutputToContain('Zapisano 1 kodów')
            ->assertSuccessful();

        $file = (string) file_get_contents($out);
        $this->assertStringContainsString($other->sku, $file);
        $this->assertStringNotContainsString($arcasio->sku, $file);
        @unlink($out);
    }

    public function test_without_scope_it_refuses(): void
    {
        $this->artisan('products:audit-footwear-identity')
            ->expectsOutputToContain('Podaj --price-list')
            ->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function card(string $name, string $table, array $payload): Product
    {
        $product = Product::query()->create([
            'sku' => $name,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie robocze i ochronne',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'shop_fields_summary' => "Parametry\npodnosek: stalowy\n".$table,
            'enrichment_payload' => $payload,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        // rodzina asortymentu z pełnej karty — tu ustawiona wprost, bo test sprawdza klasę, nie rozpoznanie obuwia
        DB::table('products')->where('id', $product->id)->update(['ppe_family' => 'footwear']);

        return $product;
    }
}
