<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Competitor;
use App\Services\Tenders\CompetitorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompetitorRegistryTest extends TestCase
{
    use RefreshDatabase;

    private CompetitorRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new CompetitorRegistry;
    }

    public function test_nothing_to_identify_gives_null(): void
    {
        $this->assertNull($this->registry->resolve(null, null));
        $this->assertNull($this->registry->resolve('  ', 'brak'));
        $this->assertSame(0, Competitor::query()->count());
    }

    public function test_creates_company_with_name_as_in_source_and_valid_nip(): void
    {
        $company = $this->registry->resolve('Przedsiębiorstwo Wielobranżowe "MADA" Kosiec i Wspólnicy Sp. k.', 'NIP: 118-16-25-269');

        $this->assertNotNull($company);
        $this->assertSame('Przedsiębiorstwo Wielobranżowe "MADA" Kosiec i Wspólnicy Sp. k.', $company->name);
        $this->assertSame('przedsiebiorstwo wielobranzowe mada kosiec i wspolnicy', $company->name_key);
        $this->assertSame('1181625269', $company->nip);
    }

    public function test_same_nip_under_other_name_is_the_same_company(): void
    {
        $first = $this->registry->resolve('MADA Kosiec i Wspólnicy Sp. k.', '1181625269');
        $second = $this->registry->resolve('P.W. MADA', 'NIP 118 16 25 269');

        $this->assertSame($first?->id, $second?->id);
        // nazwa ze źródła się nie zmienia
        $this->assertSame('MADA Kosiec i Wspólnicy Sp. k.', $second?->name);
        $this->assertSame(1, Competitor::query()->count());
    }

    public function test_same_name_in_other_spelling_is_the_same_company(): void
    {
        $first = $this->registry->resolve('ROZWIĄZANIA BIUROWE ROMAN KRZYŻANEK', null);
        $second = $this->registry->resolve('Rozwiązania Biurowe Roman Krzyżanek', '');

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, Competitor::query()->count());
    }

    public function test_company_found_by_name_gets_nip_when_it_appears(): void
    {
        $byName = $this->registry->resolve('Rękawice Nowak s.c.', null);
        $this->assertNull($byName?->nip);

        $withNip = $this->registry->resolve('Rękawice Nowak', '972-024-99-33');

        $this->assertSame($byName?->id, $withNip?->id);
        $this->assertSame('9720249933', $withNip?->fresh()?->nip);
        $this->assertSame(1, Competitor::query()->count());
    }

    public function test_same_name_with_different_nip_is_another_company(): void
    {
        $first = $this->registry->resolve('Ochrona Sp. z o.o.', '1181625269');
        $second = $this->registry->resolve('Ochrona Sp. z o.o.', '9720249933');

        $this->assertNotSame($first?->id, $second?->id);
        $this->assertSame(2, Competitor::query()->count());
    }

    public function test_invalid_nip_is_not_stored_and_name_decides(): void
    {
        $company = $this->registry->resolve('Firma z błędnym NIP', '1181625260');

        $this->assertNotNull($company);
        $this->assertNull($company->nip);
        $this->assertSame($company->id, $this->registry->resolve('FIRMA Z BŁĘDNYM NIP', null)?->id);
    }

    public function test_nip_without_name_is_described_by_the_number_from_source(): void
    {
        $company = $this->registry->resolve(null, '1181625269');

        $this->assertSame('NIP 1181625269', $company?->name);
        $this->assertSame('', $company?->name_key);

        // gdy pojawi się nazwa, firma ją dostaje
        $named = $this->registry->resolve('MADA Sp. k.', '1181625269');
        $this->assertSame($company?->id, $named?->id);
        $this->assertSame('MADA Sp. k.', $named?->name);
        $this->assertSame('mada', $named?->name_key);
    }
}
