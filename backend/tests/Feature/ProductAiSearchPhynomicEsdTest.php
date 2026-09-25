<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Przypadek golden „uvex-phynomic-esd” na kartach w kształcie produkcyjnym (sonda S01, 25.09.2026): nazwy, opisy,
 * normy i atrybuty 1:1, category = null jak na produkcji. Dotąd 0 trafień — karta 6003805 („…airLite A ESD”) odpadała
 * na dowodzie żargonu [monta, antystaty, odzie], bo ESD stoi tylko w nazwie. Istniejący
 * ProductAiSearchPhynomicPerspectaTest tego nie łapał: jego atrapy mają category „Rękawice montażowe” (igła „monta”).
 */
final class ProductAiSearchPhynomicEsdTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_esd_phynomic_cards_are_found_and_non_esd_models_stay_out(): void
    {
        $this->seedProductionPhynomicCards();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        $result = $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10);
        $skus = array_map('strval', array_column($result['products'] ?? [], 'sku'));

        // Nazwany model z ESD na karcie rozstrzyga kod — model nie jest potrzebny (jak sonda W2 z 25.09).
        $this->assertSame(ProductAiSearchService::MODEL_STATE_SKIPPED, $result['model_state'] ?? null);
        $this->assertContains('6003805', $skus);
        $this->assertContains('6004806', $skus);
        foreach (['6004005', '6004105', '6004905', '6007005', '60040'] as $withoutEsd) {
            $this->assertNotContains($withoutEsd, $skus, $withoutEsd.' nie ma ESD ani normy antystatyki');
        }
    }

    private function seedProductionPhynomicCards(): void
    {
        $attrs = static fn (string $code, array $overrides = []): array => ['attributes' => array_merge([
            'kategoria_bhp' => 'rekawice',
            'kod_producenta' => $code,
            'material' => null,
            'materialy' => [],
            'normy_en' => [],
            'klasa_ochrony' => null,
            'rozmiar' => null,
            'poziomy_en388' => null,
            'typ_wyrobu' => null,
            'przeznaczenie' => null,
            'oznaczenia' => [],
            'rodzina_materialu' => null,
        ], $overrides)];

        $cards = [
            [
                'sku' => '6003805',
                'name' => 'Rękawice Phynomic airLite A ESD',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => 'uvex phynomic airLite - to najlżejsze rękawice ochronne w swojej klasie gwarantujące wysoki komfort noszenia, bardzo dobrą manualność, lekkość oraz niezwykłą oddychalność. Są idealne do precyzyjnej pracy, wymagającej również obsługi ekranów dotykowych.',
                'enrichment_payload' => $attrs('6003805', ['oznaczenia' => ['ESD']]),
            ],
            [
                'sku' => '6004005',
                'name' => 'Rękawice Phynomic lite',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => 'Uvex phynomic lite to najlżejsze rękawice ochronne w swojej klasie - redukujące uczucie zmęczenia. Wysoka odporność na ścieranie dzięki cienkiej, ale bardzo wytrzymałej, impregnacji hydropolimerowej. Zapewniają doskonały chwyt w suchych i lekko wilgotnych obszarach, wysoką oddychalność dzięki porowatej powłoce redukującą pocenie oraz wysoki poziom czucia przy pracy z małymi częściami.',
                'enrichment_payload' => $attrs('6004005', ['normy_en' => ['EN 388:2016'], 'rozmiar' => '5-12', 'typ_wyrobu' => 'coated', 'przeznaczenie' => 'food']),
            ],
            [
                'sku' => '6004105',
                'name' => 'Rękawice Phynomic lite W',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                // opis z sondy ucięty na 700 znakach — tak jak go zapisała
                'description' => "Rękawice ochronne uvex phynomic lite (nr kat. 6004105) to lekkie rękawice robocze przeznaczone do precyzyjnych prac wymagających dużej zręczności i czucia. Model „lite” został zaprojektowany z myślą o zadaniach, w których liczy się swoboda ruchów palców oraz minimalne obciążenie dłoni, np. w montażu, kontroli jakości czy pracach warsztatowych.\n\nRękawice wykonano z cienkiego, elastycznego materiału, który zapewnia wysoki komfort noszenia i doskonałą chwytność. Dzięki lekkości i dopasowaniu do dłoni sprawdzają się w pracach wymagających precyzji, a jednocześnie chronią przed otarciami i drobnymi uszkodzeniami mechanicznymi.\n\nProdukt należy do kategorii rękawic ochronnych i jest dostępny w rozm",
                'enrichment_payload' => $attrs('6004105', ['rozmiar' => 's']),
            ],
            [
                'sku' => '6004806',
                'name' => 'Rękawice uvex phynomic C XG ESD',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => 'Rękawice uvex phynomic chroniące przed przecięciem powstały w Niemczech, przy użyciu technik produkcji neutralnych pod względem emisji dwutlenku węgla. Powłoka Xtra-Grip zapewnia doskonałą przyczepność w zaolejonych miejscach i wysoką trwałość rękawicy. Ponadto rękawice są odpowiednie do obsługi wszystkich popularnych ekranów dotykowych, tabletów i telefonów komórkowych.',
                'enrichment_payload' => $attrs('6004806', ['typ_wyrobu' => 'cut', 'oznaczenia' => ['ESD']]),
            ],
            [
                'sku' => '6004905',
                'name' => 'Rękawice phynomic allround',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => 'Lekkie i odporne na zabrudzenia rękawice ochronne do ogólnych zastosowań mechanicznych. Bardzo dobra odporność na ścieranie mechaniczne dzięki odpornej na wilgoć piankowej powłoce hydropolimerowej. Doskonały chwyt w suchych i lekko wilgotnych obszarach, wysoki poziom czucia przy montażu części.',
                'enrichment_payload' => $attrs('6004905', ['normy_en' => ['EN 388:2016'], 'rozmiar' => '5-12', 'typ_wyrobu' => 'chemical', 'przeznaczenie' => 'chemical']),
            ],
            [
                'sku' => '6007005',
                'name' => 'Rękawice Phynomic XG',
                'category' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => 'Rękawice ochronne uvex phynomic XG to elastyczne i wyjątkowo trwałe rękawice montażowe zapewniające najlepszą chwytność w warunkach oleistych. Doskonała odporność na ścieranie mechaniczne dzięki powłoce hydropolimerowej Xtra Grip, doskonała chwytność w obszarach oleistych oraz wysoki poziom oddychalności dzięki porowatej powłoce piankowej. Gwarantują wysoki poziom czucia podczas montażu zaolejonych części.',
                'enrichment_payload' => $attrs('6007005'),
            ],
            [
                'sku' => '60040',
                'name' => 'Rękawice dziane Uvex Phynomic Lite (poliamid/elastan), kolor szary',
                'category' => 'Ochrona rąk',
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'description' => "Seria uvex phynomic obejmujące liczne modele jest optymalna do wszystkich prac, przy których wymagana jest precyzja. Te rękawice ochronne pasują jak „druga skóra”, są niezwykle lekkie i elastyczne – jednocześnie zapobiegają poceniu się rąk.\nWnętrze dłoni i czubki palców z hydropolimerową impregnacją\nDobra wytrzymałość na ścieralność mechaniczną dzięki bardzo cienkiej, ale trwałej impregnacji hydropolimerowej\nDobra chwytność w suchych i lekko wilgotnych obszarach\nBardzo dobra oddychalność dzięki powłoce o otwartych porach, ogranicza pocenie\nDoskonałe czucie przy pracy z małymi elementami\nIdealne dopasowanie dzięki zastosowaniu 3D Ergo Technology\nBardzo dobra tolerancja skórna potwierdzona der",
                'enrichment_payload' => null,
            ],
        ];

        foreach ($cards as $card) {
            // Ceny i stany nie pochodzą z sondy — wszystkie równe, żeby remis nie rozstrzygał się ceną.
            Product::query()->create($card + [
                'manufacturer' => 'UVEX',
                'norms' => null,
                'catalog_price_net' => 20,
                'purchase_price' => 10,
                'stock' => 5,
            ]);
        }
    }
}
