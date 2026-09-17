<?php

declare(strict_types=1);

use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instrukcja rodziny „obuwie” dostała stały zestaw cech doboru (m.in. typ zapięcia:
 * sznurówki / rzepy / BOA) oraz wymóg nazwania braku danych brakiem. Wiersze w bazie
 * zakłada `EnrichmentDescriptionTemplateService::ensureSeeded()`, które dopisuje wyłącznie
 * brakujące klucze i nigdy nie nadpisuje istniejącej treści — bez tej migracji działająca
 * instalacja zostałaby ze starym szablonem.
 *
 * Podmieniamy tylko wiersz, w którym stoi DOKŁADNIE stara treść domyślna. Instrukcję
 * zmienioną ręcznie w panelu (/admin/szablony-opisow) zostawiamy nietkniętą — to praca
 * użytkownika, nie dane pochodne. `down()` cofa zmianę na tych samych warunkach.
 */
return new class extends Migration
{
    private const KATEGORIA = 'obuwie';

    /** Treść domyślna sprzed tej migracji — zamrożona, bo `defaults()` już jej nie zwraca. */
    private const PREVIOUS_DEFAULT = <<<'TXT'
To obuwie ochronne / robocze. Zbierz pełną kartę katalogową — nie opisuj rękawic ani odzieży.

W description ujmij: przeznaczenie, cholewka, podnosek, wkładka, podeszwa, właściwości (antystatyczność, SRC, wodoodporność, izolacja), klasa (S1–S5 / O1–O5 / S1P), zastosowania.
W specs i attributes: kod/SKU, materiał cholewki, podnosek (kompozyt / stal), wkładka, podeszwa, EN ISO 20345 / 20347, klasa_ochrony (np. S3), rozmiary EU ze źródeł.
rozmiar: wyłącznie numery EU 36–50 ze źródeł; nigdy 1–5XL ani 6–12 z rękawic.
TXT;

    public function up(): void
    {
        $this->replace(self::PREVIOUS_DEFAULT, EnrichmentDescriptionTemplates::defaultInstructions(self::KATEGORIA));
    }

    public function down(): void
    {
        $this->replace(EnrichmentDescriptionTemplates::defaultInstructions(self::KATEGORIA), self::PREVIOUS_DEFAULT);
    }

    /** Podmienia instrukcję rodziny „obuwie” tylko wtedy, gdy stoi w niej dokładnie $from. */
    private function replace(string $from, string $to): void
    {
        if (! Schema::hasTable('enrichment_description_templates')) {
            return;
        }

        $row = DB::table('enrichment_description_templates')
            ->select(['id', 'instructions'])
            ->where('kategoria_bhp', self::KATEGORIA)
            ->first();
        if ($row === null || trim((string) $row->instructions) !== trim($from)) {
            return;
        }

        DB::table('enrichment_description_templates')
            ->where('id', $row->id)
            ->update([
                'instructions' => $to,
                'updated_at' => now(),
            ]);
    }
};
