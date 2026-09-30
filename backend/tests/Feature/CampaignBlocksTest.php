<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\User;
use App\Services\Campaigns\CampaignBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Bloki treści maila: normalizacja, limity, liczność, adresy, obrazki, tryb ścisły (wysyłka) i łagodny (projekt). */
final class CampaignBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_fields_removes_unknown_keys_and_fills_missing(): void
    {
        $blocks = CampaignBlocks::validate([
            ['type' => 'header', 'extra' => 'x'],
            ['type' => 'heading', 'text' => '  Końcówki serii  '],
            ['type' => 'text'],
            ['type' => 'image', 'alt' => null],
            ['type' => 'products'],
            ['type' => 'button', 'label' => 'Zobacz katalog'],
            ['type' => 'footer', 'text' => "Linia 1\nLinia 2", 'style' => 'color:red'],
        ], false);

        $this->assertSame([
            ['type' => 'header', 'logo' => null],
            ['type' => 'heading', 'text' => 'Końcówki serii'],
            ['type' => 'text', 'text' => ''],
            ['type' => 'image', 'asset' => null, 'alt' => '', 'url' => ''],
            ['type' => 'products', 'layout' => 'grid3'],
            ['type' => 'button', 'label' => 'Zobacz katalog', 'url' => ''],
            ['type' => 'footer', 'text' => "Linia 1\nLinia 2"],
        ], $blocks);
        $this->assertSame(CampaignBlocks::standard(), CampaignBlocks::validate(CampaignBlocks::standard(), true));
    }

    public function test_structure_types_and_limits(): void
    {
        $this->assertInvalid('nie-tablica', 'blocks');
        $this->assertInvalid(['a' => ['type' => 'products']], 'blocks');
        $this->assertInvalid(array_fill(0, 21, ['type' => 'text', 'text' => 'x']), 'blocks', 'najwyżej 20');
        $this->assertInvalid([['type' => 'products'], ['type' => 'video']], 'blocks.1', 'nieznany rodzaj');
        $this->assertInvalid([['type' => 'products'], 'tekst'], 'blocks.1');
        $this->assertInvalid([['type' => 'products'], ['type' => 'text', 'text' => ['x']]], 'blocks.1.text', 'zły format');
        $this->assertInvalid([['type' => 'products', 'layout' => 'mosaic']], 'blocks.0.layout');

        $this->assertInvalid([['type' => 'products'], ['type' => 'heading', 'text' => str_repeat('a', 201)]], 'blocks.1.text', 'najwyżej 200');
        $this->assertInvalid([['type' => 'products'], ['type' => 'heading', 'text' => "Dwie\nlinie"]], 'blocks.1.text', 'jedną linią');
        $this->assertInvalid([['type' => 'products'], ['type' => 'text', 'text' => str_repeat('a', 5001)]], 'blocks.1.text');
        $this->assertInvalid([['type' => 'products'], ['type' => 'footer', 'text' => str_repeat('a', 1001)]], 'blocks.1.text');
        $this->assertInvalid([['type' => 'products'], ['type' => 'button', 'label' => str_repeat('a', 61)]], 'blocks.1.label');
        $this->assertInvalid([['type' => 'products'], ['type' => 'image', 'alt' => str_repeat('a', 201)]], 'blocks.1.alt');
        $this->assertInvalid([['type' => 'products'], ['type' => 'button', 'url' => 'https://a.pl/'.str_repeat('a', 500)]], 'blocks.1.url');

        // na granicy limitów — w porządku
        $ok = CampaignBlocks::validate([
            ['type' => 'products'], ['type' => 'heading', 'text' => str_repeat('ą', 200)], ['type' => 'text', 'text' => str_repeat('ą', 5000)],
        ], true);
        $this->assertSame(200, mb_strlen($ok[1]['text']));
    }

    public function test_exactly_one_products_and_at_most_one_header_and_footer(): void
    {
        $this->assertInvalid([['type' => 'text', 'text' => 'x']], 'blocks', 'dokładnie jeden blok produktów');
        $this->assertInvalid([['type' => 'products'], ['type' => 'products', 'layout' => 'list']], 'blocks', 'dokładnie jeden blok produktów');
        $this->assertInvalid([['type' => 'header'], ['type' => 'products'], ['type' => 'header']], 'blocks', 'jedno logo');
        $this->assertInvalid([['type' => 'products'], ['type' => 'footer'], ['type' => 'footer']], 'blocks', 'jedną stopkę');

        // bez logo i stopki też można
        $this->assertCount(2, CampaignBlocks::validate([['type' => 'text', 'text' => 'x'], ['type' => 'products', 'layout' => 'list']], true));
    }

    public function test_urls_only_https_and_mailto_only_in_button_when_sending(): void
    {
        foreach (['javascript:alert(1)', 'http://supon.pl', 'htt', 'https://supon.pl/a b', "https://supon.pl/\na", "https://supon.pl/\ta", 'https://', '//supon.pl', 'data:text/html,x', 'https://user@evil.pl'] as $bad) {
            $this->assertInvalid([['type' => 'products'], ['type' => 'button', 'label' => 'X', 'url' => $bad]], 'blocks.1.url', 'https://', $bad, strict: true);
            $this->assertInvalid([['type' => 'products'], ['type' => 'image', 'url' => $bad]], 'blocks.1.url', 'https://', $bad, strict: true);
        }
        $this->assertInvalid([['type' => 'products'], ['type' => 'image', 'url' => 'mailto:jan@supon.pl']], 'blocks.1.url', strict: true);

        $ok = CampaignBlocks::validate([
            ['type' => 'products'],
            ['type' => 'button', 'label' => 'Napisz', 'url' => 'mailto:jan@supon.pl?subject=Katalog'],
            ['type' => 'button', 'label' => 'Katalog', 'url' => ' https://supon.pl/katalog?x=1#a '],
        ], true);
        $this->assertSame('https://supon.pl/katalog?x=1#a', $ok[2]['url']);
        $this->assertTrue(CampaignBlocks::validUrl('https://supon.pl:8443/', false));
    }

    public function test_draft_accepts_unfinished_urls_without_control_characters(): void
    {
        // autozapis w trakcie pisania: adres sprawdza dopiero wysyłka, renderer pomija niepoprawne linki
        $blocks = CampaignBlocks::validate([
            ['type' => 'products'],
            ['type' => 'button', 'label' => 'Katalog', 'url' => 'htt'],
            ['type' => 'button', 'label' => 'Zły', 'url' => 'javascript:alert(1)'],
            ['type' => 'image', 'url' => "https://supon.pl/\r\na\x00b"],
        ], false);

        $this->assertSame(['htt', 'javascript:alert(1)', 'https://supon.pl/ab'], [$blocks[1]['url'], $blocks[2]['url'], $blocks[3]['url']]);
        // limit długości obowiązuje zawsze
        $this->assertInvalid([['type' => 'products'], ['type' => 'image', 'url' => str_repeat('a', 501)]], 'blocks.1.url', 'najwyżej 500');
    }

    public function test_assets_must_exist_without_owner_check(): void
    {
        $owner = User::factory()->create();
        $asset = CampaignAsset::query()->create([
            'uuid' => '0b7d6a00-1111-4222-8333-444455556666', 'user_id' => $owner->id, 'path' => 'campaign-assets/x.jpg',
            'mime' => 'image/jpeg', 'width' => 100, 'height' => 50, 'size' => 10,
        ]);

        $this->assertInvalid([['type' => 'products'], ['type' => 'image', 'asset' => '9f9f9f9f-1111-4222-8333-444455556666']], 'blocks.1.asset', 'nie istnieje');
        $this->assertInvalid([['type' => 'header', 'logo' => '../../.env'], ['type' => 'products']], 'blocks.0.logo', 'nie istnieje');
        // numer elementu liczony w żądaniu, także gdy wcześniejszy element był błędny
        $this->assertInvalid([['type' => 'video'], ['type' => 'products'], ['type' => 'image', 'asset' => '9f9f9f9f-1111-4222-8333-444455556666']], 'blocks.2.asset', 'element nr 3');

        // cudzy obrazek wolno (wspólne szablony wskazują obrazki administratora)
        $blocks = CampaignBlocks::validate([['type' => 'header', 'logo' => $asset->uuid], ['type' => 'products'], ['type' => 'image', 'asset' => $asset->uuid]], true);
        $this->assertSame($asset->uuid, $blocks[0]['logo']);
    }

    public function test_strict_requires_complete_button_and_image_lenient_does_not(): void
    {
        $blocks = [
            ['type' => 'products'],
            ['type' => 'button', 'label' => '', 'url' => ''],
            ['type' => 'image', 'asset' => null, 'alt' => 'Baner'],
            ['type' => 'heading', 'text' => ''],
        ];
        $this->assertCount(4, CampaignBlocks::validate($blocks, false));

        try {
            CampaignBlocks::validate($blocks, true);
            $this->fail('Tryb ścisły powinien odrzucić niekompletne bloki.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertSame(['Przycisk (element nr 2): wpisz napis na przycisku.'], $errors['blocks.1.label']);
            $this->assertSame(['Przycisk (element nr 2): podaj adres https://… albo mailto:…'], $errors['blocks.1.url']);
            $this->assertSame(['Grafika (element nr 3): wgraj obrazek albo usuń ten element.'], $errors['blocks.2.asset']);
            // pusty nagłówek nie blokuje wysyłki — renderer go pomija
            $this->assertArrayNotHasKey('blocks.3.text', $errors);
        }
    }

    public function test_legacy_blocks_from_old_campaign_fields(): void
    {
        $campaign = new Campaign(['heading' => "Końcówki\r\nserii", 'intro' => "Dzień dobry,\nmamy towar", 'layout' => 'list']);

        $this->assertSame([
            ['type' => 'header', 'logo' => null],
            ['type' => 'heading', 'text' => 'Końcówki serii'],
            ['type' => 'text', 'text' => "Dzień dobry,\nmamy towar"],
            ['type' => 'products', 'layout' => 'list'],
            ['type' => 'footer', 'text' => ''],
        ], $campaign->effectiveBlocks());
        // stara kampania przechodzi walidację wysyłki
        $this->assertCount(5, CampaignBlocks::validate($campaign->effectiveBlocks(), true));

        $campaign->blocks = CampaignBlocks::standard();
        $this->assertSame(CampaignBlocks::standard(), $campaign->effectiveBlocks());
    }

    private function assertInvalid(mixed $blocks, string $key, ?string $message = null, string $case = '', bool $strict = false): void
    {
        try {
            CampaignBlocks::validate($blocks, $strict);
            $this->fail("Bloki powinny być odrzucone ({$key}) {$case}");
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey($key, $errors, $case.' → '.json_encode($errors, JSON_UNESCAPED_UNICODE));
            if ($message !== null) {
                $this->assertStringContainsString($message, implode(' ', $errors[$key]), $case);
            }
        }
    }
}
