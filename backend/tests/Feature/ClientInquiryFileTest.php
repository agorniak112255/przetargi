<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Zapytanie z pliku klienta (Excel, PDF, Word) i wyrób z tematu maila przy wierszach bez nazwy. */
final class ClientInquiryFileTest extends TestCase
{
    use RefreshDatabase;

    private const LETTER = "=== Plik klienta: zapytanie 12-2026.pdf ===\nZakład Usług Komunalnych Sp. z o.o.\nul. Polna 5, 35-001 Rzeszów\n"
        ."tel. 17 111 22 33, e-mail: zaopatrzenie@zuk.pl\n\nZAPYTANIE OFERTOWE nr 12/2026\n\n"
        ."Lp. | Nazwa | Ilość | j.m.\n1 | Rękawice MAPA 332 rozm. 9 | 4 | para\n\nZ poważaniem\nJan Kowalski";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_file_text_returns_spreadsheet_text_without_creating_inquiry(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Nazwa', 'Ilość'], ['Rękawice MAPA 332 rozm. 9', '4 pary']]);
        $path = tempnam(sys_get_temp_dir(), 'inq');
        (new Xlsx($book))->save($path);

        $this->post('/api/inquiries/file-text', [
            'file' => new UploadedFile($path, 'zapytanie klienta.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('text', "Nazwa | Ilość\nRękawice MAPA 332 rozm. 9 | 4 pary")
            ->assertJsonPath('chars', 48)
            ->assertJsonPath('file_name', 'zapytanie klienta.xlsx');

        $this->assertSame(0, ClientInquiry::query()->count());
        @unlink($path);
    }

    public function test_file_text_refuses_other_formats_with_a_reason(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->post('/api/inquiries/file-text', [
            'file' => UploadedFile::fake()->createWithContent('zapytanie.txt', 'Rękawice MAPA 332 rozm. 9 — 4 pary'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Dozwolone pliki: Excel (xlsx, xls, csv), PDF albo Word (docx, doc).');
    }

    public function test_file_text_needs_the_inquiries_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->post('/api/inquiries/file-text', [
            'file' => UploadedFile::fake()->createWithContent('zapytanie.csv', "Nazwa;Ilość\nRękawice;4"),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    /**
     * Pismo z nagłówkiem firmowym: cięcie stopki jak w mailu kończyło tekst na wierszu z telefonem, czyli przed
     * tabelą. Z pliku model dostaje całą treść, kontakt nie jest zgadywany ze „stopki”, a nazwa pliku zostaje.
     */
    public function test_inquiry_from_file_goes_to_model_whole_and_keeps_file_name(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return [
                    'subject' => 'Zapytanie ofertowe 12/2026',
                    'questions' => [],
                    'product_queries' => ['Rękawice MAPA 332'],
                    'line_items' => [[
                        'id' => 'item_1',
                        'quote' => '1 | Rękawice MAPA 332 rozm. 9 | 4 | para',
                        'qty' => '4',
                        'unit' => 'para',
                        'query' => 'Rękawice MAPA 332',
                        'size' => '9',
                    ]],
                    'cards' => [],
                ];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => self::LETTER,
            'tone' => 'handlowy',
            'source_channel' => 'file',
            'source_file_name' => 'zapytanie 12-2026.pdf',
        ])->assertCreated()
            ->assertJsonPath('source_channel', 'file')
            ->assertJsonPath('source_file_name', 'zapytanie 12-2026.pdf')
            ->assertJsonPath('contact', null);

        $this->assertStringContainsString('1 | Rękawice MAPA 332 rozm. 9 | 4 | para', (string) $seenByModel);
        $this->assertSame('1 | Rękawice MAPA 332 rozm. 9 | 4 | para', $res->json('items.0.quote'));
        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $this->assertSame(self::LETTER, $inquiry->source_body);
        $this->assertNull($inquiry->analysis['analyzed_body']);
    }

    /**
     * Mail wklejony nad plikiem zostaje mailem: jego stopka i cytat nie idą do modelu, a kontakt bierzemy z niej.
     * Pismo pod nagłówkiem pliku idzie w całości — z nagłówkiem firmowym i tabelą.
     */
    public function test_mail_pasted_above_file_is_still_trimmed_like_a_mail(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return ['subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => []];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);
        $mail = "Dzień dobry, w załączniku zapytanie.\n\nPozdrawiam\nPiotr Nowak\ntel. 600 100 200\n\n"
            ."W dniu 28.09.2026 o 14:55, Supon pisze:\n> stara oferta na kalosze";

        $res = $this->postJson('/api/inquiries', [
            'body' => $mail."\n\n".self::LETTER,
            'tone' => 'handlowy',
            'source_channel' => 'file',
            'source_file_name' => 'zapytanie 12-2026.pdf',
        ])->assertCreated();

        $this->assertStringContainsString('Dzień dobry, w załączniku zapytanie.', (string) $seenByModel);
        $this->assertStringNotContainsString('600 100 200', (string) $seenByModel);
        $this->assertStringNotContainsString('stara oferta na kalosze', (string) $seenByModel);
        $this->assertStringContainsString('tel. 17 111 22 33', (string) $seenByModel);
        $this->assertStringContainsString('1 | Rękawice MAPA 332 rozm. 9 | 4 | para', (string) $seenByModel);
        $this->assertStringContainsString('600 100 200', (string) json_encode($res->json('contact'), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('111 22 33', (string) json_encode($res->json('contact'), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Dodatek do Thunderbirda 1.29.0 dokleja tekst załącznika pod nagłówkiem pliku, a kanał zostaje „thunderbird”
     * (odpowiedź idzie na ten sam mail). Mail nad nagłówkiem jest cięty jak mail, pismo z załącznika idzie w całości.
     */
    public function test_thunderbird_mail_with_attachment_text_keeps_the_attachment_whole(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return ['subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => []];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);
        $mail = "Dzień dobry, w załączniku zapytanie.\n\nPozdrawiam\nPiotr Nowak\ntel. 600 100 200\n\n"
            ."W dniu 28.09.2026 o 14:55, Supon pisze:\n> stara oferta na kalosze";

        $res = $this->postJson('/api/inquiries', [
            'body' => $mail."\n\n".self::LETTER,
            'tone' => 'handlowy',
            'source_channel' => 'thunderbird',
            'source_message_id' => '<zapytanie-12@zuk.pl>',
            'source_file_name' => 'zapytanie 12-2026.pdf',
        ])->assertCreated()
            ->assertJsonPath('source_channel', 'thunderbird')
            ->assertJsonPath('source_file_name', 'zapytanie 12-2026.pdf');

        $this->assertStringNotContainsString('600 100 200', (string) $seenByModel);
        $this->assertStringNotContainsString('stara oferta na kalosze', (string) $seenByModel);
        $this->assertStringContainsString('tel. 17 111 22 33', (string) $seenByModel);
        $this->assertStringContainsString('1 | Rękawice MAPA 332 rozm. 9 | 4 | para', (string) $seenByModel);
        $this->assertStringContainsString('600 100 200', (string) json_encode($res->json('contact'), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('111 22 33', (string) json_encode($res->json('contact'), JSON_UNESCAPED_UNICODE));
        $this->assertSame('zapytanie-12@zuk.pl', ClientInquiry::query()->findOrFail($res->json('id'))->source_message_id);
    }

    /** Końce wierszy CRLF nie ukrywają nagłówka pliku — inaczej pismo z załącznika wpadłoby w cięcie stopki. */
    public function test_thunderbird_attachment_marker_is_found_with_crlf_line_endings(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return ['subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => []];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => str_replace("\n", "\r\n", "Dzień dobry, w załączniku zapytanie.\n\n".self::LETTER),
            'tone' => 'handlowy',
            'source_channel' => 'thunderbird',
            'source_file_name' => 'zapytanie 12-2026.pdf',
        ])->assertCreated()
            ->assertJsonPath('source_file_name', 'zapytanie 12-2026.pdf');

        $this->assertStringContainsString('1 | Rękawice MAPA 332 rozm. 9 | 4 | para', (string) $seenByModel);
    }

    /** Mail z Thunderbirda bez nagłówka pliku zostaje zwykłym mailem — stopka cięta, nazwa pliku niezapisana. */
    public function test_thunderbird_mail_without_attachment_text_is_trimmed_like_before(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return ['subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => []];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => "Proszę o ofertę na rękawice MAPA 332, 4 pary.\n\nPozdrawiam\nPiotr Nowak\ntel. 600 100 200",
            'tone' => 'handlowy',
            'source_channel' => 'thunderbird',
            'source_file_name' => 'zapytanie.pdf',
        ])->assertCreated()
            ->assertJsonPath('source_file_name', null);

        $this->assertStringContainsString('rękawice MAPA 332', (string) $seenByModel);
        $this->assertStringNotContainsString('600 100 200', (string) $seenByModel);
    }

    /**
     * 30.09.2026: opis odzieży ochronnej z formularzem ofertowym (32 tys. znaków) nie mieścił się w dawnym limicie
     * 20 000. Takie pismo przechodzi w całości — do modelu trafia także jego ostatni wiersz.
     */
    public function test_long_tender_description_is_accepted_and_reaches_model_whole(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $seenByModel = null;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$seenByModel): void {
            $mock->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$seenByModel): array {
                $seenByModel = (string) $messages[1]['content'];

                return ['subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => []];
            });
        });
        $this->emptySearch();
        Sanctum::actingAs($user);
        $rows = '';
        for ($i = 1; mb_strlen($rows) < 32000; $i++) {
            $rows .= $i."/ FARTUCH BIAŁY LABORATORYJNY: fartuch płócienny biały, materiał 100% bawełna, gramatura 210 g.\n";
        }
        $body = "=== Plik klienta: Odzież ochronna - opis - 2026.docx ===\n".$rows.'OSTATNI WIERSZ OPISU';

        $this->postJson('/api/inquiries', [
            'body' => $body,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_file_name' => 'Odzież ochronna - opis - 2026.docx',
        ])->assertCreated();

        $this->assertStringContainsString('OSTATNI WIERSZ OPISU', (string) $seenByModel);
    }

    /**
     * Zapytanie #86 (30.09.2026) od wysłania z dodatku do zapisanej analizy: model bierze Lp. za ilość i cytuje całe
     * wiersze tabel. Zapisane pozycje mają ilość z kolumny, frazę z nazwy wyrobu i żadnej pozycji z adresu w stopce.
     */
    public function test_tender_tables_from_attachments_keep_column_quantities_end_to_end(): void
    {
        $suit = '1 | Kombinezon rybacki z podnoskiem (nr pozycji magazynowej u Zamawiającego M056649) | Materiał: PVC. '
            .'Podeszwa antypoślizgowa SRC. Rozmiar: od 40 do 47. | PN-EN ISO 20345 S5 SRC oraz EN 343 | szt. | 275';
        $rup = '1 | RUP 502-U - Ewakuacyjne urządzenie podnosząco-opuszczające PROTEKT: DOR (kg) 140 MBS: 20 kN | 4';
        $body = "Dzień dobry, w załączeniu zapytania.\n\nJan Nowak\n35-232 Rzeszów, ul. Miłocińska 17\n\n"
            ."=== Plik klienta: opz(65).docx ===\nOPIS PRZEDMIOTU ZAMÓWIENIA\n"
            ."l.p. | Przedmiot zamówienia | Parametry użytkowe | Wymagania spełnienia norm | Jednostka miary | Razem\n"
            .$suit."\n\nWymagania do asortymentu:\n1. Obuwie musi posiadać oznaczenie CE.\n\n"
            ."=== Plik klienta: opz_10.08(4).docx ===\nLp. | Asortyment - opis parametrów | Ilość (szt.)\n".$rup;
        $user = User::factory()->withRole('handlowiec')->create();
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use ($suit, $rup): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Zapytania, przetarg',
                'questions' => [],
                'product_queries' => ['kombinezon rybacki z podnoskiem PVC', 'RUP 502-U urządzenie ewakuacyjne PROTEKT'],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => $suit, 'qty' => '1', 'unit' => null, 'query' => 'kombinezon rybacki z podnoskiem PVC', 'size' => null],
                    ['id' => 'item_2', 'quote' => $rup, 'qty' => '1', 'unit' => null, 'query' => 'RUP 502-U urządzenie ewakuacyjne PROTEKT', 'size' => null],
                ],
                'cards' => [],
            ]);
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => $body,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_file_name' => 'opz(65).docx, opz_10.08(4).docx',
        ])->assertCreated();

        $items = ClientInquiry::query()->findOrFail($res->json('id'))->analysis['line_items'];
        $this->assertSame(
            [['275', 'szt.', 'kombinezon rybacki z podnoskiem PVC'], ['4', 'szt.', 'RUP 502-U urządzenie ewakuacyjne PROTEKT']],
            array_map(static fn (array $item): array => [$item['qty'], $item['unit'], $item['search_query']], $items),
        );
    }

    public function test_body_over_the_limit_is_refused_with_a_reason(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson('/api/inquiries', [
            'body' => str_repeat('a', 60001),
            'tone' => 'handlowy',
        ])->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'Treść zapytania może mieć najwyżej 60 000 znaków — usuń fragmenty, które nie dotyczą zamawianych wyrobów.');
    }

    public function test_file_name_is_not_recorded_for_pasted_mail(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Oferta', 'questions' => [], 'product_queries' => [], 'line_items' => [], 'cards' => [],
            ]);
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawice MAPA 332.',
            'tone' => 'handlowy',
            'source_file_name' => 'zapytanie.pdf',
        ])->assertCreated()
            ->assertJsonPath('source_channel', 'web')
            ->assertJsonPath('source_file_name', null);
    }

    /**
     * Zapytanie #83 (29.09.2026): temat „mapa 332”, w treści tylko „rozmiar 9-4 pary” i „rozmiar 10-2 pary”.
     * Model wpisał wyrób z tematu do obu wierszy, a flagę „wyrób wzięty z tematu maila” dostawał tylko pierwszy.
     */
    public function test_every_row_without_product_name_filled_from_subject_is_flagged(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Cena - mapa 332',
                'questions' => ['Jaka cena?'],
                'product_queries' => ['mapa 332'],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'rozmiar 9-4 pary', 'qty' => '4', 'unit' => 'pary', 'query' => 'mapa 332', 'size' => '9'],
                    ['id' => 'item_2', 'quote' => 'rozmiar 10-2 pary', 'qty' => '2', 'unit' => 'pary', 'query' => 'mapa 332', 'size' => '10'],
                ],
                'cards' => [],
            ]);
        });
        $this->emptySearch();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'subject' => 'mapa 332',
            'body' => "Jaka cena?\n\nmamy na stanie:\nrozmiar 9-4 pary\nrozmiar 10-2 pary",
            'tone' => 'handlowy',
        ])->assertCreated();

        $this->assertContains('product_from_subject', $res->json('items.0.flags'));
        $this->assertContains('product_from_subject', $res->json('items.1.flags'));
        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $this->assertSame(['subject', 'subject'], array_column($inquiry->analysis['line_items'], 'query_source'));
    }

    private function emptySearch(): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                static fn (array $queries): array => array_map(static fn (string $q): array => ['query' => $q, 'products' => []], $queries),
            );
        });
    }
}
