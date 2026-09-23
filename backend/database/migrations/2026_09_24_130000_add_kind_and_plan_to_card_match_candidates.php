<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Łączenie kart, krok 5: rodzaj propozycji i plan „pozycja → karta”. Obok dotychczasowego łączenia z jedną kartą
 * producenta (merge) propozycja może wskazywać kilka kart: łączenie rozmiarów (size_merge — producent ma osobną kartę
 * na każdy rozmiar w tej samej cenie) albo rozdzielanie (split — dystrybutor trzyma na jednej karcie kolory albo
 * rozmiary w różnych cenach, które producent ma osobno).
 *
 * UNIQUE przechodzi z (source, target) na (source, targets_key): targets_key to posortowane id kart docelowych —
 * rodzaj może się zmienić między odświeżeniami, a wiersz (i odrzucenie) zostaje ten sam dla tego samego zestawu kart.
 * Istniejące wiersze: karta docelowa → jej id; konflikt „kilka kart” → posortowane conflict_product_ids; wiersz bez
 * karty (karta usunięta, klucz obcy wyzerował target) → „gone:{id}”. Rodzaj istniejących wierszy zostaje merge.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        // duplikaty sprawdzone przed zmianą schematu — bez cichych zmian i bez połowicznie wykonanej migracji
        $keys = [];
        foreach (DB::table('card_match_candidates')->orderBy('id')
            ->get(['id', 'source_product_id', 'target_product_id', 'conflict_product_ids']) as $row) {
            $keys[(int) $row->source_product_id.'|'.self::targetsKey($row)][] = (int) $row->id;
        }
        $duplicates = array_filter($keys, static fn (array $ids): bool => count($ids) > 1);
        if ($duplicates !== []) {
            throw new RuntimeException('card_match_candidates: wiersze o tej samej karcie źródła i tym samym zestawie kart docelowych (id: '
                .implode('; ', array_map(static fn (array $ids): string => implode(', ', $ids), $duplicates))
                .') — rozstrzygnij ręcznie przed migracją.');
        }

        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->string('kind', 20)->default('merge')->after('status')->index();
            $table->json('plan')->nullable()->after('conflict_product_ids');
            $table->string('targets_key', 255)->default('')->after('plan');
            $table->char('plan_hash', 40)->nullable()->after('targets_key');
        });

        DB::table('card_match_candidates')->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('card_match_candidates')->where('id', $row->id)->update(['targets_key' => self::targetsKey($row)]);
                }
            });

        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->dropUnique('card_match_candidates_pair_unique');
            $table->unique(['source_product_id', 'targets_key'], 'card_match_candidates_targets_unique');
        });
    }

    public function down(): void
    {
        // propozycje nowych rodzajów do decyzji nie mają odpowiednika w starym schemacie; decyzje (odrzucone,
        // połączone) zostają jako ślad
        DB::table('card_match_candidates')
            ->where('kind', '!=', 'merge')
            ->whereIn('status', ['pending', 'conflict'])
            ->delete();

        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->dropUnique('card_match_candidates_targets_unique');
            $table->unique(['source_product_id', 'target_product_id'], 'card_match_candidates_pair_unique');
        });
        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
        });
        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'plan', 'targets_key', 'plan_hash']);
        });
    }

    /** Ta sama reguła co CardMatchCandidate::targetsKeyFor — tu dosłownie, migracja nie zależy od kodu aplikacji. */
    private static function targetsKey(object $row): string
    {
        if ($row->target_product_id !== null) {
            return (string) (int) $row->target_product_id;
        }
        $ids = is_string($row->conflict_product_ids) ? json_decode($row->conflict_product_ids, true) : null;
        if (is_array($ids) && $ids !== []) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);
            $key = implode(',', $ids);

            return strlen($key) > 255 ? 'sha1:'.sha1($key) : $key;
        }

        return 'gone:'.(int) $row->id;
    }
};
