<?php

declare(strict_types=1);

use App\Support\SupplierSpecialPrice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marża bliźniacza przetargu (decyzja właściciela 30.09.2026): użytkownik bez prices.supplier_special.view widzi
 * marżę liczoną od ceny standardowej karty z ceną specjalną B2B, uprawniony — prawdziwą. Obie marże zapisuje
 * TenderPricingService; tu tylko początkowa kopia.
 *
 * Pozycja, której żadna karta (główna ani drugi produkt) nie ma slotu B2B z ceną specjalną, ma obie marże równe —
 * kopiujemy. Pozycja z taką kartą i przetarg z taką pozycją zostają NULL („—” w panelu), aż policzy je
 * tenders:backfill-standard-margins albo najbliższe przeliczenie pozycji — kopia zdradziłaby prawdziwą marżę.
 * Tak samo pozycja z marżą bez żadnej karty (skasowanej) i jej przetarg — prawdziwej marży nie kopiujemy nigdy.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->decimal('margin_percent_standard', 8, 2)->nullable()->after('margin_percent');
        });
        Schema::table('tenders', function (Blueprint $table): void {
            $table->decimal('margin_percent_standard', 8, 2)->nullable()->after('margin_percent');
        });

        DB::table('tender_items')->update(['margin_percent_standard' => DB::raw('margin_percent')]);
        DB::table('tenders')->update(['margin_percent_standard' => DB::raw('margin_percent')]);

        // Pozycja z marżą, a bez kart (karta skasowana — klucz obcy wyzerował id): marża mogła powstać z ceny
        // specjalnej, której dziś nie da się sprawdzić — nie kopiujemy; przetarg liczy polecenie uzupełniające.
        $cardless = static function ($query): void {
            $query->whereNull('main_product_id')->whereNull('companion_product_id')->whereNotNull('margin_percent');
        };
        $cardlessTenders = DB::table('tender_items')->where($cardless)->distinct()->pluck('tender_id')->all();
        DB::table('tender_items')->where($cardless)->update(['margin_percent_standard' => null]);
        foreach (array_chunk($cardlessTenders, self::CHUNK) as $tenders) {
            DB::table('tenders')->whereIn('id', $tenders)->update(['margin_percent_standard' => null]);
        }

        foreach (array_chunk($this->affectedProductIds(), self::CHUNK) as $chunk) {
            $items = static function ($query) use ($chunk): void {
                $query->whereIn('main_product_id', $chunk)->orWhereIn('companion_product_id', $chunk);
            };
            $tenderIds = DB::table('tender_items')->where($items)->distinct()->pluck('tender_id')->all();
            DB::table('tender_items')->where($items)->update(['margin_percent_standard' => null]);
            foreach (array_chunk($tenderIds, self::CHUNK) as $tenders) {
                DB::table('tenders')->whereIn('id', $tenders)->update(['margin_percent_standard' => null]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropColumn('margin_percent_standard');
        });
        Schema::table('tenders', function (Blueprint $table): void {
            $table->dropColumn('margin_percent_standard');
        });
    }

    /**
     * Karty z dowolnym slotem B2B ocenionym jako „special” — szerzej niż cena karty (SupplierSpecialMask::card),
     * bo rozmiary konta ze slotem specjalnym też są skalowane (SupplierSpecialMask::variant). Slotów z oceną
     * (cennik bazowy i rabat standardowy) jest niewiele, więc ocena w PHP tą samą regułą co evaluate().
     *
     * @return list<int>
     */
    private function affectedProductIds(): array
    {
        $ids = [];
        DB::table('product_source_prices')
            ->where('source_key', 'like', 'b2b:%')
            ->whereNotNull('base_price_net')
            ->whereNotNull('standard_discount_percent')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->select(['id', 'product_id', 'purchase_price', 'base_price_net', 'standard_discount_percent'])
            ->chunkById(self::CHUNK, function ($slots) use (&$ids): void {
                foreach ($slots as $slot) {
                    $evaluation = SupplierSpecialPrice::evaluate(
                        $slot->purchase_price !== null ? (float) $slot->purchase_price : null,
                        (float) $slot->base_price_net,
                        (float) $slot->standard_discount_percent,
                    );
                    if (($evaluation['status'] ?? null) === SupplierSpecialPrice::SPECIAL) {
                        $ids[(int) $slot->product_id] = (int) $slot->product_id;
                    }
                }
            });

        return array_values($ids);
    }
};
