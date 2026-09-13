<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\ProductMatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Wspólna część testów Feature zestawu „przetarg opisowy 15”: przetarg z 15 pozycjami,
 * jedna lista włączonych asercji i jedna metoda oceniająca pozycję po dopasowaniu.
 * Pełny przebieg i testy per pozycja leżą w osobnych plikach, bo paratest liczy czas
 * per plik, a sam przebieg 15 pozycji zajmuje ~14 s.
 */
trait Opisowy15TenderMatch
{
    /**
     * Pozycje, dla których dana grupa asercji jest dziś zielona (wariant B‑empty odtwarza
     * wybory produkcji — AUDYT_D §4.2). Pozostałe pozycje przechodzą przez ten sam kod, ale
     * tylko z asercjami inwariantnymi; koordynator dopisuje numery po scaleniu W1–W4 (PLAN §3).
     *
     * @var array<string, list<int>>
     */
    private const ENABLED_LINES = [
        // wybór: main_product_id ∈ {oczekiwany, null}, nic z forbidden_skus, przy null status „brak”. Wyłączone: 2 — karta 34837018 ma opis kategorii sklepu, nie produktu (dane; products:audit-descriptions), 7 — właściwa karta 44‑304 nie wchodzi do puli (retrieval, D6/Fala 2), 8 — wybierana 9312+ (FFP1 z zaworem) bez potwierdzenia węgla aktywnego z wymagania (cecha miękka, Fala 2 P7)
        'pick' => [1, 3, 4, 5, 6, 9, 10, 11, 12, 13, 14, 15],
        // próg zapisu D1: trafienie zapisane tylko z ai_match_percent ≥ minMatchScore
        'threshold' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // uzasadnienie: bez fuzzy_model, bez „model: …”/„wymagano: …” w etykiecie zamiennika, bez wiersza catalog/rule
        'reasons' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
    ];

    private ProductMatchService $matcher;

    private function prepareOpisowy15Tender(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $this->matcher = app(ProductMatchService::class);
    }

    /**
     * Inwarianty obowiązują każdą pozycję (zapis nigdy nie łamie bramki ani progu zapisu);
     * asercje o właściwym wyborze, progu i czystym uzasadnieniu — tylko dla pozycji z ENABLED_LINES.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $ids
     */
    private function assertLineOutcome(array $line, TenderItem $item, array $ids): void
    {
        $lineNo = (int) $line['line_no'];
        $item->refresh();
        $item->load('mainProduct');
        $picked = $item->mainProduct?->sku;
        $reasons = is_array($item->ai_match_reasons) ? $item->ai_match_reasons : [];
        $codes = array_column($reasons, 'code');
        $label = "poz. {$lineNo} (wybrano: ".($picked ?? 'brak').')';

        $this->assertNotSame('asortyment_reject', $codes[0] ?? null, "{$label}: zapisano kartę odrzuconą przez bramkę");
        if ($item->main_product_id === null) {
            $this->assertSame('brak', $item->status, "{$label}: brak produktu, a status ≠ brak");
            $this->assertNull($item->ai_match_percent, "{$label}: brak produktu z procentem");
        } else {
            $this->assertSame('matched', $item->status, "{$label}: produkt zapisany bez statusu matched");
            $this->assertGreaterThanOrEqual($this->matcher->applyMatchScore(), (int) $item->ai_match_percent, "{$label}: zapis poniżej progu apply");
            $this->assertLessThanOrEqual(100, (int) $item->ai_match_percent, $label);
        }

        if (self::lineEnabled('pick', $lineNo)) {
            $this->assertNotContains($picked, (array) $line['forbidden_skus'], "{$label}: karta z listy zakazanych");
            if ($item->main_product_id !== null) {
                $this->assertSame(
                    $ids[(string) $line['expected_sku']],
                    (int) $item->main_product_id,
                    "{$label}: oczekiwano {$line['expected_sku']}",
                );
            }
        }

        if (self::lineEnabled('threshold', $lineNo) && $item->main_product_id !== null) {
            $this->assertGreaterThanOrEqual($this->matcher->minMatchScore(), (int) $item->ai_match_percent, "{$label}: trafienie zapisane poniżej progu min (D1)");
        }

        if (self::lineEnabled('reasons', $lineNo)) {
            $this->assertNotContains('fuzzy_model', $codes, "{$label}: „Model z SIWZ (literówka)” dla opisu bez kodu");
            foreach ($reasons as $reason) {
                if (($reason['code'] ?? '') !== 'brand_substitute') {
                    continue;
                }
                $text = (string) ($reason['label'] ?? '');
                $this->assertStringNotContainsString('model:', $text, "{$label}: „model” wyczytany z liczb w opisie");
                $this->assertStringNotContainsString('wymagano:', $text, "{$label}: producent wyczytany z opisu bez marki");
            }
            // match_allow_catalog_rows jest domyślnie wyłączone; 'rule' to wiersz skrótu deterministycznego (PLAN D7)
            $this->assertNotContains($item->match_source, ['catalog', 'rule'], "{$label}: wiersz katalogowy zapisany mimo wyłączonego ustawienia");
        }
    }

    private static function lineEnabled(string $group, int $lineNo): bool
    {
        return in_array($lineNo, self::ENABLED_LINES[$group], true);
    }

    private function makeOpisowy15Tender(string $number): Tender
    {
        return Tender::query()->create([
            'number' => $number,
            'title' => 'Przetarg testowy opisowy 15',
            'client_id' => Client::query()->create(['name' => 'Klient opisowy'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @return array<int, TenderItem> line_no => pozycja
     */
    private function makeOpisowy15Items(Tender $tender): array
    {
        $items = [];
        foreach (Opisowy15Fixture::items() as $line) {
            $lineNo = (int) $line['line_no'];
            $items[$lineNo] = TenderItem::query()->create([
                'tender_id' => $tender->id,
                'line_no' => $lineNo,
                'requirement' => (string) $line['requirement'],
                'quantity' => 10,
                'status' => 'brak',
            ]);
        }

        return $items;
    }
}
