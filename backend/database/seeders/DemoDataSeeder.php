<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $arek = User::query()->updateOrCreate(
            ['email' => 'arek@supon.local'],
            ['name' => 'Arek', 'password' => Hash::make('password'), 'role' => 'handlowiec']
        );
        $arek->syncPrimaryRole('handlowiec');

        $justyna = User::query()->updateOrCreate(
            ['email' => 'justyna@supon.local'],
            ['name' => 'Justyna', 'password' => Hash::make('password'), 'role' => 'handlowiec']
        );
        $justyna->syncPrimaryRole('handlowiec');

        $krzysiek = User::query()->updateOrCreate(
            ['email' => 'krzysiek@supon.local'],
            ['name' => 'Krzysiek', 'password' => Hash::make('password'), 'role' => 'kierownik']
        );
        $krzysiek->syncPrimaryRole('kierownik');

        $wojtek = User::query()->updateOrCreate(
            ['email' => 'wojtek@supon.local'],
            ['name' => 'Wojtek', 'password' => Hash::make('password'), 'role' => 'przetargi']
        );
        $wojtek->syncPrimaryRole('przetargi');

        $tomek = User::query()->updateOrCreate(
            ['email' => 'tomek@supon.local'],
            ['name' => 'Tomek', 'password' => Hash::make('password'), 'role' => 'dyrektor']
        );
        $tomek->syncPrimaryRole('dyrektor');

        $artur = User::query()->updateOrCreate(
            ['email' => 'artur@supon.local'],
            ['name' => 'Artur', 'password' => Hash::make('password'), 'role' => 'admin']
        );
        $artur->syncPrimaryRole('admin');

        $client = Client::query()->updateOrCreate(
            ['name' => 'Zakład Produkcji Metali Sp. z o.o.'],
            ['nip' => '8130000000', 'city' => 'Rzeszów', 'owner_id' => $arek->id]
        );

        Client::query()->updateOrCreate(
            ['name' => 'Sanitex Sp. z o.o.'],
            ['nip' => '5170000000', 'city' => 'Stalowa Wola', 'owner_id' => $arek->id]
        );

        $products = [
            ['sku' => 'ARĘKGLOMJ713', 'name' => 'Lebon POWERCUT/WH PU', 'manufacturer' => 'Lebon', 'category' => 'antyprzecieciowe', 'norms' => 'EN 388', 'catalog_price_net' => 21.99, 'discount_percent' => 38, 'purchase_price' => 13.63, 'stock' => 840],
            ['sku' => 'ARĘKPOWERFIT', 'name' => 'Lebon POWERFIT PU', 'manufacturer' => 'Lebon', 'category' => 'antyprzecieciowe', 'norms' => 'EN 388', 'catalog_price_net' => 19.99, 'discount_percent' => 38, 'purchase_price' => 12.39, 'stock' => 1100],
            ['sku' => 'ARĘKAWUNIDUR', 'name' => 'uvex UNIDUR 6641', 'manufacturer' => 'uvex', 'category' => 'antyprzecieciowe', 'norms' => 'EN 388', 'catalog_price_net' => 45.00, 'discount_percent' => 36, 'purchase_price' => 28.80, 'stock' => 318],
            ['sku' => 'ARĘK532', 'name' => 'Mapa KRYTECH 532', 'manufacturer' => 'MAPA', 'category' => 'zarekawki', 'norms' => 'EN 388', 'catalog_price_net' => 39.99, 'discount_percent' => 30, 'purchase_price' => 27.99, 'stock' => 45],
            ['sku' => 'ARKROSTWITERPRO', 'name' => 'Rostaing WINTERPRO', 'manufacturer' => 'Rostaing', 'category' => 'ocieplane', 'norms' => 'EN 511', 'catalog_price_net' => 38.00, 'discount_percent' => 35, 'purchase_price' => 24.70, 'stock' => 212],
            ['sku' => 'ARĘKTALLIN253', 'name' => 'RS ECO TEC', 'manufacturer' => 'R.S.', 'category' => 'skorzane', 'norms' => null, 'catalog_price_net' => 13.60, 'discount_percent' => 40, 'purchase_price' => 8.16, 'stock' => 1450],
            ['sku' => 'ARĘKCOMFOTEC', 'name' => 'RS Comfotec', 'manufacturer' => 'R.S.', 'category' => 'skorzane', 'norms' => null, 'catalog_price_net' => 10.50, 'discount_percent' => 40, 'purchase_price' => 6.30, 'stock' => 890],
            ['sku' => 'ARĘK53001', 'name' => 'Ansell AlphaTec 53-001', 'manufacturer' => 'Ansell', 'category' => 'chemoodporne', 'norms' => 'EN 374', 'catalog_price_net' => 56.00, 'discount_percent' => 32, 'purchase_price' => 38.08, 'stock' => 96],
            ['sku' => 'ARKNEOTOP128', 'name' => 'Ansell AlphaTec 29-500', 'manufacturer' => 'Ansell', 'category' => 'chemoodporne', 'norms' => 'EN 374', 'catalog_price_net' => 15.00, 'discount_percent' => 30, 'purchase_price' => 10.50, 'stock' => 240],
            ['sku' => 'ARĘKCARBON/10', 'name' => 'uvex UNIPUR CARBON ESD', 'manufacturer' => 'uvex', 'category' => 'esd', 'norms' => 'EN 16350', 'catalog_price_net' => 17.00, 'discount_percent' => 39, 'purchase_price' => 10.37, 'stock' => 520],
            ['sku' => 'ARKROSTCRIO', 'name' => 'Rostaing CRIO długie', 'manufacturer' => 'Rostaing', 'category' => 'kriogeniczne', 'norms' => null, 'catalog_price_net' => 279.00, 'discount_percent' => 25, 'purchase_price' => 209.25, 'stock' => 18],
        ];

        $bySku = [];
        foreach ($products as $row) {
            $bySku[$row['sku']] = Product::query()->updateOrCreate(
                ['sku' => $row['sku']],
                $row
            );
        }

        $subs = [
            [$bySku['ARĘKGLOMJ713'], $bySku['ARĘKPOWERFIT'], 'preferowany', 96, 'Ta sama klasa PU / EN 388', 'oczekuje', null],
            [$bySku['ARĘKGLOMJ713'], $bySku['ARĘKAWUNIDUR'], 'premium', 94, 'Wyższa odporność na przecięcie', 'oczekuje', null],
            [$bySku['ARĘKGLOMJ713'], $bySku['ARĘK532'], 'awaryjny', 88, 'Brak rozmiaru M na POWERCUT', 'oczekuje', null],
            [$bySku['ARĘKTALLIN253'], $bySku['ARĘKCOMFOTEC'], 'tanszy', 82, 'Niższa cena, podobna skóra', 'oczekuje', null],
            [$bySku['ARĘK53001'], $bySku['ARKNEOTOP128'], 'awaryjny', 88, 'Brak stanu 53-001', 'zatwierdzony', $krzysiek->id],
        ];

        foreach ($subs as [$main, $sub, $type, $pct, $reason, $status, $approvedBy]) {
            ProductSubstitute::query()->updateOrCreate(
                [
                    'main_product_id' => $main->id,
                    'substitute_product_id' => $sub->id,
                ],
                [
                    'type' => $type,
                    'match_percent' => $pct,
                    'norms_ok' => true,
                    'certs_ok' => true,
                    'reason' => $reason,
                    'approval_status' => $status,
                    'approved_by' => $approvedBy,
                ]
            );
        }

        $tender = Tender::query()->updateOrCreate(
            ['number' => 'PRZ/2026/0147'],
            [
                'title' => 'Pakiet rękawic ochronnych',
                'client_id' => $client->id,
                'owner_id' => $arek->id,
                'deadline' => '2026-02-28',
                'status' => 'wycena',
                'ai_percent' => 94,
                'offer_value_net' => 186420,
                'margin_percent' => 18.4,
                'last_activity_at' => now(),
            ]
        );

        $items = [
            [3, 'Antyprzecięciowe PU, EN 388', 'ARĘKGLOMJ713', 97, 2400, 16.90, 19.5],
            [7, 'Ocieplane −10 °C, EN 511', 'ARKROSTWITERPRO', 96, 600, 29.50, 16.3],
            [11, 'Monterskie kozia skóra', 'ARĘKTALLIN253', 99, 1200, 10.20, 20.0],
            [15, 'Barierowe chemikalia EN 374', 'ARĘK53001', 98, 360, 46.00, 17.2],
            [31, 'ESD EN 16350', 'ARĘKCARBON/10', 97, 480, 12.80, 19.0],
            [38, 'Kriogeniczne długie', 'ARKROSTCRIO', 80, 24, 250.00, 16.0, 'decyzja'],
        ];

        foreach ($items as $item) {
            TenderItem::query()->updateOrCreate(
                ['tender_id' => $tender->id, 'line_no' => $item[0]],
                [
                    'requirement' => $item[1],
                    'main_product_id' => $bySku[$item[2]]->id,
                    'ai_match_percent' => $item[3],
                    'quantity' => $item[4],
                    'offer_price' => $item[5],
                    'margin_percent' => $item[6],
                    'status' => $item[7] ?? 'matched',
                ]
            );
        }

        Tender::query()->updateOrCreate(
            ['number' => 'PRZ/2026/0132'],
            [
                'title' => 'ŚOI Q1',
                'client_id' => Client::query()->where('name', 'Sanitex Sp. z o.o.')->value('id'),
                'owner_id' => $arek->id,
                'deadline' => '2026-03-15',
                'status' => 'akceptacja_km',
                'ai_percent' => 91,
                'offer_value_net' => 92100,
                'margin_percent' => 17.1,
                'last_activity_at' => now()->subDay(),
            ]
        );

        unset($justyna);
    }
}
