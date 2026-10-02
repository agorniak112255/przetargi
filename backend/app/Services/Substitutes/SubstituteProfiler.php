<?php

declare(strict_types=1);

namespace App\Services\Substitutes;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Support\CanonicalBrand;
use App\Support\PpeAssortment;
use App\Support\ProductSizeVariant;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\En388Code;
use App\Support\RequirementCheck\En407Code;
use App\Support\RequirementCheck\LevelChecker;
use App\Support\RequirementCheck\PpeCategory;
use App\Support\RequirementCheck\Status;
use Illuminate\Support\Str;

/**
 * Profil ochronny karty dla zamienników (SubstituteProfile), usterki karty, przez które nie da się jej porównać,
 * i ocena, czy karta nadaje się na kartę główną.
 *
 * Poziomy czytają te same parsery co LevelChecker, a każdy odczytany poziom przechodzi kontrolę zwrotną: krótki zapis
 * wymagania z tą wartością („EN 388:2016 4131X”, „S3”, „FFP2”) sprawdzony LevelCheckerem na tej samej karcie musi dać
 * „spełnia”. Pola sobie przeczące, kilka wariantów w jednym polu albo inne wydanie normy kontrolę oblewają.
 *
 * Cechy są dwojakie (uzgodnione po audycie 125 par 02.10.2026):
 * - „rodzaj” (flags['k']) zmienia rodzaj wyrobu — musi być taki sam po obu stronach; do odrzucenia pary wystarczy
 *   słaby ślad w dowolnym polu karty, także w opisie;
 * - „dodatkowa” (flags['c']) — to, co ma karta główna, zamiennik musi mieć potwierdzone mocnym źródłem: nazwa, normy,
 *   producent, cennik, tabelka dostawcy albo zdanie opisu bez słów o wariantach i seriach („dostępne również…”).
 *   Listy pochodne z opisu (specyfikacja, cechy, materiały z wzbogacania) są tylko słabym śladem.
 */
final class SubstituteProfiler
{
    /**
     * Rodziny, które polecenie bierze bez --family: tylko te, które przeszły audyt na świeżej próbie ≥ 40 par bez
     * błędu (uzgodnione 02.10.2026). Na razie żadna — obuwie w rundach 5–6 miało 1 i 2 błędy na 45 par (po każdej
     * rundzie poprawione), a lokalnej kopii zabrakło świeżych par na kolejną. Każda rodzina jawnie przez --family,
     * pierwsza partia mała (--max-pairs) i oceniona przez człowieka na ekranie zamienników.
     *
     * @var list<string>
     */
    public const DEFAULT_FAMILIES = [];

    /** Rodziny objęte automatem (v1) — tylko tam LevelChecker czyta parametry, od których zależy ochrona. */
    public const FAMILIES = [
        PpeAssortment::FAMILY_GLOVES,
        PpeAssortment::FAMILY_FOOTWEAR,
        PpeAssortment::FAMILY_RESPIRATORY,
        PpeAssortment::FAMILY_HEARING,
    ];

    /**
     * Normy, których treść automat rozumie albo które nie mają poziomów (ogólne, metody badań). Karta główna z normą
     * spoza listy (EN 374, EN 511, EN 12477, EN 60903, EN ISO 17249, ISO 18889, ANSI, ASTM…) wypada: jej poziomów nikt
     * by nie sprawdził, a brak wiersza w porównaniu wyglądałby jak „spełnia”.
     *
     * @var array<string, list<string>>
     */
    public const NORM_WHITELIST = [
        PpeAssortment::FAMILY_GLOVES => ['388', '407', '21420', '13997', '1149', '16350'],
        PpeAssortment::FAMILY_FOOTWEAR => ['20345', '20347', '20346', '20344', '13287', '61340', '12568'],
        PpeAssortment::FAMILY_RESPIRATORY => ['149'],
        PpeAssortment::FAMILY_HEARING => ['352', '458'],
    ];

    /** Stare numery zastąpione nowymi — to ta sama norma w nowym wydaniu. */
    private const NORM_SUCCESSOR = ['420' => '21420', '345' => '20345', '346' => '20346', '347' => '20347', '344' => '20344'];

    /** Normy, w których część (-1, -2…) mówi o rodzaju wyrobu: EN 352-1 nauszniki, -2 wkładki, -3 nahełmowe. */
    private const NORM_PART_MATTERS = ['352', '1149', '61340'];

    /** Normy ISO wydane też jako EN ISO — „ISO 13997” to ta sama norma co „EN ISO 13997”. */
    private const ISO_AS_EN = ['21420', '13997', '20345', '20346', '20347', '20344'];

    private const NORM_RE = '/(?<![\p{L}\d])EN\s?(?:ISO\s?)?(\d{3,5})(?:\s?[-–]\s?(\d{1,2}))?(?!\d)/iu';

    /** Oznaczenia norm spoza EN: ISO bez EN, ANSI/ISEA, ASTM, AS/NZS, NIOSH, KN95 — dla białej listy. */
    private const OTHER_NORM_RE = '/(?<![\p{L}\d])(?:(?<!EN\s)(?<!EN)ISO\s?(\d{4,5})|ANSI(?:\/ISEA)?\s?(\d{2,3})|ASTM\s?([A-Z]?\d{2,5})|AS\/NZS\s?(\d{4})|(NIOSH|KN95|N95))(?![\p{L}\d])/u';

    private const FOOTWEAR_RE = '/(?<![\p{L}\d])('.BhpAttributeNormalizer::FOOTWEAR_CLASS.')(?![\p{L}\d])/u';

    /** Ciąg oznaczeń tuż za klasą obuwia („S3 HI HRO SRC”, „S3S FO SR ESD”) — oznaczenia czytamy tylko stąd. */
    private const MARKING_RUN_RE = '/(?<![\p{L}\d])(?:'.BhpAttributeNormalizer::FOOTWEAR_CLASS.')((?:[\h,;+\/]+(?:SRA|SRB|SRC|SR|SC|HRO|WRU|WR|WPA|CI|HI|AN|ESD|FO|M|A|E|P|LG)(?![\p{L}\d\-]))+)/u';

    private const MARKING_TOKEN_RE = '/(?<![\p{L}\d])(SRA|SRB|SRC|SR|SC|HRO|WRU|WR|WPA|CI|HI|AN|ESD|FO|M|A|E|P|LG)(?![\p{L}\d\-])/u';

    /** Klasy obuwia z odpornością na przebicie (z wkładką antyprzebiciową). */
    public const FOOTWEAR_PLATE_CLASSES = ['S1P', 'S3', 'S5', 'S7', 'O1P', 'O3', 'O5', 'O7'];

    /** Oznaczenia obuwia, które zamiennik musi mieć wprost, gdy ma je karta główna (FO osobno — patrz footwearFo). */
    public const FOOTWEAR_MARKINGS_REQUIRED = ['CI', 'HI', 'HRO', 'AN', 'WR', 'ESD', 'M', 'SC'];

    /** Zdanie opisu o wariantach, seriach i opcjach — nie potwierdza cechy tej karty. */
    private const VARIANT_SENTENCE_RE = '/\b(dostepn\w*|wersj\w*|opcj\w*|opcjonaln\w*|seri\w*|warian\w*|rowniez|takze|gam\w*|rodzin\w*|available|version\w*|option\w*|range|family|models?)\b/u';

    /** Zaprzeczenia wycinane przed szukaniem cech: „bez lateksu”, „nie do spawania”, „latex free”, „brak zaworu”. */
    private const NEGATION_RE = '/\b(?:bez|brak|nie(?:\s+(?:jest|sa|zawiera\w*|nadaj\w*|przeznaczon\w*|do))?)\s+(?:\w+\s+){0,2}?\w+|\b\w+[\s-]+free\b/u';

    /** @var array<string, true>|null klucze marek producentów z katalogu (CanonicalBrand::key) */
    private ?array $knownBrands = null;

    public function __construct(
        private readonly LevelChecker $levels = new LevelChecker,
        private readonly PpeAssortment $assortment = new PpeAssortment,
        private readonly BhpAttributeNormalizer $attributes = new BhpAttributeNormalizer,
        private readonly ProductSizeVariant $sizes = new ProductSizeVariant,
    ) {}

    /** Profil karty z rodziny objętej automatem; null dla innych rodzin. */
    public function profile(Product $product): ?SubstituteProfile
    {
        $family = (string) ($product->ppe_family ?? '');
        if (! in_array($family, self::FAMILIES, true)) {
            return null;
        }
        $sources = CardSources::fromProduct($product);
        $identity = $this->identityText($product);
        $weak = implode("\n", array_map(static fn (CardSource $s): string => $s->text, $sources));
        $strong = $this->strongText($sources);
        $levels = $this->readLevels($sources);
        $text = ['weak' => $this->lower($weak), 'strong' => $this->lower($strong)];
        $flags = $this->flags($family, $this->lower($identity), $text, $sources);

        if ($family === PpeAssortment::FAMILY_GLOVES) {
            $type = $this->gloveType($levels, $flags);
            $fromName = false;
        } else {
            $fromNameType = $this->assortment->articleType($identity, $family);
            $type = $fromNameType ?? $this->assortment->articleType($weak, $family);
            $fromName = $fromNameType !== null;
        }
        if ($family === PpeAssortment::FAMILY_FOOTWEAR) {
            // klasa z literą typu wkładki (EN ISO 20345:2022: S1PL, S3S…) to wkładka niemetalowa — mocny dowód z klasy
            $class = preg_replace('/\s+/u', '', (string) ($levels['footwear_class']['value'] ?? '')) ?? '';
            if (preg_match('/^(?:S1P|S[357]|O1P|O[357])[LS]$/', $class) === 1) {
                foreach (['main_plate', 'strong_plate'] as $key) {
                    $flags['k'][$key] = ($flags['k'][$key] ?? null) === 'metal' ? null : 'nonmetal';
                }
            }
        }
        if ($family === PpeAssortment::FAMILY_HEARING) {
            // pianka poduszek nauszników to nie „wkładki jednorazowe”; składanie dotyczy tylko nauszników
            if ($type !== 'earplug') {
                $flags['k']['plug_kind'] = null;
            }
            if ($type !== 'earmuff') {
                $flags['k']['folding'] = false;
            }
        }

        return new SubstituteProfile(
            productId: (int) $product->id,
            family: $family,
            articleType: $type,
            articleTypeFromName: $fromName,
            levels: $levels,
            norms: $this->readNorms($sources),
            flags: $flags,
            markings: $family === PpeAssortment::FAMILY_FOOTWEAR ? $this->footwearMarkings($sources, false) : [],
            brandKey: $this->effectiveBrand($product),
            fingerprint: sha1(implode("\n", array_map(static fn (CardSource $s): string => $s->source.':'.$s->text, $sources))),
            strongMarkings: $family === PpeAssortment::FAMILY_FOOTWEAR ? $this->footwearMarkings($sources, true) : [],
        );
    }

    /**
     * Usterka karty, przez którą nie porównujemy jej w żadną stronę (ani jako główna, ani jako zamiennik); null — brak.
     */
    public function cardIssue(Product $product, SubstituteProfile $profile): ?string
    {
        if (! $product->hasUsableDescription()) {
            return 'karta bez opisu';
        }
        if ($product->catalog_price_net === null || (float) $product->catalog_price_net <= 0) {
            return 'karta bez ceny';
        }
        if ($profile->articleType === null) {
            return 'nieznany rodzaj wyrobu';
        }
        if ($profile->flags['k']['electrical'] === true) {
            return 'wyroby elektroizolacyjne poza automatem';
        }
        if ($this->nameFamilyConflict($product, $profile)) {
            return 'nazwa wskazuje inną grupę wyrobów';
        }
        if (! $this->nameConfirmsFamily($product, $profile)) {
            return 'nazwa nie potwierdza rodzaju wyrobu';
        }
        if ($this->singleSizeCard($product)) {
            return 'karta jednego rozmiaru';
        }
        if ($this->typeContradictsDescription($product, $profile)) {
            return 'nazwa i opis podają inny rodzaj wyrobu';
        }
        if ($this->descriptionNamesOtherCode($product)) {
            return 'opis podaje kod innego wyrobu (opis sklejony albo cudzy)';
        }
        $sources = CardSources::fromProduct($product);
        if ($this->levels->cardConflicts($sources) !== []) {
            return 'pola karty podają różne poziomy';
        }
        $levelKeys = array_keys($profile->levels);

        return match ($profile->family) {
            PpeAssortment::FAMILY_GLOVES => $this->gloveIssue($profile, $levelKeys, $sources),
            PpeAssortment::FAMILY_FOOTWEAR => $this->footwearIssue($profile, $levelKeys, $sources),
            PpeAssortment::FAMILY_RESPIRATORY => match (true) {
                $profile->articleType !== 'ffp' => 'tylko półmaski FFP',
                ! in_array('ffp', $levelKeys, true) => 'brak klasy FFP',
                ! isset($profile->norms['en149']) => 'brak normy EN 149',
                $profile->flags['k']['valve'] === null => 'karta nie mówi jednoznacznie, czy jest zawór',
                $profile->flags['k']['shape'] === null => 'karta nie podaje jednoznacznie kształtu półmaski',
                // węgiel aktywny chroni przed różnymi gazami (kwaśne, organiczne, ozon, zapachy) — tego nie porównamy
                $profile->flags['k']['carbon'] === true => 'półmaski z węglem aktywnym poza automatem',
                default => null,
            },
            PpeAssortment::FAMILY_HEARING => match (true) {
                ! in_array('snr', $levelKeys, true) => 'brak SNR',
                $this->normParts($profile, '352') === null => 'brak normy EN 352',
                // nahełmowe pasują do konkretnych systemów kasków (uvex pheos, JSP EVO, 3M G3000) — tego nie porównamy
                $profile->flags['k']['helmet'] === true => 'nauszniki nahełmowe poza automatem',
                $profile->flags['k']['electronic'] === true => 'ochronniki elektroniczne poza automatem',
                $profile->articleType === 'earmuff' && $profile->flags['k']['mount'] === null => 'karta nie podaje sposobu noszenia nauszników',
                $profile->articleType === 'earplug' && $profile->flags['k']['plug_kind'] === null => 'karta nie mówi wprost, czy wkładki są jednorazowe czy wielokrotne',
                default => null,
            },
            default => 'rodzina poza automatem',
        };
    }

    /**
     * Dlaczego karta nie może być kartą główną (null — może): usterka karty albo norma, której poziomów automat
     * nie czyta. Normy spoza listy zamiennikowi nie szkodzą — ma więcej, nie mniej.
     */
    public function mainIneligibility(Product $product, SubstituteProfile $profile): ?string
    {
        $issue = $this->cardIssue($product, $profile);
        if ($issue !== null) {
            return $issue;
        }
        $whitelist = self::NORM_WHITELIST[$profile->family];
        foreach ($profile->norms as $key => $norm) {
            if (! str_starts_with($key, 'en') || ! in_array($this->normBase($key), $whitelist, true)) {
                return "norma spoza zakresu automatu: {$norm['label']}";
            }
        }
        // „EN 388” / „EN 407” w normach bez jednoznacznego kodu: parametru nie da się porównać
        foreach (['en388' => 'EN 388', 'en407' => 'EN 407'] as $key => $label) {
            if (isset($profile->norms[$key]) && ! isset($profile->levels[$key])) {
                return "{$label} bez jednoznacznego kodu";
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $levelKeys
     * @param  list<CardSource>  $sources
     */
    private function gloveIssue(SubstituteProfile $profile, array $levelKeys, array $sources): ?string
    {
        $k = $profile->flags['k'];
        if ($k['sleeve'] === true) {
            return 'zarękawek, nie rękawica';
        }
        if ($k['protector'] === true) {
            return 'ochraniacz na rękawice, nie rękawica';
        }
        if ($k['coating'] === null) {
            return 'karta nie podaje powłoki';
        }
        if (str_contains((string) $k['coating'], 'skora')) {
            return 'rękawice skórzane poza automatem';
        }
        if ($k['coverage'] === null) {
            return 'karta nie podaje jednoznacznie zakresu powłoki';
        }
        if (! in_array('en388', $levelKeys, true) && ! in_array('en407', $levelKeys, true)) {
            return 'brak kodu EN 388 / EN 407';
        }
        // „poziom B” w tekście obok kodu 4443C — karta sama sobie przeczy
        $code = $profile->levels['en388']['value'] ?? null;
        $letter = $code !== null && strlen($code) >= 5 ? strtoupper($code[4]) : null;
        if ($letter !== null && $letter !== 'X') {
            $text = implode("\n", array_map(static fn (CardSource $s): string => $s->text, $sources));
            foreach ($this->assortment->cutLevelsIn($text) as $other) {
                if (strtoupper($other) !== $letter) {
                    return 'opis podaje inny poziom cięcia niż kod EN 388';
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $levelKeys
     * @param  list<CardSource>  $sources
     */
    private function footwearIssue(SubstituteProfile $profile, array $levelKeys, array $sources): ?string
    {
        if (! in_array('footwear_class', $levelKeys, true)) {
            return 'brak klasy obuwia';
        }
        $class = $this->attributes->footwearClassBase($profile->levels['footwear_class']['value']);
        // wysokość cholewki przeczy rodzajowi z nazwy („obuwie wysokie” przy półbucie, „niska cholewka” przy trzewiku)
        $text = $this->lower(implode("\n", array_map(static fn (CardSource $s): string => $s->text, $sources)));
        if (($profile->articleType === PpeAssortment::TYPE_POLBUT
                && preg_match('/\b(obuwi\w*\s+wysok\w*|wysok\w*\s+(?:obuwi\w*|cholewk\w*)|semi[\s-]*shank|high[\s-]*(?:cut|top|shoe|boot))/u', $text) === 1)
            || ($profile->articleType === PpeAssortment::TYPE_TRZEWIK
                && preg_match('/\b(obuwi\w*\s+nisk\w*|nisk\w*\s+(?:obuwi\w*|cholewk\w*)|low[\s-]*(?:cut|shoe)s?\b)/u', $text) === 1)) {
            return 'wysokość cholewki przeczy rodzajowi wyrobu';
        }
        $norm = str_starts_with($class, 'O') ? 'en20347' : 'en20345';
        if (! isset($profile->norms[$norm])) {
            return 'brak normy '.($norm === 'en20347' ? 'EN ISO 20347' : 'EN ISO 20345');
        }
        // materiał elementów ochronnych (metal / bez metalu) znany wprost: podnosek przy klasach S, wkładka przy klasach
        // z odpornością na przebicie — inaczej nie da się uczciwie porównać
        $k = $profile->flags['k'];
        if (str_starts_with($class, 'S') && ($k['main_toe'] ?? null) === null) {
            return 'karta nie podaje materiału podnoska';
        }
        if (in_array($class, self::FOOTWEAR_PLATE_CLASSES, true) && ($k['main_plate'] ?? null) === null) {
            return 'karta nie podaje materiału wkładki antyprzebiciowej';
        }

        return null;
    }

    /**
     * Rękawice w automacie to tylko powlekane: „antyprzecięciowe” wyłącznie z literą ISO 13997 co najmniej B w kodzie
     * EN 388 (słowa w opisie to za mało), reszta z powłoką — „powlekane” (materiał powłoki porównuje się osobno).
     *
     * @param  array<string, array<string, mixed>>  $levels
     * @param  array<string, array<string, mixed>>  $flags
     */
    private function gloveType(array $levels, array $flags): ?string
    {
        $code = (string) ($levels['en388']['value'] ?? '');
        $letter = strlen($code) >= 5 ? strtoupper($code[4]) : 'X';
        if ($letter >= 'B' && $letter <= 'F') {
            return 'cut';
        }

        return $flags['k']['coating'] !== null ? 'coated' : null;
    }

    /** Angielskie nazwy odzieży z kart dystrybutorów (Canis) — PpeAssortment::family ich nie zna. */
    private const ENGLISH_GARMENT = '/\b(knickers|trousers|pants|jacket|shirt|t-shirt|coverall|overall|vest|hoodie|socks|apron|sweatshirt)\b/iu';

    /**
     * Nazwa mówi o innej grupie niż zapisana rodzina karty („Knickers, 100% cotton” jako obuwie z opisem butów S3) —
     * opis albo rodzina są wtedy cudze i karty nie porównujemy.
     */
    public function nameFamilyConflict(Product $product, SubstituteProfile $profile): bool
    {
        $name = (string) $product->name;
        $fromName = $this->assortment->family($name);
        if ($fromName !== null && $fromName !== $profile->family) {
            return true;
        }

        return $fromName === null && preg_match(self::ENGLISH_GARMENT, $name) === 1;
    }

    /**
     * Karta potwierdza grupę wyrobów poza opisem albo na samym jego początku: rodzina albo rodzaj z nazwy, kodu
     * i kategorii sklepu dostawcy, poziom z nazwy („S1 PL”, „FFP2”, „27 dB SNR”), a dla nazw będących samym modelem
     * („RINGERS 065”, „MaxiFlex Ultimate”) — pierwsze zdania opisu. Bez żadnego śladu karta jest czymś innym
     * zapisanym w tej rodzinie (klej, gąbka ścierna, znak „uwaga hałas”).
     */
    public function nameConfirmsFamily(Product $product, SubstituteProfile $profile): bool
    {
        $identity = $this->identityText($product);
        if ($this->assortment->articleType($identity, $profile->family) !== null
            || $this->assortment->family($identity) === $profile->family) {
            return true;
        }
        foreach ($profile->levels as $level) {
            if ($level['source'] === CardSource::NAME) {
                return true;
            }
        }
        $head = $this->descriptionHead($product);

        return $head !== '' && $this->assortment->family($head) === $profile->family;
    }

    /**
     * Rodzaj z nazwy przeczy rodzajowi z początku opisu („Low perforated leather footwear” z opisem sandała na rzep) —
     * jedno z nich jest od innego wyrobu.
     */
    private function typeContradictsDescription(Product $product, SubstituteProfile $profile): bool
    {
        if ($profile->family === PpeAssortment::FAMILY_GLOVES) {
            return false;
        }
        $fromName = $this->assortment->articleType($this->identityText($product), $profile->family);
        $fromHead = $this->assortment->articleType($this->descriptionHead($product), $profile->family);

        return $fromName !== null && $fromHead !== null && $fromName !== $fromHead;
    }

    /**
     * Karta jednego rozmiaru (rozmiar w nazwie albo „Rozmiar: 10” w tabelce dostawcy — np. karty VEND z automatów):
     * jako zamiennik karty modelu z pełną rozmiarówką wprowadza w błąd, jako główna — też.
     */
    private function singleSizeCard(Product $product): bool
    {
        if ($this->sizes->singleSizeFromName((string) $product->name) !== null) {
            return true;
        }
        // tylko wiersz „Rozmiar: X” (tabelka dostawcy, specyfikacja) — ogólny odczyt rozmiarów z opisu bierze
        // „SNR 37 dB” za rozmiar 37; do wykluczenia karty wystarczy słaby ślad
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $lines = implode("\n", [
            (string) ($product->shop_fields_summary ?? ''),
            ...array_filter(is_array($payload['specs'] ?? null) ? $payload['specs'] : [], 'is_string'),
        ]);

        return preg_match('/^\s*rozmiar\s*:\s*[^\s,;\/–-]+\s*(?:\([^)]*\))?\s*$/imu', $lines) === 1;
    }

    /**
     * Opis z kodem innego wyrobu w tym samym zapisie co SKU karty („4510-004-000-00” w opisie karty 4510-074-000-00) —
     * opis sklejony z kart innych wariantów albo cudzy; parametrów z takiej karty nie bierzemy.
     */
    private function descriptionNamesOtherCode(Product $product): bool
    {
        $sku = trim((string) $product->sku);
        if (mb_strlen($sku) < 8 || preg_match('/^[\p{L}\d]+(?:[-.\/][\p{L}\d]+)+$/u', $sku) !== 1) {
            return false;
        }
        // ten sam zapis: cyfra → cyfra, litera → litera, separator → dowolny z . / -
        $shape = '';
        foreach (mb_str_split($sku) as $char) {
            $shape .= match (true) {
                ctype_digit($char) => '\d',
                preg_match('/\p{L}/u', $char) === 1 => '\p{L}',
                default => '[.\/-]',
            };
        }
        $pattern = '/(?<![\p{L}\d])'.$shape.'(?![\p{L}\d])/u';
        if (preg_match_all($pattern, (string) $product->description, $m) < 1) {
            return false;
        }
        foreach ($m[0] as $code) {
            if (mb_strtoupper($code) !== mb_strtoupper($sku)) {
                return true;
            }
        }

        return false;
    }

    private function descriptionHead(Product $product): string
    {
        return mb_substr(trim(strip_tags((string) $product->description)), 0, 300);
    }

    /**
     * Marka wyrobu: marka producenta z katalogu wymieniona w nazwie karty (karta dystrybutora „Respirator 3M AURA”,
     * „Ear muffs Peltor” → 3M), a bez niej producent karty. Własna marka w nazwie wygrywa z cudzą.
     */
    public function effectiveBrand(Product $product): string
    {
        $own = CanonicalBrand::key($product->manufacturer);
        $known = $this->knownBrands();
        $found = [];
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii((string) $product->name))) ?: [];
        foreach ($tokens as $token) {
            if ($token === '' || (mb_strlen($token) < 3 && $token !== '3m')) {
                continue;
            }
            $key = CanonicalBrand::key($token);
            if ($key !== '' && isset($known[$key])) {
                $found[] = $key;
            }
        }
        if ($found === [] || in_array($own, $found, true)) {
            return $own;
        }

        return $found[0];
    }

    /**
     * @return array<string, true>
     */
    private function knownBrands(): array
    {
        if ($this->knownBrands === null) {
            $this->knownBrands = [];
            foreach (Product::query()->whereNotNull('manufacturer')->distinct()->pluck('manufacturer') as $name) {
                $key = CanonicalBrand::key((string) $name);
                if (mb_strlen($key) >= 3 || $key === '3m') {
                    $this->knownBrands[$key] = true;
                }
            }
        }

        return $this->knownBrands;
    }

    /**
     * Wiersz LevelChecker dla krótkiego zapisu wymagania na kartach źródłowych — null, gdy zapis nie dał wiersza
     * o tym kluczu albo dał też inne wiersze (zapis czytany inaczej, niż zakładamy).
     *
     * @param  list<CardSource>  $sources
     */
    public function checkFragment(string $key, string $fragment, array $sources): ?CheckRow
    {
        $rows = $this->levels->check($fragment, $sources);
        if (count($rows) !== 1 || $rows[0]->key !== $key) {
            return null;
        }

        return $rows[0];
    }

    /** Numer normy bez przedrostka i części: „en352-2” → „352”. */
    public function normBase(string $key): string
    {
        return (string) preg_replace('/^[a-z]+/', '', explode('-', $key)[0]);
    }

    /**
     * Części normy podane na karcie (EN 352: ['352-1'] itp.); [] — norma bez części; null — normy nie ma.
     *
     * @return list<string>|null
     */
    public function normParts(SubstituteProfile $profile, string $base): ?array
    {
        $parts = null;
        foreach (array_keys($profile->norms) as $key) {
            if (str_starts_with($key, 'en') && $this->normBase($key) === $base) {
                $parts ??= [];
                if (str_contains($key, '-')) {
                    $parts[] = substr($key, 2);
                }
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstOkFinding(CheckRow $row): ?array
    {
        foreach ($row->card as $finding) {
            if (($finding['verdict'] ?? null) === Status::Ok->value) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Poziomy, które karta podaje jednoznacznie i które LevelChecker potwierdza na niej samej.
     *
     * @param  list<CardSource>  $sources
     * @return array<string, array{value: string, fragment: string, text: string, source: string, quote: ?string}>
     */
    private function readLevels(array $sources): array
    {
        $candidates = [
            'en388' => $this->codeValue($sources, static fn (string $t): array => En388Code::allIn($t), 'EN 388'),
            'en407' => $this->codeValue($sources, static fn (string $t): array => En407Code::allIn($t), 'EN 407'),
            'ppe_category' => $this->singleValue($sources, static function (string $t): array {
                $out = [];
                foreach (PpeCategory::allIn($t) as $c) {
                    $out[] = $c->isList() ? null : $c->roman();
                }

                return $out;
            }, static fn (string $v): string => "kategoria {$v}"),
            'footwear_class' => $this->footwearValue($sources),
            'ffp' => $this->singleValue($sources, function (string $t): array {
                if ($this->attributes->ffpClass($t) === null) {
                    return [];
                }
                preg_match_all('/(?<![\p{L}\d])FFP\s*-?([123])(?!\d)/iu', $t, $m);

                return array_map(static fn (string $d): string => 'FFP'.$d, array_values(array_unique($m[1])));
            }, static fn (string $v): string => $v),
            'snr' => $this->snrValue($sources),
        ];

        $out = [];
        foreach ($candidates as $key => $candidate) {
            if ($candidate === null) {
                continue;
            }
            [$value, $fragment] = $candidate;
            $row = $this->checkFragment($key, $fragment, $sources);
            if ($row === null || $row->status !== Status::Ok) {
                continue;
            }
            $finding = $this->firstOkFinding($row);
            $out[$key] = [
                'value' => $value,
                'fragment' => $fragment,
                'text' => $finding['text'] ?? $fragment,
                'source' => $finding['source'] ?? CardSource::DESCRIPTION,
                'quote' => $finding['quote'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Kod EN 388 / EN 407: jedna wartość (pełny kod, bez „-”). Zapis producenta ma pierwszeństwo jak w LevelChecker;
     * kody z różnych podanych wydań EN 388 (2003 i 2016) to nie sprzeczność — bierzemy nowsze wydanie.
     *
     * @param  list<CardSource>  $sources
     * @param  callable(string): list<En388Code|En407Code>  $parse
     * @return array{0: string, 1: string}|null
     */
    private function codeValue(array $sources, callable $parse, string $norm): ?array
    {
        $found = [];
        foreach ($sources as $source) {
            foreach ($parse($source->text) as $code) {
                if ($code->worded) {
                    continue;
                }
                $found[] = [
                    'source' => $source->source,
                    'canonical' => $code->canonical(),
                    'edition' => $code instanceof En388Code ? $code->edition : null,
                ];
            }
        }
        if ($found === []) {
            return null;
        }
        $manufacturer = array_values(array_filter($found, static fn (array $f): bool => $f['source'] === CardSource::MANUFACTURER));
        $pool = $manufacturer !== [] ? $manufacturer : $found;
        $editions = array_values(array_unique(array_filter(array_column($pool, 'edition'))));
        rsort($editions);
        $edition = $editions[0] ?? null;
        if ($edition !== null) {
            $pool = array_values(array_filter($pool, static fn (array $f): bool => $f['edition'] === null || $f['edition'] === $edition));
        }
        $values = array_values(array_unique(array_column($pool, 'canonical')));
        if (count($values) !== 1 || str_contains($values[0], '-')) {
            return null;
        }
        if ($norm === 'EN 388') {
            // Kod z wydania 2003 (Coup Test bez litery ISO) nie porównuje się z kodami 2016 — taka karta nie jest główna.
            // Kod z pozycją litery ISO to zapis 2016 także bez podanego roku: zamiennik z samym kodem 2003 ma odpaść.
            if ($edition !== null && $edition < '2016') {
                return null;
            }
            $edition ??= strlen($values[0]) >= 5 ? '2016' : null;
        }

        return [$values[0], $edition !== null ? "{$norm}:{$edition} {$values[0]}" : "{$norm} {$values[0]}"];
    }

    /**
     * Wartość podana jednakowo we wszystkich polach, które ją podają; pole z kilkoma wartościami (null w liście
     * albo więcej niż jedna) to warianty — wtedy brak.
     *
     * @param  list<CardSource>  $sources
     * @param  callable(string): list<?string>  $read
     * @param  callable(string): string  $fragment
     * @return array{0: string, 1: string}|null
     */
    private function singleValue(array $sources, callable $read, callable $fragment): ?array
    {
        $values = [];
        foreach ($sources as $source) {
            $inField = $read($source->text);
            if ($inField === []) {
                continue;
            }
            if (in_array(null, $inField, true) || count(array_unique($inField)) > 1) {
                return null;
            }
            $values[] = (string) $inField[0];
        }
        $values = array_values(array_unique($values));
        if (count($values) !== 1) {
            return null;
        }

        return [$values[0], $fragment($values[0])];
    }

    /**
     * Klasa obuwia: ta sama baza klasy we wszystkich polach. „S3” obok „S3L” to ten sam wyrób zapisany bez typu wkładki —
     * wtedy wymagamy bazy (S3), bo LevelChecker przy wymaganym S3L i karcie S3 da „do sprawdzenia”.
     *
     * @param  list<CardSource>  $sources
     * @return array{0: string, 1: string}|null
     */
    private function footwearValue(array $sources): ?array
    {
        $classes = [];
        foreach ($sources as $source) {
            if (preg_match_all(self::FOOTWEAR_RE, $source->text, $m) < 1) {
                continue;
            }
            $inField = array_values(array_unique(array_map(
                static fn (string $c): string => preg_replace('/\s+/u', '', $c) ?? $c,
                $m[1],
            )));
            if (count($inField) > 1) {
                return null;
            }
            $classes[] = $inField[0];
        }
        $classes = array_values(array_unique($classes));
        if ($classes === []) {
            return null;
        }
        $bases = array_values(array_unique(array_map(fn (string $c): string => $this->attributes->footwearClassBase($c), $classes)));
        if (count($bases) !== 1) {
            return null;
        }
        $value = count($classes) === 1 ? $classes[0] : $bases[0];

        return [$value, "obuwie {$value}"];
    }

    /**
     * SNR: wartość z nazwy, a bez niej jedyna wartość z pól. Kilka różnych wartości w polach bez nazwy — brak
     * (warianty serii); kontrola zwrotna LevelChecker i tak odrzuci sprzeczność.
     *
     * @param  list<CardSource>  $sources
     * @return array{0: string, 1: string}|null
     */
    private function snrValue(array $sources): ?array
    {
        $values = [];
        $fromName = null;
        foreach ($sources as $source) {
            // zakres („SNR 30–31 dB”) to warianty, nie wartość wyrobu
            if (preg_match('/snr\W{0,12}\d{2}(?:[,.]\d)?\s*[–-]\s*\d{2}(?:[,.]\d)?\s*db/iu', $source->text) === 1) {
                return null;
            }
            $snr = $this->attributes->snrRating($source->text);
            if ($snr === null) {
                continue;
            }
            $value = $this->preciseSnr($source->text, $snr);
            if ($source->source === CardSource::NAME) {
                $fromName = $value;
            }
            $values[] = $value;
        }
        $values = array_values(array_unique($values));
        // ta sama wartość zapisana raz z częścią dziesiętną („27” w nazwie, „27,5 dB” w tabelce) — dokładniejsza
        $whole = array_values(array_unique(array_map(static fn (string $v): int => (int) $v, $values)));
        if (count($whole) === 1 && count($values) > 1) {
            $values = array_values(array_filter($values, static fn (string $v): bool => str_contains($v, '.')));
        }
        if (count($values) !== 1) {
            // różne wartości w polach (opis wymienia warianty serii) — rozstrzyga nazwa; bez niej brak
            if ($fromName === null) {
                return null;
            }
            $values = [$fromName];
        }

        return [$values[0], 'SNR '.(int) $values[0].' dB'];
    }

    /**
     * SNR z częścią dziesiętną, gdy pole ją podaje („SNR: 27,5 dB”) — snrRating czyta całe decybele, a 27 przy 27,5 to
     * słabsze tłumienie, nie równe. Tylko w automacie zamienników — wspólny odczyt SNR przetargów zostaje bez zmian.
     */
    private function preciseSnr(string $text, int $snr): string
    {
        if ((preg_match('/(?<![\d,.])'.$snr.'[,.](\d)(?!\d)\s*(?:dB|db)/u', $text, $m) === 1
                || preg_match('/SNR\W{0,6}'.$snr.'[,.](\d)(?!\d)/iu', $text, $m) === 1) && $m[1] !== '0') {
            return $snr.'.'.$m[1];
        }

        return (string) $snr;
    }

    /**
     * Normy karty: klucz → zapis do pokazania i pole. EN: „en388”, „en352-2” (część tylko tam, gdzie mówi o rodzaju
     * wyrobu); spoza EN: „iso18889”, „ansi105”, „astmf696”, „other-kn95” — te tylko do białej listy karty głównej.
     *
     * @param  list<CardSource>  $sources
     * @return array<string, array{label: string, source: ?string}>
     */
    private function readNorms(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            if (preg_match_all(self::NORM_RE, $source->text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $number = self::NORM_SUCCESSOR[$match[1]] ?? $match[1];
                    $part = ($match[2] ?? '') !== '' && in_array($number, self::NORM_PART_MATTERS, true) ? '-'.$match[2] : '';
                    $key = 'en'.$number.$part;
                    if (! isset($out[$key])) {
                        // etykieta jak na karcie: „EN 420” zostaje „EN 420” (klucz wspólny z EN ISO 21420 tylko do porównania)
                        $iso = preg_match('/ISO/i', $match[0]) === 1;
                        $out[$key] = ['label' => 'EN '.($iso ? 'ISO ' : '').$match[1].$part, 'source' => $source->source];
                    }
                }
            }
            if (preg_match_all(self::OTHER_NORM_RE, $source->text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    [$key, $label] = match (true) {
                        ($match[1] ?? '') !== '' => in_array($match[1], self::ISO_AS_EN, true)
                            ? ['en'.$match[1], 'EN ISO '.$match[1]]
                            : ['iso'.$match[1], 'ISO '.$match[1]],
                        ($match[2] ?? '') !== '' => ['ansi'.$match[2], 'ANSI/ISEA '.$match[2]],
                        ($match[3] ?? '') !== '' => ['astm'.mb_strtolower($match[3]), 'ASTM '.$match[3]],
                        ($match[4] ?? '') !== '' => ['asnzs'.$match[4], 'AS/NZS '.$match[4]],
                        default => ['other-'.mb_strtolower((string) ($match[5] ?? '')), (string) ($match[5] ?? '')],
                    };
                    $out[$key] ??= ['label' => $label, 'source' => $source->source];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Cechy karty: 'k' — cechy rodzaju (muszą być równe), 'c' — cechy dodatkowe z siłą dowodu
     * ['weak' => ślad gdziekolwiek, 'strong' => potwierdzenie mocnym źródłem].
     *
     * @param  array{weak: string, strong: string}  $text  małe litery, bez polskich znaków, z interpunkcją
     * @param  list<CardSource>  $sources
     * @return array{k: array<string, bool|string|null>, c: array<string, array{weak: bool, strong: bool}>}
     */
    private function flags(string $family, string $identity, array $text, array $sources): array
    {
        $weak = $this->withoutNegations($text['weak']);
        $strong = $this->withoutNegations($text['strong']);
        $has = static fn (string $re, string $hay): bool => preg_match($re, $hay) === 1;
        $c = static fn (string $re): array => ['weak' => $has($re, $weak), 'strong' => $has($re, $strong)];

        $k = [
            // elektroizolacja (kalosze 5 kV, rękawice dielektryczne) to inny wyrób niż zwykły
            'electrical' => $this->assortment->productShowsElectricalInsulation($text['weak']),
            'junior' => $has('/\b(junior|dzieci\w*|dzieciec\w*|kids?|child\w*)\b/u', $weak),
        ];
        $cf = [];

        switch ($family) {
            case PpeAssortment::FAMILY_GLOVES:
                $k += [
                    'sleeve' => $has('/\b(zarekaw\w*|sleeve\w*|ochraniacz\w*\s+przedrami\w*)\b/u', $identity),
                    'protector' => $has('/\b(ochraniacz\w*|protector\w*|f\s?696|cvr)\b/u', $identity.' '.$weak),
                    'coating' => $this->gloveCoatings($weak),
                    'coverage' => $this->gloveCoverage($weak),
                    'needle' => $has('/\b(igl\w*|igiel|needle\w*|przeszukan\w*|nsr|pointguard|thornarmor)\b/u', $weak),
                    'impact' => $has('/\b(uderzen\w*|impact|tp-?x|tpr|udarow\w*)\b/u', $weak),
                    'welding' => $has('/\b(spawal\w*|welding|12477)\b/u', $weak),
                    'winter' => $has('/\b(ocieplan\w*|ocieplen\w*|zimow\w*|insulated|winter|chlodn\w*|en\s?511)\b/u', $weak),
                    'cuff' => $has('/\b(dlug\w*\s+mankiet|mankiet\w*\s*:?\s*(?:dluzsz|dlug|przedluz)\w*|rekaw(?!ic)\w*|gauntlet|(?:3\d|4\d|5\d)\s?cm|(?:3\d\d|4\d\d|5\d\d)\s?mm)\b/u', $weak),
                ];
                $cf = [
                    'esd' => $c('/\b(esd|antystatyczn\w*|antistatic|elektrostatyczn\w*|1149|16350)\b/u'),
                    'food' => $c('/(zywnosc\w*|spozywcz\w*|\bfood\b|1935\/2004|haccp)/u'),
                ];
                break;
            case PpeAssortment::FAMILY_FOOTWEAR:
                $k += [
                    // obuwie spawalnicze / odlewnicze (EN ISO 20349) — inne przeznaczenie
                    'welding' => $has('/\b(spawal\w*|odlewn\w*|welding|20349)\b/u', $weak),
                    // „chłodnie” to miejsce pracy, nie ocieplenie (ślad z cech dopisanych przez wzbogacanie)
                    'winter' => $has('/\b(ocieplan\w*|ocieplen\w*|zimow\w*|futr\w*|kozuszk\w*|winter|thinsulate)\b/u', $weak),
                    // ochrona śródstopia (oznaczenie M) — nie „wzmocnienie śródstopia” bieżnika ani amortyzacja
                    'metatarsal' => $has('/\b(ochron\w*\s+(?:\w+\s+)?srodstop\w*|srodstop\w*\s+ochron\w*|metatarsal\s+(?:guard|protect)\w*)\b/u', $weak),
                    // „do połowy łydki” — wyższa cholewka niż trzewik
                    'calf' => $has('/\b(polowy\s+lydki|mid[\s-]*calf|calf[\s-]*length|semi[\s-]*shank|obuwi\w*\s+wysok\w*|wysok\w*\s+obuwi\w*)\b/u', $weak),
                    // obuwie higieniczne / spożywcze (łatwe do mycia, do prania) i wsuwane — inne przeznaczenie i krój
                    // „wkładka higieniczna” to zwykła wkładka do buta, nie obuwie higieniczne
                    'hygiene' => $has('/\b((?<!wkladka\s)(?<!wkladki\s)(?<!wkladke\s)higien\w*|hygien\w*|przemysl\w*\s+spozywcz\w*|food\s+industry|latw\w*\s+(?:do\s+)?(?:mycia|czyszczeni\w*|utrzymani\w*)|do\s+prania|pran\w*\s+w\s+\d+)/u', $weak),
                    'slip_on' => $has('/\b(wsuwan\w*|slip[\s-]*on|bez\s+sznurowa\w*)\b/u', $text['weak']),
                    // perforowana cholewka (nie „antyperforacja” = odporność na przebicie)
                    'perforated' => $has('/\b(perforowan\w*|perforated|z\s+perforacj\w*)\b/u', $weak),
                ];
                foreach ($this->footwearComponents($text['weak']) as $part => $material) {
                    $k['main_'.$part] = $material;
                }
                foreach ($this->footwearComponents($text['strong']) as $part => $material) {
                    $k['strong_'.$part] = $material;
                }
                $metalFree = '/\bmetal[\s-]*free\b|\bbezmetalow\w*|\b(?:bez|brak)\s+element\w*\s+metal\w*|\b(?:calkowic\w*|w\s+calosci)\s+(?:\w+\s+){0,2}?(?:wolne?|wolny|pozbawion\w*)\s+(?:od\s+)?metal\w*|\b(?:obuwi\w*|but\w*|calkowic\w*|wyrob\w*)\W+(?:\w+\W+){0,3}?bez\s+(?:zawartosci\s+)?(?:element\w*\s+)?metal\w*/u';
                $cf = [
                    'fo_2011' => ['weak' => $has('/20345\s*:\s*2011/u', $text['weak']), 'strong' => false],
                    // cały but bez metalu („Metal free”) — u zamiennika cały but bez metalu, nie tylko podnosek
                    'metal_free' => ['weak' => $has($metalFree, $text['weak']), 'strong' => $has($metalFree, $text['strong'])],
                ];
                break;
            case PpeAssortment::FAMILY_RESPIRATORY:
                $k += [
                    'valve' => $this->valve($sources),
                    // węgiel aktywny (spawalnicze, na opary, gazy kwaśne) i półmaski spożywcze to inne wyroby
                    'carbon' => $has('/\b(wegl\w*\s+aktyw\w*|aktywn\w*\s+wegl\w*|wegl\w*\s+aktywowan\w*|carbon|active\s+coal|activated|spawal\w*|welding|ozon\w*|par\w*\s+organiczn\w*|gaz\w*\s+kwasn\w*|acid\s+gas\w*|organic\s+vapou?r\w*|nuisance)\b/u', $weak),
                    'food' => $has('/\b(przemysl\w*\s+spozywcz\w*|sektor\w*\s+spozywcz\w*|spozywcz\w*|food|wykrywaln\w*|detectable)\b/u', $weak),
                    'shape' => $this->maskShape($weak),
                ];
                $cf = [
                    'nr' => $c('/\bffp\s?-?[123]\s*nr\b/u'),
                    'r' => $c('/\bffp\s?-?[123]\s*r\b/u'),
                    'dolomite' => $c('/\bffp\s?-?[123]\s*(?:nr|r)?\s*d\b|dolomit\w*/u'),
                ];
                break;
            case PpeAssortment::FAMILY_HEARING:
                $k += [
                    'plug_kind' => $this->plugKind($weak),
                    'corded' => $has('/\b(sznur\w*|corded)\b/u', $weak),
                    'detectable' => $has('/\b(wykrywaln\w*|detect\w*|detekowaln\w*)\b/u', $weak),
                    'banded' => $has('/\b(palak\w*|banded|band)\b/u', $weak),
                    // sposób noszenia nauszników: pałąk nagłowny / nakarkowy; nahełmowe osobno (poza automatem)
                    'mount' => $this->earmuffMount($weak),
                    'helmet' => $has('/\b(nahelm\w*|(?:na|do)\s+(?:kask|helm)\w*|helmet|352\s?-\s?3)\b/u', $weak),
                    'electronic' => $has('/\b(elektron\w*|aktywn\w*|radio\w*|bluetooth|level[\s-]*dependent|352\s?-\s?[468]|komunikac\w*)\b/u', $weak),
                    'dispenser' => $has('/\b(dozownik\w*|dispenser\w*|refill\w*|uzupelni\w*)\b/u', $weak),
                    'semi_insert' => $has('/\b(nie\s+wnika\w*|polwkladk\w*|semi[\s-]*(?:insert|aural)\w*|canal\s+cap\w*)\b/u', $text['weak']),
                    'folding' => $has('/\b(skladan\w*|folding|foldable)\b/u', $weak),
                ];
                break;
        }

        return ['k' => $k, 'c' => $cf];
    }

    /**
     * Materiał podnoska i wkładki antyprzebiciowej: metal / nonmetal / null (karta nie mówi albo przeczy sobie).
     * „Bez zawartości metalu” całego buta daje nonmetal obu elementom, chyba że element wprost jest stalowy.
     *
     * @return array{toe: ?string, plate: ?string}
     */
    private function footwearComponents(string $text): array
    {
        // „podnosek bez metalu” to podnosek niemetalowy — zaprzeczenie zamieniamy na słowo, zanim szukamy „metal…”
        $whole = preg_match('/\b(?:bez\s+(?:zawartosci\s+)?metal\w*|metal[\s-]*free)\b/u', $text) === 1;
        $text = preg_replace('/\b(?:bez\s+(?:zawartosci\s+)?metal\w*|metal[\s-]*free)\b/u', 'niemetalowy', $text) ?? $text;
        // „without steel toe cap”, „bez podnoska” — podnoska nie ma (klasy O), to nie podnosek stalowy
        $text = preg_replace('/\b(?:without\s+(?:\w+\s+){0,2}?toe[\s-]*caps?|bez\s+(?:\w+\s+)?podnosk\w*|no\s+toe[\s-]*caps?)\b/u', 'brakochrony', $text) ?? $text;
        $metal = '(?:stal\w*|steel|alumin\w*|metal(?:ow\w*|iczn\w*)?)(?!\w)';
        $nonMetal = '(?:kompozyt\w*|composite|fiberglass|wlokn\w*|fibre\w*|fiber\w*|tworzyw\w*|polimer\w*|plastik\w*|tekstyl\w*|textile|kevlar\w*|nanokarbon\w*|karbon\w*|carbon|xenova|niemetal\w*|nonmetal\w*|non-metal\w*)';
        $toe = '(?:podnos\w*|nosek|noskiem|toe\s*caps?|toecap\w*)';
        $plate = '(?:wkladk\w*\s+(?:antyprzebic\w*|odporn\w*\s+na\s+przebic\w*|chroniac\w*\s+przed\s+przebic\w*)|przeszyw\w*|podeszw\w*\s+antyprzebic\w*|midsole|anti-?perforation\s+\w+|penetration[\s-]+resistant\s+\w+|puncture[\s-]+resistant\s+\w+|plate)';
        // zdanie po zdaniu (punkty listy, kropki, średniki): „• Stalowa wkładka antyprzebiciowa • Podnosek xenova bez
        // metalu” to dwa elementy z dwoma materiałami, nie sprzeczność
        $sentences = array_values(array_filter(array_map('trim', preg_split('/[.;•\n\r]+|\s[-–]\s/u', $text) ?: [])));
        // słowa pomiędzy elementem a materiałem nie mogą być innym materiałem ani innym elementem
        $gap = '(?:(?!'.$metal.'|'.$nonMetal.'|'.$toe.'|'.$plate.')\w+\W+)';
        $read = function (string $part) use ($sentences, $metal, $nonMetal, $gap): ?string {
            $isMetal = false;
            $isNon = false;
            foreach ($sentences as $sentence) {
                $isMetal = $isMetal || preg_match('/\b'.$part.'\W+'.$gap.'{0,5}?'.$metal.'\b|\b'.$metal.'\W+'.$gap.'{0,2}?'.$part.'/u', $sentence) === 1;
                $isNon = $isNon || preg_match('/\b'.$part.'\W+'.$gap.'{0,5}?'.$nonMetal.'\b|\b'.$nonMetal.'\W+'.$gap.'{0,2}?'.$part.'/u', $sentence) === 1;
            }

            return match (true) {
                $isMetal && $isNon => null,
                $isMetal => 'metal',
                $isNon => 'nonmetal',
                default => null,
            };
        };
        $out = ['toe' => $read($toe), 'plate' => $read($plate)];
        // „bez zawartości metalu” całego buta (zdanie bez nazwy elementu) — element bez podanego materiału też
        // niemetalowy; „podnosek w 100% bez zawartości metalu” mówi tylko o podnosku
        if ($whole) {
            foreach ($sentences as $sentence) {
                if (preg_match('/\bniemetalowy\b/u', $sentence) === 1
                    && preg_match('/\b(?:'.$toe.'|'.$plate.')/u', $sentence) !== 1
                    && preg_match('/\b(?:obuwi\w*|but\w*|wyrob\w*|calkowic\w*|cal\w*|model\w*|footwear|shoes?)\b/u', $sentence) === 1) {
                    foreach ($out as $part => $material) {
                        $out[$part] ??= 'nonmetal';
                    }
                    break;
                }
            }
        }

        return $out;
    }

    /** Zakres powłoki rękawicy: full / three_quarter / palm; null — karta nie mówi albo podaje kilka. */
    private function gloveCoverage(string $text): ?string
    {
        $found = array_keys(array_filter([
            'full' => preg_match('/\b(peln\w*\s+(?:powlok\w*|oblan\w*|pokryc\w*|powlecz\w*|zanurz\w*)|calkowic\w*\s+(?:oblan\w*|powlek\w*|pokryt\w*|zanurz\w*)|(?:oblan\w*|powlek\w*|pokryt\w*)\s+calkowic\w*|fully\s+(?:coated|dipped)|full(?:y)?[\s-]+(?:coat\w*|dip\w*))/u', $text) === 1,
            'three_quarter' => preg_match('/(3\s?\/\s?4|¾|trzy\s+czwarte|three[\s-]*quarter)/u', $text) === 1,
            'palm' => preg_match('/\b(powlek\w*\s+(?:\w+\s+){0,3}?dlon\w*|powlok\w*\s+(?:\w+\s+){0,2}?(?:na\s+)?dlon\w*|na\s+dloni\s+i\s+(?:koncach\s+)?palc\w*|palm[\s-]*(?:coat\w*|dip\w*)|palm\s+and\s+finger\w*|(?:wentylowan\w*|przewiewn\w*|oddychajac\w*|odkryt\w*|niepowlekan\w*)\s+grzbiet\w*|ventilated\s+back|breathable\s+back)/u', $text) === 1,
        ]));

        return count($found) === 1 ? $found[0] : null;
    }

    /** Materiały powłoki (zbiór porównywany równością); null — karta nie mówi. */
    private function gloveCoatings(string $text): ?string
    {
        $map = [
            'nitryl' => '/\b(nitryl\w*|nitrile|nbr)\b/u',
            'poliuretan' => '/\b(poliuretan\w*|polyurethane|pu)\b/u',
            'lateks' => '/\b(lateks\w*|latex)\b/u',
            'pcv' => '/\b(pcv|pvc|polichlorek\w*)\b/u',
            'neopren' => '/\b(neopren\w*)\b/u',
            'skora' => '/\b(skor\w*|leather|licow\w*|dwoin\w*)\b/u',
        ];
        $out = [];
        foreach ($map as $name => $re) {
            if (preg_match($re, $text) === 1) {
                $out[] = $name;
            }
        }

        return $out === [] ? null : implode(',', $out);
    }

    /**
     * Zawór wydechowy, pole po polu, bez zdań o wariantach: przeczenie („Zawór wydechowy: brak”, „bez zaworu”,
     * „without valve”) i potwierdzenie („z zaworem”, „with valve”, „FFP2 V”); oba albo żadne — null.
     *
     * @param  list<CardSource>  $sources
     */
    private function valve(array $sources): ?string
    {
        // [mocne źródła, wszystkie pola]: przeczenie / potwierdzenie
        $neg = [false, false];
        $pos = [false, false];
        foreach ($sources as $source) {
            $strong = $this->isStrongSource($source);
            foreach ($this->sentences($this->lower($source->text)) as $sentence) {
                if ($source->source === CardSource::DESCRIPTION && preg_match(self::VARIANT_SENTENCE_RE, $sentence) === 1) {
                    continue;
                }
                $n = preg_match('/\bzawor\w*(?:\s+wydechow\w*)?\s*[:\-–]\s*(?:brak|nie|bez)\b|\bbrak\s+zawor\w*|\bbez\s+zawor\w*|\bnie\s+posiada\s+zawor\w*|\bwithout\s+(?:an?\s+)?(?:exhalation\s+)?valve|\bno\s+(?:exhalation\s+)?valve|\bvalve\s*[:\-–]\s*no\b|\b(?:unvalved|valveless)\b/u', $sentence) === 1;
                $p = preg_match('/\bz\s+zawor\w*|\bzawor\w*(?:\s+wydechow\w*)?\s*[:\-–]\s*(?:tak|jest|posiada)\b|\bwyposazon\w*\s+w\s+zawor\w*|\bposiada\s+zawor\w*|\bzaworem\b|\bzaworkiem\b|\bwith\s+(?:an?\s+)?(?:exhalation\s+)?valve|\bvalved\b|\bffp\s?[123]\s+v\b/u', $sentence) === 1;
                $neg = [$neg[0] || ($strong && $n), $neg[1] || $n];
                $pos = [$pos[0] || ($strong && $p), $pos[1] || $p];
            }
        }

        // wartość tylko z mocnego źródła; jakakolwiek sprzeczność w innych polach (specyfikacja z wzbogacania) — brak
        return match (true) {
            $neg[1] && $pos[1] => null,
            $neg[0] => 'bez zaworu',
            $pos[0] => 'z zaworem',
            default => null,
        };
    }

    /** Nauszniki: na pałąku nagłownym / nakarkowym; null — karta nie mówi albo podaje oba. */
    private function earmuffMount(string $text): ?string
    {
        $neck = preg_match('/\b(nakark\w*|karkow\w*|na\s+kark\w*|neckband|behind[\s-]+the[\s-]+head)\b/u', $text) === 1;
        $head = preg_match('/\b(naglown\w*|palak\w*\s+na\s+glow\w*|headband|over[\s-]+the[\s-]+head)\b/u', $text) === 1;
        // samo „na pałąku” bez słowa o karku to pałąk nagłowny
        $band = preg_match('/\b(palak\w*|band)\b/u', $text) === 1;

        return match (true) {
            $neck && $head => null,
            $neck => 'nakarkowe',
            $head || $band => 'nagłowne',
            default => null,
        };
    }

    /** Kształt półmaski: składana / kubkowa; null — karta nie mówi albo podaje oba. */
    private function maskShape(string $text): ?string
    {
        // „wyprofilowana blaszka nosowa” to nie kształt półmaski — tylko słowa o korpusie
        $folded = preg_match('/\b(skladan\w*|skladac\w*|fold\w*|flat[\s-]*fold\w*)\b/u', $text) === 1;
        $cup = preg_match('/\b(kopul\w*|kubk\w*|formowan\w*|formed|cup|cup[\s-]*shaped|preformed|ksztaltow\w*|tvarovan\w*|moulded|molded|uformowan\w*)\b/u', $text) === 1;

        return match (true) {
            $folded && $cup => null,
            $folded => 'składana',
            $cup => 'kubkowa',
            default => null,
        };
    }

    /** Wkładki: jawne „jednorazowe” albo „wielokrotne”; oba naraz albo żadne — null. */
    private function plugKind(string $text): ?string
    {
        $disposable = preg_match('/\b(jednorazow\w*|disposable)\b/u', $text) === 1;
        $reusable = preg_match('/\b(wielokrotn\w*|wielorazow\w*|reusable|przemywaln\w*)\b/u', $text) === 1;
        if ($disposable && $reusable) {
            return null;
        }
        if ($reusable) {
            return 'wielokrotne';
        }

        // „z pianki” to tylko materiał — jednorazowość musi być napisana wprost
        return $disposable ? 'jednorazowe' : null;
    }

    /**
     * Oznaczenia obuwia z ciągów przy klasie („S3 HI HRO SRC”) i oznaczenia wymagań dodatkowych jako osobne słowa
     * — nie jako część nazwy („HI-POLY” to wkładka).
     * $strong: tylko z mocnych pól (nazwa, normy, producent, cennik, tabelka, zdania opisu bez wariantów).
     *
     * @param  list<CardSource>  $sources
     * @return list<string>
     */
    private function footwearMarkings(array $sources, bool $strong): array
    {
        $out = [];
        foreach ($sources as $source) {
            if ($strong && ! $this->isStrongSource($source)) {
                continue;
            }
            $texts = $source->source === CardSource::DESCRIPTION && $strong
                ? array_filter($this->sentences($source->text), fn (string $s): bool => preg_match(self::VARIANT_SENTENCE_RE, $this->lower($s)) !== 1)
                : [$source->text];
            foreach ($texts as $text) {
                if (preg_match_all(self::MARKING_RUN_RE, $text, $runs) > 0) {
                    foreach ($runs[1] as $run) {
                        preg_match_all(self::MARKING_TOKEN_RE, $run, $tokens);
                        foreach ($tokens[1] as $token) {
                            $out[$token] = true;
                        }
                    }
                }
                // oznaczenia z wymaganiem dodatkowym bywają w zdaniu („spełnia kryteria ESD”, „CI – izolacja spodu od
                // zimna”) — jako osobne słowo, nie część nazwy („HI-POLY”)
                if (preg_match_all('/(?<![\p{L}\d\-])(ESD|CI|HI|HRO|WRU|WR|AN)(?![\p{L}\d\-])/u', $text, $words) > 0) {
                    foreach ($words[1] as $token) {
                        // potwierdzenie ESD tylko z progiem 35 MΩ albo EN 61340 — „antystatyczne (ESD)” to nie ESD
                        if ($strong && $token === 'ESD' && preg_match('/35\s*M|61340|ESD\s*[:–-]\s*(?:tak|spełnia|<)/iu', $text) !== 1) {
                            continue;
                        }
                        $out[$token] = true;
                    }
                }
                // HRO opisane zdaniem: kontakt z gorącym podłożem do 300°C
                if (preg_match('/gor[aą]c\w*\s+podło\w*|kontakt\w*\s+z\s+(?:gor[aą]c\w*|ciepłem)|300\s*°\s*C/iu', $text) === 1) {
                    $out['HRO'] = true;
                }
            }
        }
        $out = array_keys($out);
        sort($out);

        return $out;
    }

    private function isStrongSource(CardSource $source): bool
    {
        return ! in_array($source->source, [CardSource::SPECS, CardSource::FEATURES, CardSource::PAYLOAD_NORMS, CardSource::MATERIALS, CardSource::VARIANT], true);
    }

    /**
     * Tekst mocnych źródeł: pola karty poza listami z wzbogacania, a z opisu tylko zdania bez wariantów i serii.
     *
     * @param  list<CardSource>  $sources
     */
    private function strongText(array $sources): string
    {
        $parts = [];
        foreach ($sources as $source) {
            if (! $this->isStrongSource($source)) {
                continue;
            }
            if ($source->source !== CardSource::DESCRIPTION) {
                $parts[] = $source->text;

                continue;
            }
            foreach ($this->sentences($source->text) as $sentence) {
                if (preg_match(self::VARIANT_SENTENCE_RE, $this->lower($sentence)) !== 1) {
                    $parts[] = $sentence;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?;])\s+|\R/u', $text) ?: [])));
    }

    /** Małe litery bez polskich znaków, z zachowaną interpunkcją (zapisy „3/4”, „Zawór: brak”). */
    private function lower(string $text): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return Str::ascii(strtr(mb_strtolower($text), $map));
    }

    private function withoutNegations(string $text): string
    {
        return preg_replace(self::NEGATION_RE, ' ', $text) ?? $text;
    }

    private function identityText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
            $product->categoryAsEvidence(),
        ])));
    }
}
