<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Unit\InquiryMailTextTest;
use Tests\Unit\InquirySignatureTest;

/**
 * inquiries:recompute-contact — kontakt ze stopki zapisanych zapytań liczony od nowa (po 56e4e85 stopka kończy się
 * na cytacie). Domyślnie podgląd, zapis tylko z --apply.
 */
final class InquiriesRecomputeContactCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    /** Kontakt policzony dawniej z całej historii wątku — jak w zapytaniach #91 i #93. */
    private const STALE_CONTACT = [
        'person' => 'Anna Nowak',
        'company' => 'PHT Supon Sp. z o.o.',
        'emails' => ['anna.nowak@firma.pl', 'handel@supon.rzeszow.pl', 'izabela@supon.rzeszow.pl'],
        'phones' => ['600 100 200', '056709365', '017 860 28 53'],
        'address' => 'ul. Miłocińska 17, 35-232 Rzeszów',
        'website' => null,
        'raw' => "Pozdrawiam\nAnna Nowak\n…\nTemat: RE: Zapytanie ofertowe 056709365",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['inquiries.internal_email_domains' => ['supon.rzeszow.pl']]);
        $this->author = User::factory()->create();
    }

    public function test_preview_shows_the_change_and_saves_nothing(): void
    {
        $inquiry = $this->inquiry(InquirySignatureTest::replyOverOutlookQuoteMail(), 'anna.nowak@firma.pl', self::STALE_CONTACT);

        $this->assertSame(0, Artisan::call('inquiries:recompute-contact'));
        $output = Artisan::output();

        $this->assertStringContainsString('#'.$inquiry->id.' — podgląd', $output);
        $this->assertStringContainsString('handel@supon.rzeszow.pl', $output);
        $this->assertStringContainsString('056709365', $output);
        $this->assertStringContainsString('Nic nie zapisano', $output);
        $this->assertSame(self::STALE_CONTACT, $inquiry->fresh()->contact);
    }

    public function test_apply_saves_only_the_listed_inquiries_without_touching_updated_at(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $fixed = $this->inquiry(InquirySignatureTest::replyOverOutlookQuoteMail(), 'anna.nowak@firma.pl', self::STALE_CONTACT);
        // ponaglenie #93 bez rozpoznanego nadawcy wątku — nad cytatem nie ma stopki, więc kontaktu nie ma
        $followUp = $this->inquiry(InquiryMailTextTest::clientFollowUpMail(), null, self::STALE_CONTACT, 'Zapytanie ofertowe 056709365');
        $other = $this->inquiry(InquirySignatureTest::replyOverOutlookQuoteMail(), 'anna.nowak@firma.pl', self::STALE_CONTACT);
        $updatedAt = (string) $fixed->fresh()->updated_at;
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));

        $this->assertSame(0, Artisan::call('inquiries:recompute-contact', ['ids' => [$fixed->id, $followUp->id], '--apply' => true]));
        $this->assertStringContainsString('#'.$fixed->id.' — zapisane', Artisan::output());

        $contact = $fixed->fresh()->contact;
        $this->assertSame('Anna Nowak', $contact['person']);
        $this->assertNull($contact['company']);
        $this->assertSame(['anna.nowak@firma.pl'], $contact['emails']);
        $this->assertSame(['600 100 200'], $contact['phones']);
        $this->assertStringNotContainsString('Temat:', $contact['raw']);
        $this->assertNull($followUp->fresh()->contact);
        // poprawka danych, nie praca handlowca
        $this->assertSame($updatedAt, (string) $fixed->fresh()->updated_at);
        // zapytanie spoza listy numerów zostaje jak było
        $this->assertSame(self::STALE_CONTACT, $other->fresh()->contact);
    }

    /** Mail z naszej skrzynki: stopka to podpis pracownika — takiego kontaktu nie zapisujemy w miejsce starego. */
    public function test_new_contact_with_our_address_is_not_saved(): void
    {
        $inquiry = $this->inquiry(InquiryMailTextTest::cederrothMail(), 'lzielinski@supon.rzeszow.pl', null);

        $this->assertSame(0, Artisan::call('inquiries:recompute-contact', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('#'.$inquiry->id.' — pominięte', $output);
        $this->assertStringContainsString('lzielinski@supon.rzeszow.pl', $output);
        $this->assertNull($inquiry->fresh()->contact);
    }

    public function test_up_to_date_contact_is_not_listed_and_unknown_ids_are_reported(): void
    {
        $current = $this->inquiry(InquirySignatureTest::replyOverOutlookQuoteMail(), 'anna.nowak@firma.pl', null);
        $this->assertSame(0, Artisan::call('inquiries:recompute-contact', ['ids' => [$current->id], '--apply' => true]));
        $this->assertNotNull($current->fresh()->contact);

        $this->assertSame(0, Artisan::call('inquiries:recompute-contact', ['ids' => [$current->id, 999]]));
        $output = Artisan::output();
        $this->assertStringNotContainsString('#'.$current->id.' —', $output);
        $this->assertStringContainsString('#999: nie ma takiego zapytania', $output);
        $this->assertStringContainsString('Kontakt do zmiany: 0', $output);

        $this->assertSame(2, Artisan::call('inquiries:recompute-contact', ['ids' => ['91a']]));
    }

    /**
     * @param  array<string, mixed>|null  $contact
     */
    private function inquiry(string $body, ?string $from, ?array $contact, string $subject = 'RE: Zapytanie ofertowe 056709365'): ClientInquiry
    {
        return ClientInquiry::query()->create([
            'user_id' => $this->author->id,
            'source_channel' => 'thunderbird',
            'source_subject' => $subject,
            'source_from_email' => $from,
            'source_body' => $body,
            'contact' => $contact,
        ]);
    }
}
