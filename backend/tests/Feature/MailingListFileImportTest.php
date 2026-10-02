<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\MailingList;
use App\Models\MailingListContact;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import adresów do grupy odbiorców z pliku CSV/Excel z kolumnami przypisanymi przez użytkownika. */
final class MailingListFileImportTest extends TestCase
{
    use RefreshDatabase;

    /** Jak eksport klientów ze sklepu: średnik, cudzysłowy, zwrot grzecznościowy w „Nazwa kontaktu”. */
    private const SHOP_CSV = <<<'CSV'
ID;"Nazwa kontaktu";Imię;Nazwisko;"Adres e-mail";Firma;Sprzedaż;Newsletter
9829;;Agnieszka;Czapla;agnieszkaczapla@subor.pl;"SUBOR SPÓŁKA Z O.O.";323,00 zł;1
9803;Pan;Przepompownia;Szamotuły;Jakub.Michalak@ZGK.pl;"""ZGK W SZAMOTUŁACH"" SP. Z O.O.";541,20 zł;1
9791;;Bartosz;Artiomow;bartosz@onet.pl;;;0
9790;;Bez;Adresu;;;;1
9789;;Zły;Adres;to-nie-adres;;;1
9788;Pani;Ewa;Kolwicz;agnieszkaczapla@subor.pl;;;1
9787;;Jan;Nowak;"jan@a.pl, biuro@a.pl";"Firma A";;tak
9786;;Stary;Kontakt;stary@firma.pl;"Nowa Firma";;1
9785;;Wypisany;Klient;wypisany@firma.pl;;;1
CSV;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_preview_suggests_columns_from_headers(): void
    {
        [$user, $list] = $this->userWithList();
        Sanctum::actingAs($user);

        $this->postFile("/api/mailing-lists/{$list->id}/import-file/preview", ['file' => $this->csv(self::SHOP_CSV)])
            ->assertOk()
            ->assertJsonPath('file_name', 'klienci.csv')
            ->assertJsonPath('sheets', ['Worksheet'])
            ->assertJsonPath('total_rows', 10)
            ->assertJsonPath('has_header', true)
            ->assertJsonPath('column_count', 8)
            // „Nazwa kontaktu” (Pan/Pani) bez propozycji — to nie nazwisko
            ->assertJsonPath('suggested', [null, null, 'first_name', 'last_name', 'email', 'company', null, 'consent'])
            ->assertJsonPath('rows.0.2', 'Imię')
            ->assertJsonPath('rows.2.5', '"ZGK W SZAMOTUŁACH" SP. Z O.O.')
            ->assertJsonCount(8, 'rows');
    }

    public function test_import_maps_columns_and_reports_skipped_rows(): void
    {
        [$user, $list] = $this->userWithList();
        $existing = Contact::query()->create(['email' => 'stary@firma.pl', 'name' => 'Stare Imię', 'company' => null]);
        EmailSuppression::query()->create(['email' => 'wypisany@firma.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);
        Sanctum::actingAs($user);

        $this->postFile("/api/mailing-lists/{$list->id}/import-file", [
            'file' => $this->csv(self::SHOP_CSV),
            'has_header' => '1',
            'mapping' => ['email' => '4', 'first_name' => '2', 'last_name' => '3', 'company' => '5', 'consent' => '7'],
            'basis' => 'consent',
            'basis_note' => 'plik: klienci.csv',
        ])->assertOk()->assertExactJson([
            'added' => 6,
            'already' => 0,
            'duplicates' => 1,
            'empty' => 1,
            'no_consent' => 1,
            'invalid' => ['wiersz 6: to-nie-adres'],
            'invalid_count' => 1,
            'suppressed' => 1,
        ]);

        $first = Contact::query()->where('email', 'agnieszkaczapla@subor.pl')->firstOrFail();
        // pierwsze wystąpienie adresu wygrywa
        $this->assertSame(['Agnieszka Czapla', 'SUBOR SPÓŁKA Z O.O.'], [$first->name, $first->company]);
        $this->assertSame('"ZGK W SZAMOTUŁACH" SP. Z O.O.', Contact::query()->where('email', 'jakub.michalak@zgk.pl')->value('company'));
        // dwa adresy w komórce = dwa kontakty z tą samą osobą
        $this->assertSame(['Jan Nowak', 'Jan Nowak'], Contact::query()->whereIn('email', ['jan@a.pl', 'biuro@a.pl'])->pluck('name')->all());
        $this->assertNull(Contact::query()->where('email', 'bartosz@onet.pl')->first());
        // istniejącemu kontaktowi tylko puste pola
        $existing->refresh();
        $this->assertSame(['Stare Imię', 'Nowa Firma'], [$existing->name, $existing->company]);

        $pivot = MailingListContact::query()->where('mailing_list_id', $list->id)->where('contact_id', $first->id)->firstOrFail();
        $this->assertSame(['consent', 'plik: klienci.csv', $user->id], [$pivot->basis, $pivot->basis_note, (int) $pivot->added_by]);

        // drugi import tego samego pliku: wszystko już jest
        $this->postFile("/api/mailing-lists/{$list->id}/import-file", [
            'file' => $this->csv(self::SHOP_CSV),
            'has_header' => '1',
            'mapping' => ['email' => '4'],
            'basis' => 'customer',
        ])->assertOk()->assertJsonPath('added', 1)->assertJsonPath('already', 6);
        // bez kolumny zgody bartosz@ wchodzi (podstawa wybrana dla całego importu)
        $this->assertSame(7, $list->contacts()->count());
    }

    public function test_full_name_column_wins_over_first_and_last_name(): void
    {
        [$user, $list] = $this->userWithList();
        Sanctum::actingAs($user);
        $csv = "Osoba;Imię;Nazwisko;Mail\nJan Kowalski;X;Y;jan@k.pl\n;Anna;Nowak;anna@n.pl\n";

        $this->postFile("/api/mailing-lists/{$list->id}/import-file", [
            'file' => $this->csv($csv),
            'has_header' => '1',
            'mapping' => ['name' => '0', 'first_name' => '1', 'last_name' => '2', 'email' => '3'],
            'basis' => 'customer',
        ])->assertOk()->assertJsonPath('added', 2);

        $this->assertSame('Jan Kowalski', Contact::query()->where('email', 'jan@k.pl')->value('name'));
        $this->assertSame('Anna Nowak', Contact::query()->where('email', 'anna@n.pl')->value('name'));
    }

    public function test_windows_1250_csv_without_header(): void
    {
        [$user, $list] = $this->userWithList();
        Sanctum::actingAs($user);
        $csv = (string) iconv('UTF-8', 'CP1250', "Łukasz Żółć;lukasz@zolc.pl;Spółka Źródło\nGrażyna Ślęk;grazyna@slek.pl;\n");

        $this->postFile("/api/mailing-lists/{$list->id}/import-file/preview", ['file' => $this->csv($csv)])
            ->assertOk()
            ->assertJsonPath('has_header', false)
            ->assertJsonPath('suggested', [null, 'email', null])
            ->assertJsonPath('rows.0.0', 'Łukasz Żółć');

        $this->postFile("/api/mailing-lists/{$list->id}/import-file", [
            'file' => $this->csv($csv),
            'has_header' => '0',
            'mapping' => ['name' => '0', 'email' => '1', 'company' => '2'],
            'basis' => 'customer',
        ])->assertOk()->assertJsonPath('added', 2);

        $lukasz = Contact::query()->where('email', 'lukasz@zolc.pl')->firstOrFail();
        $this->assertSame(['Łukasz Żółć', 'Spółka Źródło'], [$lukasz->name, $lukasz->company]);
    }

    public function test_xlsx_with_second_sheet(): void
    {
        [$user, $list] = $this->userWithList();
        Sanctum::actingAs($user);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Opis')->setCellValue('A1', 'Lista klientów z targów');
        $sheet = $book->createSheet()->setTitle('Adresy');
        $sheet->fromArray([['Firma', 'E-mail'], ['Budowa SA', 'biuro@budowa.pl'], ['', ''], ['Drewno', 'drewno@x.pl']]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($path);
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('targi.xlsx', (string) file_get_contents($path));

        $this->postFile("/api/mailing-lists/{$list->id}/import-file/preview", ['file' => $file(), 'sheet' => '1'])
            ->assertOk()
            ->assertJsonPath('sheets', ['Opis', 'Adresy'])
            ->assertJsonPath('sheet', 1)
            ->assertJsonPath('total_rows', 3)
            ->assertJsonPath('suggested', ['company', 'email']);
        $this->postFile("/api/mailing-lists/{$list->id}/import-file/preview", ['file' => $file(), 'sheet' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postFile("/api/mailing-lists/{$list->id}/import-file", [
            'file' => $file(),
            'sheet' => '1',
            'has_header' => '1',
            'mapping' => ['company' => '0', 'email' => '1'],
            'basis' => 'customer',
        ])->assertOk()->assertJsonPath('added', 2);
        $this->assertSame('Budowa SA', Contact::query()->where('email', 'biuro@budowa.pl')->value('company'));
        @unlink($path);
    }

    public function test_validation_and_access(): void
    {
        [$user, $list] = $this->userWithList();
        $foreign = MailingList::query()->create(['user_id' => User::factory()->withRole('handlowiec')->create()->id, 'name' => 'Cudza']);
        Sanctum::actingAs($user);
        $url = "/api/mailing-lists/{$list->id}/import-file";
        $base = ['file' => $this->csv(self::SHOP_CSV), 'has_header' => '1', 'basis' => 'customer'];

        $this->postFile("/api/mailing-lists/{$foreign->id}/import-file/preview", ['file' => $this->csv(self::SHOP_CSV)])->assertNotFound();
        $this->postFile($url, [...$base, 'mapping' => ['first_name' => '2']])->assertUnprocessable()->assertJsonValidationErrors('mapping.email');
        $this->postFile($url, [...$base, 'mapping' => ['email' => '4', 'phone' => '1']])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        $this->postFile($url, [...$base, 'mapping' => ['email' => '4', 'name' => '4']])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        $this->postFile($url, [...$base, 'mapping' => ['email' => '40']])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        $this->postFile($url, [...$base, 'file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF'), 'mapping' => ['email' => '0']])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postFile($url, [...$base, 'file' => $this->csv(''), 'mapping' => ['email' => '0']])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $tooMany = "mail\n".implode("\n", array_map(static fn (int $i): string => "a{$i}@x.pl", range(1, 5001)));
        $this->postFile($url, [...$base, 'file' => $this->csv($tooMany), 'mapping' => ['email' => '0']])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        // arkusz większy niż dwa limity odrzucony przed wczytaniem (także w podglądzie)
        $huge = "mail\n".implode("\n", array_map(static fn (int $i): string => "a{$i}@x.pl", range(1, 10001)));
        $this->postFile("/api/mailing-lists/{$list->id}/import-file/preview", ['file' => $this->csv($huge)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, Contact::query()->count());
    }

    /** @return array{0: User, 1: MailingList} */
    private function userWithList(): array
    {
        $user = User::factory()->withRole('handlowiec')->create();

        return [$user, MailingList::query()->create(['user_id' => $user->id, 'name' => 'Ze sklepu'])];
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('klienci.csv', $content);
    }

    /** @param  array<string, mixed>  $data */
    private function postFile(string $url, array $data): TestResponse
    {
        return $this->post($url, $data, ['Accept' => 'application/json']);
    }
}
