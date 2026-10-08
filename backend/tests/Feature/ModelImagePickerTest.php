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
 * w nazwie pliku albo nazwa z samych słów koloru, cyfr i tokenów technicznych — etap 2b), kopia zdjęcia lidera albo nic.
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
        // na szarą kartę Orthomata i nie liczy się jako „inny kolor” — zostaje kopia lidera
        $greyLeader = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)');
        $member = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $leaderImage = $this->image($greyLeader, self::ORTHOMAT_NEUTRAL);
        $foreign = 'https://www.coba.com/wp-content/uploads/2024/09/cobagrip-grey.jpg';
        $foreignBlack = 'https://www.coba.com/wp-content/uploads/2024/09/cobagrip-black.jpg';

        $pick = (new ModelImagePicker)->pickFor($member, ['grey'], [$foreign, $foreignBlack], [$leaderImage], 'Orthomat Standard');
        $this->assertNull($pick['url']);
        $this->assertSame((int) $leaderImage->id, (int) $pick['copy_of']?->id);
        $this->assertSame(ModelImagePicker::REASON_LEADER_COPY, $pick['reason']);
        // etap 2b: „Gray.jpg” — nazwa z samego koloru — liczy się jak nazwa z rdzeniem (do 08.10.2026 nie liczyła się wcale)
        $this->assertSame(
            [self::GREY, ModelImagePicker::REASON_PAGE_IN_COLOUR],
            array_values(array_intersect_key((new ModelImagePicker)->pickFor($member, ['grey'], [$foreign, $foreignBlack, self::GREY], [$leaderImage], 'Orthomat Standard'), array_flip(['url', 'reason'])))
        );

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

    public function test_file_named_only_by_colour_counts_like_a_file_with_the_model_stem(): void
    {
        // Etap 2b (pilotaż 08.10.2026, partia #499): galeria strony kraty COBAGRiP ma „Yellow-1.jpg” bez słowa „cobagrip”
        // — żółte kraty zostały bez zdjęcia. Nazwa z samych słów koloru, cyfr i tokenów technicznych liczy się jak nazwa
        // ze słowem rdzenia.
        $leader = $this->coba('GRP040001G', 'COBAGRiP Krata GRP Zielony 2000mm x 1000mm x 25mm');
        $member = $this->coba('GRP070009G', 'COBAGRIP Krata GRP Żółty 3660mm x 1220mm x 50mm');
        $leaderImage = $this->image($leader, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Green-1.jpg');
        $yellow = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1.jpg';
        $stem = 'COBAGRiP Krata GRP';
        $picker = new ModelImagePicker;

        $pick = $picker->pickFor($member, ['yellow'], [self::NEUTRAL, self::GREY, $yellow], [$leaderImage], $stem);
        $this->assertSame([$yellow, null, ModelImagePicker::REASON_PAGE_IN_COLOUR], [$pick['url'], $pick['copy_of'], $pick['reason']]);

        // dopiski techniczne WordPressa („-1000x1000”, „-scaled”, „-e1696234567”), rozszerzenia i „img” nie są słowem wyrobu
        foreach ([
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1-1000x1000.jpg',
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/yellow-2-scaled.webp',
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1-e1696234567.png',
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/img_%C5%BC%C3%B3%C5%82ty_01.jpg',
        ] as $url) {
            $this->assertSame($url, $picker->pickFor($member, ['yellow'], [$url], [$leaderImage], $stem)['url'], $url);
        }
        // nazwa z samymi innymi kolorami to „inny kolor” — bez nowego zdjęcia i bez kopii zielonego lidera
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            $picker->pickFor($member, ['yellow'], ['https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/black-grey-2-scaled.jpg', self::GREY], [$leaderImage], $stem)
        );
        // słowo innego wyrobu („deckplate-yellow.jpg” na stronie kraty) albo spoza listy („yellow-copy.jpg”) — nazwa nie
        // liczy się wcale; zostaje reguła lidera: „Green-1.jpg” zielonego lidera nie idzie na żółtą kartę
        $this->assertSame(
            ModelImagePicker::REASON_LEADER_OTHER_COLOUR,
            $picker->pickFor($member, ['yellow'], [
                'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/deckplate-yellow.jpg',
                'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/yellow-copy.jpg',
            ], [$leaderImage], $stem)['reason']
        );
    }

    public function test_colour_only_file_name_counts_only_on_manufacturer_host_and_never_as_swatch_thumbnail_or_clear(): void
    {
        // Przegląd etapu 2b: nazwa z samych kolorów łapała próbki kolorów sklepów, miniatury i zaślepki — członek dostawał
        // próbkę jako zdjęcie wyrobu albo „inny kolor” (bez zdjęcia, z force usunięcie starego).
        // lider żółty z plikiem bez koloru — gdy galeria nie rozstrzyga, wynikiem jest kopia lidera
        $leader = $this->coba('GRP070001G', 'COBAGRiP Krata GRP Żółty 2000mm x 1000mm x 25mm');
        $member = $this->coba('GRP070009G', 'COBAGRIP Krata GRP Żółty 3660mm x 1220mm x 50mm');
        $leaderImage = $this->image($leader, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/cobagrip-grating-1.jpg');
        $stem = 'COBAGRiP Krata GRP';
        $picker = new ModelImagePicker;
        $copy = fn (array $urls): array => $picker->pickFor($member, ['yellow'], $urls, [$leaderImage], $stem);
        $leaderCopy = static fn (array $pick): array => [$pick['url'], $pick['reason']];

        // (a) sklep: nazwa z samego koloru bez słowa rdzenia nie liczy się — ani „w kolorze”, ani „inny kolor”
        $this->assertSame([null, ModelImagePicker::REASON_LEADER_COPY], $leaderCopy($copy(['https://sklep.example/img/yellow.jpg'])));
        $this->assertSame([null, ModelImagePicker::REASON_LEADER_COPY], $leaderCopy($copy(['https://sklep.example/img/black.png'])));
        // ze słowem rdzenia sklep liczy się jak dawniej
        $shopStem = 'https://sklep.example/img/cobagrip-yellow.jpg';
        $this->assertSame($shopStem, $copy([$shopStem])['url']);

        // (b) próbki kolorów i małe miniatury — nawet na hoście producenta i ze słowem rdzenia
        foreach ([
            'https://www.coba.com/media/attribute/swatch/swatch_image/30x20/y/e/yellow.png',
            'https://www.coba.com/media/attribute/swatches/yellow.png',
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1-300x300.jpg',
            'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/cobagrip-yellow-150x150.jpg',
            'https://www.coba.com/media/catalog/product/cache/1/265x265/cobagrip-yellow.jpg',
        ] as $url) {
            $this->assertSame([null, ModelImagePicker::REASON_LEADER_COPY], $leaderCopy($copy([$url])), $url);
        }
        // próbka w innym kolorze nie robi „innego koloru”
        $this->assertSame([null, ModelImagePicker::REASON_LEADER_COPY], $leaderCopy($copy(['https://sklep.example/media/attribute/swatch/cobagrip-black.png'])));
        // duża wersja (≥ 600 px) zostaje
        $big = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1-1000x1000.jpg';
        $this->assertSame($big, $copy([$big])['url']);
        $this->assertSame($big, $copy(['https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1-300x300.jpg', $big])['url']);

        // (c) „transparent”/„clear” jako jedyny kolor nazwy to zaślepka — bez słowa rdzenia nie liczy się (także dla
        // przezroczystej karty), a nazwa z przezroczystym i innym kolorem tak
        $clearMember = $this->coba('GF120002', 'Gripfoot Standard Taśma 50mm x 18.3m - Clear (przezroczysty)');
        foreach (['transparent.png', 'clear.gif', 'clear-1.jpg'] as $file) {
            $pick = $picker->pickFor($clearMember, ['clear'], ['https://www.coba.com/wp-content/uploads/'.$file], [], 'Gripfoot Standard Taśma');
            $this->assertSame([null, ModelImagePicker::REASON_LEADER_NONE], [$pick['url'], $pick['reason']], $file);
        }
        $this->assertSame(
            ModelImagePicker::REASON_PAGE_OTHER_COLOUR,
            $copy(['https://www.coba.com/wp-content/uploads/black-transparent.png'])['reason'],
            'kolor obok przezroczystego liczy się'
        );
        $withStem = 'https://www.coba.com/wp-content/uploads/gripfoot-clear.jpg';
        $this->assertSame($withStem, $picker->pickFor($clearMember, ['clear'], [$withStem], [], 'Gripfoot Standard Taśma')['url']);

        // „Yellow-1.jpg” z coba.com dalej w kolorze karty
        $this->assertSame(ModelImagePicker::REASON_PAGE_IN_COLOUR, $copy(['https://www.coba.com/pl/wp-content/uploads/sites/6/2024/09/Yellow-1.jpg'])['reason']);
    }

    public function test_coba_file_names_with_card_code_and_abbreviated_colours(): void
    {
        // Ponowny audyt Coby 08.10.2026 (partia #501): galeria lidera Orthomat Diamond (page_image_urls wersji #1363,
        // prawdziwe adresy) — „BlkYel” nie był kolorem, wszystkie karty Czarny/Żółte dostały czarne
        // af-orthomat-diamond-workplace-matting-1.jpg
        $c = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/';
        $gallery = [
            $c.'af-orthomat-diamond-workplace-matting-1.jpg',
            $c.'DAF010701_Orthomat_Diamond_BlkYel_06x09.jpg',
            $c.'af-orthomat-diamond-workplace-matting-2.jpg',
            $c.'DAF010001_Orthomat_Diamond_Blk_06x09.jpg',
            $c.'af-orthomat-diamond-workplace-matting-safety-3.jpg',
            $c.'DAF010703C_OrthomatDiamond_09xLinear_BlkYel.jpg',
        ];
        $stem = 'Orthomat Diamond krawędzie';
        $picker = new ModelImagePicker;
        $leader = $this->coba('DAF010701', 'Orthomat Diamond Czarny/Żółte krawędzie 0.6m x 0.9m (9.5mm)');
        $member = $this->coba('DAF0107-4', 'Orthomat Diamond Czarny/Żółte krawędzie 0.6m x 18.3m (9.5mm)');
        $exactMember = $this->coba('DAF010703C', 'Orthomat Diamond Czarny/Żółte krawędzie 0.9m x mb. (9.5mm)');
        $leaderImage = $this->image($leader, $gallery[0]);

        $this->assertSame([$gallery[1], ModelImagePicker::REASON_PAGE_IN_COLOUR], $this->urlAndReason($picker->pickFor($leader, ['black', 'yellow'], $gallery, [], $stem)));
        $this->assertSame([$gallery[1], ModelImagePicker::REASON_PAGE_IN_COLOUR], $this->urlAndReason($picker->pickFor($member, ['black', 'yellow'], $gallery, [$leaderImage], $stem)));
        // plik z pełnym kodem TEJ karty wygrywa z wcześniejszym plikiem w tym samym kolorze
        $this->assertSame($gallery[5], $picker->pickFor($exactMember, ['black', 'yellow'], $gallery, [$leaderImage], $stem)['url']);
        // czarna karta tego modelu bierze plik „_Blk_”, nie „_BlkYel_”
        $black = $this->coba('DAF010001', 'Orthomat Diamond Czarny 0.6m x 0.9m (9.5mm)');
        $this->assertSame($gallery[3], $picker->pickFor($black, ['black'], $gallery, [$leaderImage], 'Orthomat Diamond')['url']);

        // Orthomat Comfort Plus (wersja #1368): „OCP010002_OrthoComfortPlus_…_Blk_Coner.jpg” nie ma słowa „orthomat” —
        // mówi o modelu kodem rodziny (OCP01…); karta Czarny/Żółte bierze „…_Blkyel_Corner_1.jpg”
        $ocpBlack = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2019/11/OCP010002_OrthoComfortPlus_09x15_Blk_Coner.jpg';
        $ocpBlackYellow = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2019/11/Orthomat%C2%AE-Comfort-Plus_06x09_Blkyel_Corner_1.jpg';
        $ocpIndustrial = $c.'OCP0_Orthomat-Comfort-Plus-1_industrial.jpg';
        $ocpLeader = $this->coba('OCP010701', 'Orthomat Comfort Plus Czarny/Żółte krawędzie 0.6m x 0.9m (15mm)');
        $ocpMember = $this->coba('OCP010702', 'Orthomat Comfort Plus Czarny/Żółte krawędzie 0.9m x 1.5m (15mm)');
        $ocpLeaderImage = $this->image($ocpLeader, $ocpBlack);
        $ocpStem = 'Orthomat Comfort Plus krawędzie';
        $this->assertSame($ocpBlackYellow, $picker->pickFor($ocpMember, ['black', 'yellow'], [$ocpBlack, $ocpIndustrial, $ocpBlackYellow], [$ocpLeaderImage], $ocpStem)['url']);
        // bez pliku czarno-żółtego: czarny plik z kodem rodziny to „inny kolor” — bez kopii czarnego zdjęcia lidera
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            $picker->pickFor($ocpMember, ['black', 'yellow'], [$ocpBlack, $ocpIndustrial], [$ocpLeaderImage], $ocpStem)
        );
        // i bez galerii: zdjęcie lidera „…_Blk_…” to inny kolor niż Czarny/Żółte (dawniej kopia)
        $this->assertSame(ModelImagePicker::REASON_LEADER_OTHER_COLOUR, $picker->pickFor($ocpMember, ['black', 'yellow'], [], [$ocpLeaderImage], $ocpStem)['reason']);
    }

    public function test_abbreviated_or_glued_other_colour_is_not_copied_to_one_colour_card(): void
    {
        // Orthomat Premium Czarny (wersja #1369): jedyny plik z kolorem to „…_Yel_Bk-…” (czarna z żółtą krawędzią)
        $c = 'https://www.coba.com/pl/wp-content/uploads/sites/6/';
        $yelBk = $c.'2016/06/FF01_0701-Orthomat-Premium_Yel_Bk-100_0.6-x-0.9m-Corner.jpg';
        $gallery = [$yelBk, $c.'2022/10/FF01_16-Orthomat-Premium_industrial.jpg', $c.'2022/10/af-orthomat-premium-workplace-matting-2.jpg'];
        $leader = $this->coba('FF010002', 'Orthomat Premium Czarny 0.9m x 1.5m (12.5mm)');
        $member = $this->coba('FF010001', 'Orthomat Premium Czarny 0.6m x 0.9m (12.5mm)');
        $leaderImage = $this->image($leader, $yelBk);
        $picker = new ModelImagePicker;
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            $picker->pickFor($member, ['black'], $gallery, [$leaderImage], 'Orthomat Premium')
        );
        $this->assertSame(ModelImagePicker::REASON_LEADER_OTHER_COLOUR, $picker->pickFor($member, ['black'], [], [$leaderImage], 'Orthomat Premium')['reason']);

        // COBAGRiP Osłona krawędzi Żółta (wersja #1506): „…-BlackYellow_isolated.jpg” to czarno-żółty kątownik
        $blackYellow = $c.'2022/10/GRPN_COBAGRiP-Stair-Nosing-BlackYellow_isolated.jpg';
        $nosingLeader = $this->coba('GRP070001N', 'COBAGRiP Osłona krawędzi Żółta 3m x 55mm x 55mm');
        $nosing = $this->coba('GRP070005N', 'COBAGRiP Osłona krawędzi Żółta 0.75m x 55mm x 55mm');
        $nosingImage = $this->image($nosingLeader, $blackYellow);
        $this->assertSame(
            ['url' => null, 'copy_of' => null, 'reason' => ModelImagePicker::REASON_PAGE_OTHER_COLOUR],
            $picker->pickFor($nosing, ['yellow'], [$blackYellow], [$nosingImage], 'COBAGRiP Osłona krawędzi')
        );
    }

    public function test_charcoal_file_goes_to_anthracite_card(): void
    {
        // Toughrib (wersja #1543) i Needlepunch (wersja #1534): karta „Antracyt”, plik „Charcoal” — dawniej „inny kolor”
        $c = 'https://www.coba.com/pl/wp-content/uploads/sites/6/';
        $toughrib = $this->coba('TR010002', 'Toughrib Antracyt 0.9m x 1.5m');
        $charcoal = $c.'2022/10/TR010004_Toughrib_08x12_Charcoal.jpg';
        $pick = (new ModelImagePicker)->pickFor($toughrib, ['anthracite'], [
            $c.'2022/10/TR01_02-Toughrib-Brown_general.jpg', $c.'2022/10/TR01_02-Toughrib-Red_general.jpg', $charcoal,
        ], [], 'Toughrib');
        $this->assertSame([$charcoal, ModelImagePicker::REASON_PAGE_IN_COLOUR], $this->urlAndReason($pick));

        $needlepunch = $this->coba('NP010003', 'Needlepunch Antracyt 1m x 21m / krawędź dodatkowo płatna P249-C63-C09');
        $needleCharcoal = $c.'2022/10/af-needlepunch-entrance-matting-charcoal-3.jpg';
        $this->assertSame($needleCharcoal, (new ModelImagePicker)->pickFor($needlepunch, ['Antracyt'], [
            $c.'2022/10/af-needlepunch-entrance-matting-grey-1.jpg',
            $c.'2025/05/Needlepuch-Edge-Corner-P249-scaled.jpg',
            $needleCharcoal,
            $c.'2020/02/af-needlepunch-entrance-matting-style-charcoal-4.jpg',
        ], [], 'Needlepunch krawędź dodatkowo płatna P249-C63-C09')['url']);
    }

    public function test_card_code_family_in_file_name_counts_like_model_stem_word(): void
    {
        // plik nazwany samym kodem (bez słowa „deckstep”): kod rodziny tej karty mówi o modelu, kod innej rodziny nie
        $c = 'https://www.coba.com/wp-content/uploads/2020/02/';
        $redWide = $this->coba('DS031210C', 'DeckStep Matting Czerwony 1.2m x mb (11.5mm)');
        $red = $this->coba('DS0306', 'DeckStep Matting Czerwony ~0.59m/0.6m x 10m (11.5mm)');
        $green = $this->coba('DS0406', 'DeckStep Matting Zielony ~0.59m/0.6m x 10m (11.5mm)');
        $redFile = $c.'DS030610_059x10_Red.jpg';
        $picker = new ModelImagePicker;
        foreach ([$redWide, $red] as $card) {
            $this->assertSame([$redFile, ModelImagePicker::REASON_PAGE_IN_COLOUR], $this->urlAndReason($picker->pickFor($card, ['red'], [$redFile], [], 'DeckStep Matting')), (string) $card->sku);
        }
        // DS04… to inna rodzina kodu: bez słowa rdzenia czerwony plik nie mówi o zielonej karcie
        $this->assertSame([null, ModelImagePicker::REASON_LEADER_NONE], $this->urlAndReason($picker->pickFor($green, ['green'], [$redFile], [], 'DeckStep Matting')));
        // ze słowem rdzenia plik innego członka w innym kolorze to „inny kolor”, nie dowód
        $this->assertSame(ModelImagePicker::REASON_PAGE_OTHER_COLOUR, $picker->pickFor($green, ['green'], [$c.'DS030610_DeckStep_059x10_Red.jpg'], [], 'DeckStep Matting')['reason']);

        // końcówka kodu rozdziela modele: krawędź „męska” (…B1M) nie idzie na „żeńską” (…B1F), nasadka N na kratę G
        $female = $this->coba('SS070002B1F', "Krawędź/narożnik 'żeński' Żółty (100% Nitryl) 75mm x 1m");
        $this->assertSame(
            [null, ModelImagePicker::REASON_LEADER_NONE],
            $this->urlAndReason($picker->pickFor($female, ['yellow'], ['https://www.coba.com/x/SS070002B1M_FatStepEdgeB1_Yel_Male-scaled.jpg'], [], "Krawędź/narożnik 'żeński'"))
        );
        $grating = $this->coba('GRP070009G', 'COBAGRIP Krata GRP Żółty 3660mm x 1220mm x 50mm');
        foreach (['GRP070005N_Nosing_Yellow.jpg', 'GRP0112_Strip_Yellow.jpg'] as $file) {
            $this->assertSame([null, ModelImagePicker::REASON_LEADER_NONE], $this->urlAndReason($picker->pickFor($grating, ['yellow'], ['https://www.coba.com/x/'.$file], [], 'Krata GRP')), $file);
        }
    }

    public function test_sibling_card_image_in_member_colour_is_a_substitute(): void
    {
        // Superdry (model coba|WH|superdry w partii #501): Szary 11216/11217 bez zdjęcia, 11218 ma szare zdjęcie
        $black = $this->coba('WH010001', 'Superdry Czarny 0.6m x 0.9m');
        $greySibling = $this->coba('WH0600', 'Superdry Szary 1.15m x 1.75m');
        $member = $this->coba('WH060001', 'Superdry Szary 0.6m x 0.9m');
        $blackImage = $this->image($black, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2026/08/Superdry-Corner-Black-WH02.png');
        $greyImage = $this->image($greySibling, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/WH01_06-Superdry-Grey_general.jpg');
        $picker = new ModelImagePicker;

        $this->assertSame((int) $greyImage->id, (int) $picker->pickFromModelSiblings($member, ['grey'], [$blackImage, $greyImage], 'Superdry')?->id);
        $this->assertSame((int) $greyImage->id, (int) $picker->pickFromModelSiblings($member, ['Szary'], [$blackImage, $greyImage], null)?->id);
        $this->assertNull($picker->pickFromModelSiblings($member, ['grey'], [$blackImage], 'Superdry'), 'tylko inny kolor');
        $this->assertNull($picker->pickFromModelSiblings($member, [], [$greyImage], 'Superdry'), 'karta bez koloru');

        // Entra-Plush: Szary 11162/11164 ← „PP060002_EntraPlush_09x15_Grey.jpg” z 11163; niebieskie karty nie dają
        $blue = $this->coba('PP020001', 'Entra-Plush Niebieski 0.6m x 0.9m');
        $greyEntra = $this->coba('PP060002', 'Entra-Plush Szary 0.9m x 1.5m');
        $entraMember = $this->coba('PP060001', 'Entra-Plush Szary 0.6m x 0.9m');
        $blueImage = $this->image($blue, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2016/06/PP02_06-Entra-Plush-Blue_general.jpg');
        $greyEntraImage = $this->image($greyEntra, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/PP060002_EntraPlush_09x15_Grey.jpg');
        $this->assertSame((int) $greyEntraImage->id, (int) $picker->pickFromModelSiblings($entraMember, ['grey'], [$blueImage, $greyEntraImage], 'Entra-Plush')?->id);

        // bez koloru w nazwie pliku — nie (tak powstały czarne kopie lidera); karta-właściciel w innym kolorze — nie;
        // zdjęcie samej karty członka, miniatura i inny wyrób — nie
        $neutral = $this->image($greySibling, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2026/08/Superdry-Isolated-WH050001.jpg');
        $greyFileOnBlackCard = $this->image($black, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/WH01_06-Superdry-Grey_detail.jpg');
        $own = $this->image($member, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/WH060001_SuperDry_06x09_Grey.jpg');
        $thumb = $this->image($greySibling, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/WH01_06-Superdry-Grey_general-300x300.jpg');
        $foreign = $this->image($greySibling, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/cobagrip-grey.jpg');
        $this->assertNull($picker->pickFromModelSiblings($member, ['grey'], [$neutral, $greyFileOnBlackCard, $own, $thumb, $foreign, 'x'], 'Superdry'));

        // plik z pełnym kodem karty członka wygrywa z wcześniejszym pasującym
        $exact = $this->image($greySibling, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/WH060001_SuperDry_06x09_Grey.jpg');
        $this->assertSame((int) $exact->id, (int) $picker->pickFromModelSiblings($member, ['grey'], [$greyImage, $exact], 'Superdry')?->id);
    }

    public function test_file_with_code_of_another_product_line_never_names_the_model(): void
    {
        // Symulacja na partii #501: karta HR Matting Niebieski ma ze sklepu zdjęcie DeckStepa z kodem DS020610C; ogólne
        // słowo rdzenia „matting” („HR” ma 2 znaki) przepuszczało je jako zamiennik na inne niebieskie HR Matting
        $shopDeckStep = 'https://sklep.example/pol_pl_Mata-DeckStep-Matting-Niebieski-0-6m-mb-DS020610C-COBA_%5B68876%5D_568.jpg';
        $hrBlue = $this->coba('HR020001', 'HR Matting Niebieski 0.6m x 10m (2.4mm)');
        $member = $this->coba('HR020004C', 'HR Matting Niebieski 0.6m x mb. (2.4mm) - maks. 10m');
        $wrong = $this->image($hrBlue, $shopDeckStep);
        $picker = new ModelImagePicker;

        $this->assertNull($picker->pickFromModelSiblings($member, ['blue'], [$wrong], 'HR Matting'));
        $this->assertSame([null, ModelImagePicker::REASON_LEADER_NONE], $this->urlAndReason($picker->pickFor($member, ['blue'], [$shopDeckStep], [], 'HR Matting')));
        // ten sam plik bez obcego kodu liczy się przez słowo rdzenia jak dotąd; kod tych samych liter spoza rodziny nie
        // jest obcy („HR060004C” przy HR02…: inny kolor, rozstrzyga słowo rdzenia), litery dopisku technicznego też nie
        $plain = 'https://sklep.example/Mata-HR-Matting-Niebieski.jpg';
        $this->assertSame($plain, $picker->pickFor($member, ['blue'], [$plain], [], 'HR Matting')['url']);
        $this->assertSame(ModelImagePicker::REASON_PAGE_OTHER_COLOUR, $picker->pickFor($member, ['blue'], ['https://www.coba.com/x/HR060004C_HR-Matting_Grey.jpg'], [], 'HR Matting')['reason']);
        $this->assertSame('https://www.coba.com/x/IMG20240901_HR-Matting_Blue.jpg', $picker->pickFor($member, ['blue'], ['https://www.coba.com/x/IMG20240901_HR-Matting_Blue.jpg'], [], 'HR Matting')['url']);
    }

    /** @return array{0: ?string, 1: string} */
    private function urlAndReason(array $pick): array
    {
        return [$pick['url'], $pick['reason']];
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
