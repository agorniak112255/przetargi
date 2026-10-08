<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ModelImagePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zdjęcie dla członka modelu (etap 2 opisów z cenników): adres strony w kolorze karty (zbiór kolorów + słowo rdzenia
 * w nazwie pliku), kopia zdjęcia lidera albo nic.
 */
final class ModelImagePickerTest extends TestCase
{
    use RefreshDatabase;

    private const BLACK = 'https://www.coba.com/wp-content/uploads/2020/02/af-orthomat-standard-workplace-matting-black-1.jpg';

    private const GREY = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Gray.jpg';

    private const ORTHOMAT_GREY = 'https://www.coba.com/wp-content/uploads/2020/02/af-orthomat-standard-workplace-matting-grey-1.jpg';

    private const NEUTRAL = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/Solid-Fatigue-Step-Leisure_07.jpg';

    private const ORTHOMAT_NEUTRAL = 'https://www.coba.com/wp-content/uploads/2020/02/AF010003C_OrthomatStd_09xLinear.jpg';

    public function test_page_image_with_member_colour_and_model_stem_in_file_name_wins(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)');
        $member = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $leaderImage = $this->image($leader, self::BLACK);

        $pick = (new ModelImagePicker)->pickFor($member, ['grey'], [self::NEUTRAL, self::BLACK, self::ORTHOMAT_GREY], [$leaderImage], 'Orthomat Standard');

        $this->assertSame(self::ORTHOMAT_GREY, $pick['url']);
        $this->assertNull($pick['copy_of']);
        $this->assertSame(ModelImagePicker::REASON_PAGE_IN_COLOUR, $pick['reason']);

        // bez rdzenia — bez warunku słowa w nazwie pliku („Gray.jpg” wystarcza); kolor karty podany słowem z nazwy
        $this->assertSame(self::GREY, (new ModelImagePicker)->pickFor($member, ['Szary'], [self::BLACK, self::GREY], [], null)['url']);
        $this->assertSame(self::GREY, (new ModelImagePicker)->pickFor($member, ['grey'], [self::BLACK, self::GREY], [], '')['url']);
        // rdzeń bez słowa dłuższego niż 2 znaki — też bez warunku
        $this->assertSame(self::GREY, (new ModelImagePicker)->pickFor($member, ['grey'], [self::GREY], [], 'GP')['url']);
    }

    public function test_page_image_without_model_stem_in_file_name_does_not_count_even_as_other_colour(): void
    {
        // galeria strony Orthomata to wszystkie <img> tej strony, także cudzych wyrobów: „cobagrip-grey.jpg” nie idzie
        // na szarą kartę Orthomata, a „Gray.jpg” bez rdzenia nie liczy się jako „inny kolor” — zostaje kopia lidera
        $greyLeader = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)');
        $member = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $leaderImage = $this->image($greyLeader, self::ORTHOMAT_NEUTRAL);
        $foreign = 'https://www.coba.com/wp-content/uploads/2024/09/cobagrip-grey.jpg';
        $foreignBlack = 'https://www.coba.com/wp-content/uploads/2024/09/cobagrip-black.jpg';

        $pick = (new ModelImagePicker)->pickFor($member, ['grey'], [$foreign, $foreignBlack, self::GREY], [$leaderImage], 'Orthomat Standard');
        $this->assertNull($pick['url']);
        $this->assertSame((int) $leaderImage->id, (int) $pick['copy_of']?->id);
        $this->assertSame(ModelImagePicker::REASON_LEADER_COPY, $pick['reason']);

        // z rdzeniem w nazwie pliku, ale tylko w innym kolorze — bez nowego zdjęcia i bez kopii lidera
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            (new ModelImagePicker)->pickFor($member, ['grey'], [$foreign, self::BLACK], [$leaderImage], 'Orthomat Standard')
        );
        // słowo rdzenia bez polskich znaków i wielkości liter: „Krawędź/narożnik 'męski'” → „krawedz”
        $edgeMember = $this->coba('SS010002M', "Krawędź/narożnik 'męski' Czarny 85mm x 1m");
        $edgeUrl = 'https://www.coba.com/wp-content/uploads/2024/09/Krawedz-meski-Black.jpg';
        $this->assertSame($edgeUrl, (new ModelImagePicker)->pickFor($edgeMember, ['black'], [self::BLACK, $edgeUrl], [], "Krawędź/narożnik 'męski'")['url']);
    }

    public function test_two_colour_card_takes_only_file_with_the_same_colour_set(): void
    {
        $leader = $this->coba('LM010501', 'COBAwash Czarny/Brązowy 0.6m x 0.85m');
        $member = $this->coba('LM010201', 'COBAwash Czarny/Niebieski 0.6m x 0.85m');
        $black = 'https://www.coba.com/wp-content/uploads/2020/02/cobawash-black.jpg';
        $blackBrown = 'https://www.coba.com/wp-content/uploads/2020/02/cobawash-black-brown.jpg';
        $blueBlack = 'https://www.coba.com/wp-content/uploads/2020/02/cobawash-blue-black.jpg';
        $leaderImage = $this->image($leader, $blackBrown);

        // równy zbiór bez względu na kolejność; „…-black.jpg” (jednobarwny) nie wystarcza
        $pick = (new ModelImagePicker)->pickFor($member, ['black', 'blue'], [$black, $blackBrown, $blueBlack], [$leaderImage], 'COBAwash');
        $this->assertSame($blueBlack, $pick['url']);
        $this->assertSame(ModelImagePicker::REASON_PAGE_IN_COLOUR, $pick['reason']);

        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            (new ModelImagePicker)->pickFor($member, ['black', 'blue'], [$black, $blackBrown], [$leaderImage], 'COBAwash'),
            'tylko inne zbiory w galerii — także jednobarwny czarny'
        );

        // bez adresów z kolorem: kopia lidera tylko przy równym zbiorze — lider Czarny/Brązowy nie idzie na Czarny/Niebieski
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_LEADER_OTHER_COLOUR],
            (new ModelImagePicker)->pickFor($member, ['black', 'blue'], [], [$leaderImage], 'COBAwash')
        );
        $neutralFileOfTwoColourLeader = $this->image($leader, 'https://www.coba.com/wp-content/uploads/2020/02/cobawash_01.jpg');
        $this->assertSame(
            ModelImagePicker::REASON_LEADER_OTHER_COLOUR,
            (new ModelImagePicker)->pickFor($member, ['black', 'blue'], [], [$neutralFileOfTwoColourLeader], 'COBAwash')['reason'],
            'plik bez koloru, ale karta lidera dwubarwna w innym zestawie'
        );
        $sameSetLeader = $this->coba('LM010202', 'COBAwash Niebieski/Czarny 0.85m x 1.5m');
        $sameSetImage = $this->image($sameSetLeader, 'https://www.coba.com/wp-content/uploads/2020/02/cobawash_02.jpg');
        $this->assertSame((int) $sameSetImage->id, (int) (new ModelImagePicker)->pickFor($member, ['black', 'blue'], [], [$sameSetImage], 'COBAwash')['copy_of']?->id);

        // COBAtape Biała vs Biało/Czerwona: zdjęcie białej taśmy nie idzie na biało-czerwoną i odwrotnie
        $whiteLeader = $this->coba('TP010002', 'COBAtape Biała 50mm x 18.3m');
        $whiteImage = $this->image($whiteLeader, 'https://www.coba.com/wp-content/uploads/2020/02/cobatape-white.jpg');
        $whiteRed = $this->coba('TP010502', 'COBAtape Biało/Czerwona 50mm x 18.3m');
        $this->assertSame(
            ModelImagePicker::REASON_LEADER_OTHER_COLOUR,
            (new ModelImagePicker)->pickFor($whiteRed, ['white', 'red'], [], [$whiteImage], 'COBAtape')['reason']
        );
        $whiteRedImage = $this->image($whiteRed, 'https://www.coba.com/wp-content/uploads/2020/02/cobatape-white-red.jpg');
        $this->assertSame(
            ModelImagePicker::REASON_LEADER_OTHER_COLOUR,
            (new ModelImagePicker)->pickFor($whiteLeader, ['white'], [], [$whiteRedImage], 'COBAtape')['reason']
        );
    }

    public function test_no_colour_in_page_urls_copies_leader_image_unless_leader_colour_differs(): void
    {
        $leaderNeutral = $this->coba('WC0000-4', 'First-Step');
        $member = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $neutralImage = $this->image($leaderNeutral, self::NEUTRAL);

        $copy = (new ModelImagePicker)->pickFor($member, ['grey'], [self::NEUTRAL], [$neutralImage], 'Orthomat Standard');
        $this->assertNull($copy['url']);
        $this->assertSame((int) $neutralImage->id, (int) $copy['copy_of']?->id);
        $this->assertSame(ModelImagePicker::REASON_LEADER_COPY, $copy['reason']);

        // plik lidera nazwany innym kolorem
        $leaderBlack = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)');
        $blackFile = $this->image($leaderBlack, self::BLACK);
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_LEADER_OTHER_COLOUR],
            (new ModelImagePicker)->pickFor($member, ['grey'], [], [$blackFile], 'Orthomat Standard')
        );

        // plik bez koloru w nazwie, ale karta lidera „Czarny” — zdjęcie czarnej maty nie idzie na szarą
        $blackCardNeutralFile = $this->image($leaderBlack, self::NEUTRAL);
        $this->assertSame(
            ModelImagePicker::REASON_LEADER_OTHER_COLOUR,
            (new ModelImagePicker)->pickFor($member, ['grey'], [self::NEUTRAL], [$blackCardNeutralFile], 'Orthomat Standard')['reason']
        );
        // ten sam kolor karty lidera — kopia
        $greyLeader = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)');
        $greyCardNeutralFile = $this->image($greyLeader, self::NEUTRAL);
        $this->assertSame((int) $greyCardNeutralFile->id, (int) (new ModelImagePicker)->pickFor($member, ['grey'], [], [$greyCardNeutralFile], 'Orthomat Standard')['copy_of']?->id);
        // słowo spoza słownika kolorów nie jest kolorem — jak karta bez koloru: galeria nie rozstrzyga, kopia lidera
        $unknown = (new ModelImagePicker)->pickFor($member, ['melanż'], [self::GREY, self::BLACK], [$neutralImage], null);
        $this->assertSame((int) $neutralImage->id, (int) $unknown['copy_of']?->id);
        $this->assertSame(ModelImagePicker::REASON_LEADER_COPY, $unknown['reason']);
    }

    public function test_member_without_colour_copies_first_leader_image_and_leader_without_images_gives_nothing(): void
    {
        $leader = $this->coba('CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm');
        $member = $this->coba('CCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu C - 38mm');
        $first = $this->image($leader, self::BLACK);
        $second = $this->image($leader, self::GREY);

        $pick = (new ModelImagePicker)->pickFor($member, [], [self::GREY, self::BLACK], [$first, $second], 'Akcesoria Krata GRP Uchwyt typu C');
        $this->assertNull($pick['url'], 'bez koloru karty adres strony nie rozstrzyga — kopia bez sieci');
        $this->assertSame((int) $first->id, (int) $pick['copy_of']?->id);
        $this->assertSame(ModelImagePicker::REASON_LEADER_COPY, $pick['reason']);
        // puste słowa koloru to brak koloru
        $this->assertSame((int) $first->id, (int) (new ModelImagePicker)->pickFor($member, ['', ' '], [self::GREY], [$first], null)['copy_of']?->id);

        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_LEADER_NONE],
            (new ModelImagePicker)->pickFor($member, [], [self::NEUTRAL], [], null)
        );
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_LEADER_NONE],
            (new ModelImagePicker)->pickFor($member, ['grey'], [self::NEUTRAL], [], 'Orthomat Standard')
        );
    }

    private function coba(string $sku, string $name): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba']);
    }

    private function image(Product $product, string $sourceUrl): ProductImage
    {
        $sort = ProductImage::query()->where('product_id', $product->id)->count();

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => 'products/'.$product->id.'/'.$sort.'.jpg', 'source_url' => $sourceUrl,
            'is_primary' => $sort === 0, 'sort_order' => $sort, 'checksum' => hash('sha256', $product->id.'|'.$sourceUrl),
        ]);
    }
}
