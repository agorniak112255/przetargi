<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RequirementUnderstanding;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Przegląd zapisanych zrozumień: lista pokazuje tylko podejrzane zapisy bieżącej wersji instrukcji,
 * a kasowanie działa wyłącznie z --apply i wyłącznie na wskazanych numerach.
 */
final class SearchUnderstandingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_flagged_understanding_and_hides_clean_one(): void
    {
        $this->understanding('spodnie do pasa z polipropylenu', 'spodnie robocze', ['spodnie', 'robocze']);
        $this->understanding('rękawice spawalnicze trudnopalne', 'rękawice spawalnicze trudnopalne', ['rękawice', 'spawalnicze', 'trudnopalne']);
        $this->understanding('buty robocze zimowe', 'kalosze', ['kalosze'], 'understand-stara-wersja');

        $this->assertSame(0, Artisan::call('search:understandings'));
        $output = Artisan::output();
        // wiersz tabeli: id | wymaganie | needed | zgubione | dopisane | data
        $this->assertMatchesRegularExpression(
            '/\|\s*spodnie do pasa z polipropylenu\s*\|\s*spodnie robocze\s*\|\s*polipropylenu\s*\|\s*robocze\s*\|/u',
            $output,
        );
        $this->assertStringNotContainsString('rękawice spawalnicze trudnopalne', $output);
        $this->assertStringNotContainsString('buty robocze zimowe', $output);
        $this->assertStringContainsString('Sprawdzone zapisy: 2, podejrzane: 1.', $output);

        $this->artisan('search:understandings', ['--all' => true])
            ->expectsOutputToContain('spodnie do pasa z polipropylenu')
            ->expectsOutputToContain('rękawice spawalnicze trudnopalne')
            ->doesntExpectOutputToContain('buty robocze zimowe')
            ->assertSuccessful();
    }

    public function test_forget_without_apply_deletes_nothing(): void
    {
        $wrong = $this->understanding('spodnie do pasa z polipropylenu', 'spodnie robocze', ['spodnie', 'robocze']);

        $this->artisan('search:understandings', ['--forget' => (string) $wrong->id])
            ->expectsOutputToContain('nic nie zostało skasowane')
            ->expectsOutputToContain('--forget='.$wrong->id.' --apply')
            ->assertSuccessful();

        $this->assertDatabaseCount('requirement_understandings', 1);
    }

    public function test_forget_with_apply_deletes_only_listed_ids(): void
    {
        $first = $this->understanding('spodnie do pasa z polipropylenu', 'spodnie robocze', ['spodnie', 'robocze']);
        $kept = $this->understanding('kurtka ocieplana z kapturem', 'kurtka robocza', ['kurtka', 'robocza']);
        $second = $this->understanding('buty robocze zimowe', 'kalosze', ['kalosze'], 'understand-stara-wersja');
        $unknown = $second->id + 100;

        $this->artisan('search:understandings', ['--forget' => "{$first->id}, {$second->id},{$unknown}", '--apply' => true])
            ->expectsOutputToContain('Nie znaleziono zapisów: '.$unknown.'.')
            ->expectsOutputToContain('Skasowano zapisy: 2.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('requirement_understandings', ['id' => $first->id]);
        $this->assertDatabaseMissing('requirement_understandings', ['id' => $second->id]);
        $this->assertDatabaseHas('requirement_understandings', ['id' => $kept->id]);
    }

    public function test_malformed_forget_list_deletes_nothing(): void
    {
        $row = $this->understanding('spodnie do pasa z polipropylenu', 'spodnie robocze', ['spodnie', 'robocze']);

        $this->artisan('search:understandings', ['--forget' => "{$row->id},abc", '--apply' => true])
            ->expectsOutputToContain('Niepoprawny numer zapisu')
            ->assertFailed();

        $this->assertDatabaseHas('requirement_understandings', ['id' => $row->id]);
    }

    /** @param  list<string>  $steps */
    private function understanding(string $requirement, string $needed, array $steps, ?string $version = null): RequirementUnderstanding
    {
        return RequirementUnderstanding::query()->create([
            'requirement_hash' => hash('sha256', $requirement),
            'prompt_version' => $version ?? ProductAiSearchService::UNDERSTAND_PROMPT_VERSION,
            'requirement' => $requirement,
            'answer' => [
                'needed' => $needed,
                'search_steps' => $steps,
                'manufacturer' => null,
                'model_name' => null,
                'size_note' => null,
                'search_phrases' => [$needed],
                'constraints' => [],
            ],
        ]);
    }
}
