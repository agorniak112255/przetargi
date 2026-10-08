<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ProductModelKey;
use App\Support\ProductSizeVariant;
use Tests\TestCase;

/**
 * Klucz modelu z nazwy karty (etap 2 opisów z cenników). Przypadki z prawdziwych kart Coby: pomiar „przed”
 * (SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv w katalogu projektu) i zrzut 714 kart cennika z 08.10.2026.
 */
final class ProductModelKeyTest extends TestCase
{
    public function test_stem_removes_only_sizes_dimensions_and_colour_words(): void
    {
        $sizes = new ProductSizeVariant;
        foreach ([
            'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)' => 'Orthomat Standard',
            'Orthomat Standard Czarny 0.9m x mb. (9.5mm)' => 'Orthomat Standard',
            'Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)' => 'Deckplate krawędzie',
            'Deckplate Czarny 0.9m x 18.3m (15mm)' => 'Deckplate',
            'Premier Track Otwarta Brązowy - 29cm x 44cm' => 'Premier Track Otwarta',
            'Premier Track Pełna Szary - 29cm x 44cm' => 'Premier Track Pełna',
            'Premier Rib Otwarta Czarny - 29cm x 44cm' => 'Premier Rib Otwarta',
            'COBAGRiP Krata GRP Zielony 2000mm x 1000mm x 25mm' => 'COBAGRiP Krata GRP',
            'COBAGRIP Light Szary 0.8m x 1.2m (3mm)' => 'COBAGRIP Light',
            'COBAGRiP Nakładka na schody Czarno/Żółta 1m x 345mm x 55mm' => 'COBAGRiP Nakładka na schody',
            'COBAGRiP Osłona krawędzi Żółta 1m x 55mm x 55mm' => 'COBAGRiP Osłona krawędzi',
            'Gripfoot Standard Taśma 50mm x 18.3m - Czarna' => 'Gripfoot Standard Taśma',
            'Gripfoot Standard Taśma 50mm x 18.3m - Clear (przezroczysty)' => 'Gripfoot Standard Taśma',
            'Gripfoot Standard Taśma 50mm x 18.3m - Żółto/Czarna' => 'Gripfoot Standard Taśma',
            'Senso Runner Szary 1m x mb. (3mm) - maks. 10m' => 'Senso Runner',
            'Senso Runner ESD Czarny 1.2m x 10m (3mm)' => 'Senso Runner ESD',
            'Akcesoria Krata GRP - Uchwyt typu C - 25mm' => 'Akcesoria Krata GRP Uchwyt typu C',
            // „L” przed wymiarem to typ uchwytu, nie rozmiar odzieżowy
            'Akcesoria Krata GRP - Uchwyt typu L - 38mm' => 'Akcesoria Krata GRP Uchwyt typu L',
            "Krawędź/narożnik 'męski' Czarny 85mm x 1m" => "Krawędź/narożnik 'męski'",
            "Krawędź/narożnik 'męski' Żółty (100% Nitryl) 75mm x 1m" => "Krawędź/narożnik 'męski' (100% Nitryl)",
            // „m” po liczbie to metr, nie rozmiar M
            'CablePro GP1 Czarny - 3 m' => 'CablePro GP1',
            'CablePro GP1 Czarny - 9 m' => 'CablePro GP1',
            'Loopermat Backed Medium Czarna Krawędź x mb. (8mm)' => 'Loopermat Backed Medium Krawędź',
            'Precision Nib Szary 2m x mb / krawędź dodatkowo płatna P249-C63-C09' => 'Precision Nib krawędź dodatkowo płatna P249-C63-C09',
            'COBAmat Standard 2222 Czarny 0.6m x mb. (12mm) - max. 10m' => 'COBAmat Standard 2222',
            'COBAmat Standard 2222 Czarny 0.6m x 5m (12mm)' => 'COBAmat Standard 2222',
            'Coir Natural 1m x mb. (17mm) - max. długość 6m' => 'Coir Natural',
            'Worksafe Nitryl - Niebieski 0.9 x 1.5m (16mm)' => 'Worksafe Nitryl',
            'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm)' => 'DeckStep Matting',
            'Vyna-Plush Czarny/Stalowy 0.9m x 1.2m' => 'Vyna-Plush',
            'Superdry Contract Ciemnoszary 1.8m x 12m / krawędź dodatkowo płatna' => 'Superdry Contract krawędź dodatkowo płatna',
            'Flexi-Deck Narożnik w opakowaniu 4 sztuki' => 'Flexi-Deck Narożnik w opakowaniu 4 sztuki',
            'First-Step' => 'First-Step',
            'VITAL 115' => 'VITAL 115',
            '' => '',
            // kontrprzykłady recenzenta (08.10.2026): litera typu i numer modelu na końcu nazwy nie są rozmiarem
            'Uchwyt typu L' => 'Uchwyt typu L',
            'Uchwyt typu M' => 'Uchwyt typu M',
            'Mata Model 5' => 'Mata Model 5',
            'Mata Model 10' => 'Mata Model 10',
            'Rękawice model 115' => 'Rękawice model 115',
            // rozmiar z etykietą i rozmiar po słowie innym niż „typu”/„model” schodzą jak dotąd
            'Rękawice rozmiar 10' => 'Rękawice',
            'Kurtka typu softshell L' => 'Kurtka typu softshell',
            // STAN FAKTYCZNY, nie reguła: „T5”/„T8” to dla ProductSizeVariant francuski znacznik rozmiaru (taille 5),
            // więc „Uchwyt T5” i „Uchwyt T8” mają ten sam rdzeń — rozdziela je co najwyżej rodzina SKU. Nazw tego
            // kształtu u Coby nie ma; wyjątek w regule rozmiarów psułby łączenie rozmiarów rękawic.
            'Uchwyt T5' => 'Uchwyt',
            'Uchwyt T8' => 'Uchwyt',
        ] as $name => $stem) {
            $this->assertSame($stem, ProductModelKey::stem($name, $sizes), $name);
        }
    }

    public function test_coba_cards_group_by_stem_and_sku_family(): void
    {
        $same = static fn (array $a, array $b): bool => $a[0] === $b[0];

        $orthomat = [$this->key('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'), $this->key('AF060003C', 'Orthomat Standard Czarny 0.9m x mb. (9.5mm)')];
        $this->assertTrue($same(...$orthomat), 'ten sam model: inny wymiar, kolor i cięcie na metry');
        $this->assertSame('coba|AF|orthomat standard', $orthomat[0][0]);
        $this->assertSame('Orthomat Standard', $orthomat[0][1]);

        $this->assertFalse($same($this->key('SD0107-6', 'Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)'), $this->key('DP0100-4', 'Deckplate Czarny 0.6m x 18.3m (15mm)')));
        $this->assertFalse($same($this->key('PT010501', 'Premier Track Otwarta Brązowy - 29cm x 44cm'), $this->key('PT010601C', 'Premier Track Pełna Szary - 29cm x 44cm')));
        $this->assertFalse($same($this->key('PR010101', 'Premier Rib Otwarta Czarny - 29cm x 44cm'), $this->key('PT010501', 'Premier Track Otwarta Brązowy - 29cm x 44cm')));

        $grp = array_unique([
            $this->key('GRP040001G', 'COBAGRiP Krata GRP Zielony 2000mm x 1000mm x 25mm')[0],
            $this->key('GRP070009G', 'COBAGRIP Krata GRP Żółty 3660mm x 1220mm x 50mm')[0],
            $this->key('GRP060003L', 'COBAGRIP Light Szary 0.8m x 1.2m (3mm)')[0],
            $this->key('GRP060002L', 'COBAGRiP Light Szary 1.2m x 1.2m (3mm)')[0],
            $this->key('GRP010704S', 'COBAGRiP Nakładka na schody Czarno/Żółta 1m x 345mm x 55mm')[0],
            $this->key('GRP070004N', 'COBAGRiP Osłona krawędzi Żółta 1m x 55mm x 55mm')[0],
        ]);
        $this->assertCount(4, $grp, 'krata / Light / nakładka / osłona to cztery modele; pisownia COBAGRIP i COBAGRiP nie rozdziela');

        $tape = array_unique([
            $this->key('GF010002', 'Gripfoot Standard Taśma 50mm x 18.3m - Czarna')[0],
            $this->key('GF120002', 'Gripfoot Standard Taśma 50mm x 18.3m - Clear (przezroczysty)')[0],
            $this->key('GF010702', 'Gripfoot Standard Taśma 50mm x 18.3m - Żółto/Czarna')[0],
        ]);
        $this->assertCount(1, $tape);
        $this->assertFalse($same($this->key('GF010002', 'Gripfoot Standard Taśma 50mm x 18.3m - Czarna'), $this->key('GF010003C', 'Gripfoot Special Taśma 102mm x 18.3m - Czarny')));

        $this->assertFalse($same($this->key('SN0100-7', 'Senso Runner Czarny 1m x 10m (3mm)'), $this->key('SN0100-9', 'Senso Runner ESD Czarny 1.2m x 10m (3mm)')));
        $this->assertTrue($same($this->key('SN0100-7', 'Senso Runner Czarny 1m x 10m (3mm)'), $this->key('SN060007C', 'Senso Runner Szary 1m x mb. (3mm) - maks. 10m')));
        $this->assertFalse($same($this->key('CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm'), $this->key('LCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu L - 38mm')));
        $this->assertTrue($same($this->key('LCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu L - 38mm'), $this->key('LCLIP-50', 'Akcesoria Krata GRP - Uchwyt typu L - 50mm')));
        $this->assertTrue($same($this->key('CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm'), $this->key('CCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu C - 38mm')), 'etap 2b: kod z myślnikiem w rodzinie kodu bez myślnika');
        $this->assertFalse($same($this->key('SS010002M', "Krawędź/narożnik 'męski' Czarny 85mm x 1m"), $this->key('SS070002B1M', "Krawędź/narożnik 'męski' Żółty (100% Nitryl) 75mm x 1m")));
        $this->assertTrue($same($this->key('LM010201', 'COBAwash Czarny/Niebieski 0.6m x 0.85m'), $this->key('LM010301', 'COBAwash Czarny/Czerwony 0.6m x 0.85m')));

        // rodzina SKU jest składnikiem: ten sam rdzeń w innej rodzinie to inny klucz
        $this->assertFalse($same($this->key('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'), $this->key('ZZ060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)')));

        // literowy przyrostek SKU rozdziela inne wykonanie o tej samej nazwie (pomiar na 714 kartach Coby: dokładnie
        // te trzy pary): Solid Fatigue-Step zwykły vs nitrylowy (inna odporność na oleje), krawędzie „męska”/„żeńska”
        // w wykonaniu B1
        $this->assertFalse($same($this->key('ST010001', 'Solid Fatigue-Step Czarny 0.6m x 0.9m (16mm)'), $this->key('ST010001B1', 'Solid Fatigue-Step Czarny 0.6m x 0.9m (16mm)')));
        $this->assertSame('coba|ST/B1|solid fatigue step', $this->key('ST010001B1', 'Solid Fatigue-Step Czarny 0.6m x 0.9m (16mm)')[0]);
        $this->assertFalse($same($this->key('SS070002MN', "Krawędź/narożnik 'męski' Żółty (100% Nitryl) 85mm x 1m"), $this->key('SS070002B1M', "Krawędź/narożnik 'męski' Żółty (100% Nitryl) 75mm x 1m")));
        $this->assertFalse($same($this->key('SS070002FN', "Krawędź/narożnik 'żeński' Żółty (100% Nitryl) 85mm x 1m"), $this->key('SS070002B1F', "Krawędź/narożnik 'żeński' Żółty (100% Nitryl) 75mm x 1m")));
        // postać sprzedaży „C” i końcówka „-N” nie są przyrostkiem — ten sam model
        $this->assertTrue($same($this->key('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)'), $this->key('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)')));
        $this->assertTrue($same($this->key('SD0107-6', 'Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)'), $this->key('SD0107-4', 'Deckplate Czarny/Żółte krawędzie 0.9m x 6m (15mm)')));
    }

    public function test_dashed_clip_codes_group_with_their_undashed_family(): void
    {
        // Etap 2b (pilotaż 08.10.2026, partia #499): cennik pisze „CCLIP-38”, strona producenta „CCLIP38”; „CCLIP-38”
        // nie pasowało do model_regex, dostawało rodzinę '' i razem z CCLIP-50 szło do osobnej grupy od CCLIP25 — jako
        // własny lider kończyło „ręcznie”. Uchwyty 25/38/50 jednego typu to jeden model, typy C/L/M — trzy modele.
        $groups = [];
        foreach (['C', 'L', 'M'] as $type) {
            $keys = array_unique([
                $this->key($type.'CLIP25', 'Akcesoria Krata GRP - Uchwyt typu '.$type.' - 25mm')[0],
                $this->key($type.'CLIP-38', 'Akcesoria Krata GRP - Uchwyt typu '.$type.' - 38mm')[0],
                $this->key($type.'CLIP-50', 'Akcesoria Krata GRP - Uchwyt typu '.$type.' - 50mm')[0],
            ]);
            $this->assertCount(1, $keys, 'uchwyt typu '.$type.': 25/38/50 to jeden model');
            $groups[] = reset($keys);
        }
        $this->assertSame(['coba|CCLIP|akcesoria krata grp uchwyt typu c', 'coba|LCLIP|akcesoria krata grp uchwyt typu l', 'coba|MCLIP|akcesoria krata grp uchwyt typu m'], $groups);
    }

    public function test_brand_without_model_profile_or_manufacturer_has_no_key(): void
    {
        $keys = app(ProductModelKey::class);
        $profiles = app(ManufacturerProfiles::class);

        $mapa = new Product(['sku' => '34115', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);
        $this->assertNull($profiles->for($mapa)?->modelGroup);
        $this->assertNull($keys->for($mapa), 'MAPA: numer w nazwie to model, bez grupowania');
        $this->assertNull($keys->for(new Product(['sku' => '34117', 'name' => 'VITAL 117', 'manufacturer' => 'MAPA'])));

        $this->assertNull($keys->for(new Product(['sku' => 'X-1', 'name' => 'Mata Szara 1m x 2m', 'manufacturer' => ''])), 'karta bez producenta');
        $this->assertNull($keys->for(new Product(['sku' => 'X-2', 'name' => 'Rękawice', 'manufacturer' => 'Nieznana Marka'])), 'marka bez profilu');

        $coba = $profiles->for(new Product(['sku' => 'AF060001', 'name' => 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', 'manufacturer' => 'Coba']));
        $this->assertSame('name_stem', $coba?->modelGroup);
        $this->assertSame(2, $coba?->modelMinMembers);
        $this->assertNull($keys->for(new Product(['sku' => 'X-3', 'name' => 'Szary 1m x 2m', 'manufacturer' => 'Coba'])), 'pusty rdzeń');
    }

    public function test_family_is_capture_of_profile_model_regex_with_letter_suffix_after_digits(): void
    {
        $regex = '/^([A-Z]+)\d/';
        $this->assertSame('AF', ProductModelKey::family('AF060001', $regex));
        $this->assertSame('GRP/G', ProductModelKey::family('grp040001g', $regex), 'przyrostek po cyfrach, wielkość liter bez znaczenia');
        $this->assertSame('P', ProductModelKey::family('P249-C63-C', $regex), 'przyrostek kończy się na pierwszym znaku spoza liter i cyfr');
        // etap 2b: wzorzec na SKU bez separatora między literą a cyfrą („LCLIP-38” = „LCLIP38” ze strony producenta);
        // do 08.10.2026 oczekiwane było '' i osobna grupa od LCLIP25
        $this->assertSame('LCLIP', ProductModelKey::family('LCLIP-38', $regex));
        $this->assertSame('CCLIP', ProductModelKey::family('CCLIP 50', $regex), 'spacja');
        $this->assertSame('CCLIP', ProductModelKey::family('cclip_38', $regex), 'podkreślenie, małe litery');
        $this->assertSame('', ProductModelKey::family('38-CCLIP', $regex), 'SKU nie pasuje do wzorca');
        $this->assertSame('', ProductModelKey::family('AF060001', null));
        $this->assertSame('AF0', ProductModelKey::family('AF060001', '/^[A-Z]+\d/'), 'bez przechwytu całe dopasowanie');

        // trzy pary z pomiaru na 714 kartach Coby, które sam przechwyt sklejał
        $this->assertSame('ST', ProductModelKey::family('ST010001', $regex));
        $this->assertSame('ST/B1', ProductModelKey::family('ST010001B1', $regex));
        $this->assertSame('SS/MN', ProductModelKey::family('SS070002MN', $regex));
        $this->assertSame('SS/B1M', ProductModelKey::family('SS070002B1M', $regex));
        $this->assertSame('SS/FN', ProductModelKey::family('SS070002FN', $regex));
        $this->assertSame('SS/B1F', ProductModelKey::family('SS070002B1F', $regex));
        // „C” po cyfrze to cięcie na metry (jak variant_suffixes), „-N” to rozmiar — nie przyrostek
        $this->assertSame('AF', ProductModelKey::family('AF060003C', $regex));
        $this->assertSame('PT', ProductModelKey::family('PT010601C', $regex));
        $this->assertSame('SD', ProductModelKey::family('SD0107-6', $regex));
        $this->assertSame('SS/M', ProductModelKey::family('SS010002M', $regex));
        $this->assertSame('CCLIP', ProductModelKey::family('CCLIP25', $regex));

        $key = $this->key('GRP040001G', 'COBAGRiP Krata GRP Zielony 2000mm x 1000mm x 25mm');
        $this->assertSame(['coba|GRP/G|cobagrip krata grp', 'COBAGRiP Krata GRP'], $key);
    }

    public function test_cards_of_the_measurement_before_group_into_45_models(): void
    {
        $path = base_path('../SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv');
        $this->assertFileExists($path);
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);
        fgetcsv($handle, 0, ';');
        $keyOf = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $keyOf[(int) $row[1]] = $this->key((string) $row[2], (string) $row[3])[0];
        }
        fclose($handle);

        $this->assertCount(101, $keyOf);
        // 44 po samym przechwycie rodziny; przyrostek SKU rozdziela krawędzie „żeńskie” SS070002FN i SS070002B1F
        $this->assertCount(45, array_unique($keyOf), 'liczba modeli wśród 101 kart pomiaru „przed”');
        $this->assertNotSame($keyOf[10585], $keyOf[10587], 'SS/FN i SS/B1F to inne wykonanie');
        // grupa 2 pomiaru: cztery szare Orthomaty to jeden model
        $this->assertCount(1, array_unique([$keyOf[10396], $keyOf[10397], $keyOf[10398], $keyOf[10399]]));
        $this->assertCount(17, array_keys($keyOf, $keyOf[11027], true), 'krata GRP: 17 kart w pomiarze');
        $this->assertNotSame($keyOf[11100], $keyOf[11103]);
        $this->assertNotSame($keyOf[11105], $keyOf[11100]);
        $this->assertNotSame($keyOf[10745], $keyOf[10747]);
        $this->assertNotSame($keyOf[11055], $keyOf[11061]);
        $this->assertSame($keyOf[11061], $keyOf[11062]);
        $this->assertNotSame($keyOf[10582], $keyOf[10586]);
        $this->assertSame($keyOf[10940], $keyOf[10947]);
        $this->assertNotSame($keyOf[10940], $keyOf[10958]);
    }

    /** @return array{string, string} [klucz, rdzeń] */
    private function key(string $sku, string $name): array
    {
        $key = app(ProductModelKey::class)->for(new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba']));
        $this->assertNotNull($key, $name);

        return [$key->key, $key->stem];
    }
}
