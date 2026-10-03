<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\User;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/notices/{notice} — szczegóły ogłoszenia: fragmenty treści słowo w słowo z próbek Biuletynu
 * (tests/Fixtures/bzp), części z odczytu ogłoszenia, dokumenty z e-Zamówień (lista z próbki API
 * tests/Fixtures/ezamowienia/tender-documents.json) albo informacja o innej platformie.
 */
final class NoticeDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    /** postępowanie, z którego pochodzi próbka listy dokumentów e-Zamówień */
    private const SAMPLE_OCDS = 'ocds-148610-80ea708c-f1af-464b-a908-c2da5441f5de';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Warsaw'));
    }

    public function test_sections_word_for_word_lots_and_other_platform_documents(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');

        $response = $this->getJson("/api/notices/{$notice->id}")->assertOk();

        $response->assertJsonPath('row.id', $notice->id)
            ->assertJsonPath('html_available', true)
            ->assertJsonPath('html_note', null)
            ->assertJsonPath('can_import_documents', true);

        $sections = collect($response->json('sections'))->keyBy('key');
        // kolejność jak w ogłoszeniu (pierwszy wiersz fragmentu)
        $this->assertSame(
            ['organization', 'communication', 'subject', 'execution', 'criteria', 'qualification', 'deposit', 'deadlines', 'other'],
            $sections->keys()->all(),
        );
        $this->assertSame('Wadium i zabezpieczenie należytego wykonania umowy', $sections['deposit']['title']);
        $this->assertSame(implode("\n", [
            '6.4.) Zamawiający wymaga wadium: Tak',
            '6.4.1) Informacje dotyczące wadium:',
            '1. Wykonawca obowiązany jest wnieść wadium przed upływem terminu składania ofert w wysokości:',
            'Część 1 Gaśnice i węże pożarnicze 2 700,00 zł',
            'Część 2 Bielizna termoaktywna trudnopalna 2 200,00 zł',
            'Część 3 Hełmy strażackie i latarki 1 300,00 zł',
            'Część 4 Wyposażenie strażackie 1 900,00 zł',
            'Część 5 Kamery termowizyjne i multitoole 1 200,00 zł',
            '6.5.) Zamawiający wymaga zabezpieczenia należytego wykonania umowy: Tak',
        ]), $sections['deposit']['text']);
        $this->assertStringStartsWith(
            "8.1.) Termin składania ofert: 2026-09-30 08:00\n8.2.) Miejsce składania ofert: https://platformazakupowa.pl/pn/szi",
            $sections['deadlines']['text'],
        );
        // przy częściach: nagłówek części przed jej fragmentem
        $this->assertStringStartsWith("Część 1\n4.2.10.) Okres realizacji zamówienia albo umowy ramowej: 45 dni\nCzęść 2\n", $sections['execution']['text']);
        $this->assertStringStartsWith("Część 1\n4.3.) Kryteria oceny ofert:", $sections['criteria']['text']);
        $this->assertStringContainsString("Kryterium 3\n4.3.4.) Rodzaj kryterium: inne.\n4.3.5.) Nazwa kryterium: Termin gwarancji", $sections['criteria']['text']);
        // kryteria nie są powtarzane w przedmiocie; formułka RODO i stopka strony pominięte
        $this->assertStringNotContainsString('Kryterium 1', $sections['subject']['text']);
        $this->assertStringContainsString('Cześć 1 - Dostawa gaśnic i węzy - opis przedmiotu zamówienia stanowi Załącznik nr 13a do SWZ.', $sections['subject']['text']);
        $this->assertStringNotContainsString('RODO', $sections['communication']['text']);
        $this->assertStringNotContainsString('Biuletyn Zamówień Publicznych', $sections['other']['text']);
        $this->assertStringContainsString('1.2.) Nazwa zamawiającego: Stołeczny Zarząd Infrastruktury', $sections['organization']['text']);
        $this->assertStringContainsString('• świadectwo dopuszczenia do użytkowania CNBOP', $sections['qualification']['text']);
        foreach ($sections as $section) {
            $this->assertStringNotContainsString('<', $section['text']);
        }

        $response->assertJsonCount(5, 'lots')
            ->assertJsonPath('lots.0.lot_no', 1)
            ->assertJsonPath('lots.0.estimated_value', '126 019,26 PLN')
            ->assertJsonPath('lots.0.cpv_main', '35110000-8')
            ->assertJsonPath('lots.0.cpv_main_name', 'Sprzęt gaśniczy, ratowniczy i bezpieczeństwa')
            ->assertJsonPath('lots.1.description', 'Część 2 - Dostawa Bielizny trudnopalnej termoaktywna - opis przedmiotu zamówienia określa Załącznik nr 13b do SWZ');

        // inna platforma — bez zapytań do niej, tylko informacja
        $response->assertJsonPath('documents', [
            'available' => false,
            'source' => null,
            'items' => [],
            'note' => 'Dokumenty są na platformie platformazakupowa.pl — pobierz je ze strony postępowania i dodaj tutaj.',
        ]);
        Http::assertNothingSent();
    }

    public function test_without_stored_html_shows_lots_from_parsed_data(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->fixtureNotice('contract-lots');
        // system:prune skasował pełną treść (ogłoszenie bez przetargu po 30 dniach)
        DB::table('procurement_notices')->where('id', $notice->id)->update(['html_body' => null]);

        $this->getJson("/api/notices/{$notice->id}")
            ->assertOk()
            ->assertJsonPath('html_available', false)
            ->assertJsonPath('sections', [])
            ->assertJsonCount(5, 'lots')
            ->assertJsonPath('lots.4.estimated_value', '65 040,65 PLN')
            ->assertJsonPath('html_note', 'Pełnej treści tego ogłoszenia już nie przechowujemy (usuwana po 30 dniach, gdy z ogłoszenia nie założono przetargu). '
                .'Poniżej części zamówienia odczytane przy pobraniu ogłoszenia; całość jest na stronie ogłoszenia w Biuletynie.');
    }

    public function test_ezamowienia_documents_with_kinds_cached_for_an_hour(): void
    {
        $notice = $this->ezamowieniaNotice();
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList($notice)),
        ]);
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());

        $response = $this->getJson("/api/notices/{$notice->id}")->assertOk();

        $response->assertJsonPath('documents.available', true)
            ->assertJsonPath('documents.source', 'ezamowienia')
            ->assertJsonCount(11, 'documents.items')
            ->assertJsonPath('documents.note', 'Pliki opublikowane przez zamawiającego na platformie e-Zamówienia (stan z 20.9.2026, 10:00).');
        $items = collect($response->json('documents.items'))->keyBy('id');
        $ocds = (string) $notice->ocds_id;
        $this->assertSame([
            'id' => $ocds.'_8',
            'name' => 'Załącznik nr 1 - Formularz ofertowy',
            'file_name' => 'Załącznik nr 1 - Formularz ofertowy.docx',
            'published_at' => '2026-09-22T10:56:16.626000Z',
            'kind' => 'form',
            'importable' => true,
            'suggested' => true,
            // nazwa bez numeru pakietu („Pakiet nr 3”) — dokument nie jest przypisany do części
            'lot_no' => null,
            'lot_bhp' => null,
        ], $items[$ocds.'_8']);
        $this->assertSame('description', $items[$ocds.'_6']['kind']);
        $this->assertTrue($items[$ocds.'_5']['suggested']);
        $this->assertSame('swz', $items[$ocds.'_9']['kind']);
        $this->assertFalse($items[$ocds.'_9']['suggested']);
        $this->assertSame('other', $items[$ocds.'_10']['kind']);
        $this->assertSame('other', $items[$ocds.'_4']['kind']);

        Http::assertSent(fn ($request): bool => $request['tenderId'] === $ocds
            && $request->hasHeader('User-Agent', (string) config('bzp.user_agent')));

        // druga wizyta w ciągu godziny — z pamięci podręcznej
        $this->travel(30)->minutes();
        $this->getJson("/api/notices/{$notice->id}")->assertOk()->assertJsonCount(11, 'documents.items');
        Http::assertSentCount(1);
    }

    public function test_ezamowienia_list_error_is_reported_and_not_retried_at_once(): void
    {
        $notice = $this->ezamowieniaNotice();
        Http::fake(['ezamowienia.gov.pl/*' => Http::response('', 503)]);
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());

        $this->getJson("/api/notices/{$notice->id}")
            ->assertOk()
            ->assertJsonPath('documents.available', false)
            ->assertJsonPath('documents.source', 'ezamowienia')
            ->assertJsonPath('documents.items', [])
            ->assertJsonPath('documents.note', 'Nie udało się pobrać listy dokumentów z platformy e-Zamówienia (błąd HTTP 503). '
                .'Spróbuj ponownie za kilka minut albo pobierz pliki ze strony postępowania i dodaj je tutaj.');
        $this->getJson("/api/notices/{$notice->id}")->assertOk()->assertJsonPath('documents.available', false);
        Http::assertSentCount(1);
    }

    public function test_permissions_and_result_notice(): void
    {
        Http::fake();
        $notice = $this->fixtureNotice('contract-lots');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/notices/{$notice->id}")->assertForbidden();

        // handlowiec zakłada przetargi, ale nie odczytuje dokumentów (tenders.import)
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/notices/{$notice->id}")->assertOk()->assertJsonPath('can_import_documents', false);

        $result = $this->fixtureNotice('result-single-awarded');
        $this->assertSame(ProcurementNotice::TYPE_RESULT, $result->notice_type);
        $this->getJson("/api/notices/{$result->id}")->assertNotFound();
    }

    private function fixtureNotice(string $name): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path("tests/Fixtures/bzp/{$name}.json")), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }

    /** Ogłoszenie z próbki prowadzone na platformie e-Zamówienia (Gmina Moszczenica). */
    private function ezamowieniaNotice(): ProcurementNotice
    {
        $notice = $this->fixtureNotice('contract-single');
        $this->assertStringStartsWith('https://ezamowienia.gov.pl/mp-client/search/list/', (string) $notice->parsed['procedure_url']);

        return $notice;
    }

    /**
     * Próbka odpowiedzi GetTenderDocuments (inne postępowanie) z identyfikatorami przepisanymi na postępowanie ogłoszenia.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleList(ProcurementNotice $notice): array
    {
        $json = (string) file_get_contents(base_path('tests/Fixtures/ezamowienia/tender-documents.json'));

        return json_decode(str_replace(self::SAMPLE_OCDS, (string) $notice->ocds_id, $json), true);
    }
}
