<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\Tenders\TenderResultStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TenderResultStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Macierz z decyzji A5 kontraktu: liczą się najpierw tylko wygrane i przegrane.
     *
     * @return array<string, array{list<?string>, ?string}>
     */
    public static function matrix(): array
    {
        return [
            'brak części' => [[], null],
            'jedna część bez wyniku' => [[null], null],
            'jedna wygrana' => [['won'], 'won'],
            'wszystkie wygrane' => [['won', 'won'], 'won'],
            'wygrana i unieważniona' => [['won', 'cancelled'], 'won'],
            'wygrana i bez oferty' => [['won', 'not_submitted'], 'won'],
            'wygrana i część bez wyniku' => [['won', null], 'won'],
            'wygrana i przegrana' => [['won', 'lost'], 'partial'],
            'wygrana, przegrana, unieważniona' => [['won', 'lost', 'cancelled'], 'partial'],
            'jedna przegrana' => [['lost'], 'lost'],
            'same przegrane' => [['lost', 'lost'], 'lost'],
            'przegrana i unieważniona' => [['lost', 'cancelled'], 'lost'],
            'przegrana i bez oferty' => [['lost', 'not_submitted'], 'lost'],
            'przegrana i część bez wyniku' => [['lost', null], 'lost'],
            'unieważniona' => [['cancelled'], 'cancelled'],
            'wszystkie unieważnione' => [['cancelled', 'cancelled'], 'cancelled'],
            'unieważniona i część bez wyniku' => [['cancelled', null], null],
            'bez oferty' => [['not_submitted'], 'not_submitted'],
            'bez oferty i unieważniona' => [['not_submitted', 'cancelled'], 'not_submitted'],
            'bez oferty i część bez wyniku' => [['not_submitted', null], null],
            'nieznany wynik się nie liczy' => [['cos_innego'], null],
        ];
    }

    /**
     * @param  list<?string>  $outcomes
     */
    #[DataProvider('matrix')]
    public function test_matrix(array $outcomes, ?string $expected): void
    {
        $this->assertSame($expected, TenderResultStatus::compute($outcomes));
        $this->assertSame($expected, TenderResultStatus::compute(array_map(
            static fn (?string $outcome): array => ['outcome' => $outcome],
            $outcomes,
        )));
        $this->assertSame($expected, TenderResultStatus::compute(array_map(
            static fn (?string $outcome): TenderLot => new TenderLot(['outcome' => $outcome]),
            $outcomes,
        )));
    }

    public function test_recompute_saves_result_status_from_lots(): void
    {
        $tender = $this->tender();
        $this->assertNull(TenderResultStatus::recompute($tender));

        TenderLot::query()->create(['tender_id' => $tender->id, 'lot_no' => 1, 'outcome' => 'won']);
        TenderLot::query()->create(['tender_id' => $tender->id, 'lot_no' => 2, 'outcome' => 'lost']);
        $this->assertSame('partial', TenderResultStatus::recompute($tender));
        $this->assertSame('partial', $tender->fresh()->result_status);

        TenderLot::query()->where('tender_id', $tender->id)->update(['outcome' => 'cancelled']);
        $this->assertSame('cancelled', TenderResultStatus::recompute($tender));
        $this->assertSame('cancelled', $tender->fresh()->result_status);

        TenderLot::query()->where('tender_id', $tender->id)->update(['outcome' => null]);
        $this->assertNull(TenderResultStatus::recompute($tender));
        $this->assertNull($tender->fresh()->result_status);
    }

    private function tender(): Tender
    {
        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Zamawiający testowy']);

        return Tender::query()->create([
            'number' => 'PRZ/2026/9001',
            'title' => 'Rękawice',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'draft',
            'ai_percent' => 0,
        ]);
    }
}
