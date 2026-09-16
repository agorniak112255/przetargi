<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wpisuje tabelę upustów PROTEKT (skan przekazany 16.09.2026) na konto B2B, żeby nie trzeba było
 * klikać czterdziestu wierszy w panelu. Dalsze zmiany robi się już w panelu — to polecenie służy
 * do pierwszego założenia listy albo do przywrócenia jej do stanu z tabeli.
 *
 * Kolejność reguł ma znaczenie: najpierw wąskie dopasowania po numerze katalogowym (w jednej kategorii
 * dostawcy bywa kilka stawek — CR200 10%, CR300 20%, reszta samohamownych 30%), dopiero potem kategorie.
 *
 * Świadomie NIE zakładamy reguły „wszystko”: dopóki jej nie ma, karta spoza tabeli zostaje pominięta
 * z powodem zamiast dostać przypadkową stawkę. Gdy zdecydujecie się na stawkę dla reszty asortymentu,
 * dodajcie ją w panelu jako ostatnią pozycję listy.
 */
final class B2bProtektDiscountsCommand extends Command
{
    protected $signature = 'b2b:protekt-discounts
        {account : ID konta B2B protekt.pl (widoczne na karcie w Cenniki → B2B)}
        {--force : Nadpisz listę, jeśli konto ma już reguły}';

    protected $description = 'Zakłada na koncie B2B tabelę upustów PROTEKT na grupy asortymentowe';

    /**
     * Tabela upustów: [nazwa grupy, pole, typ dopasowania, wzorzec, upust %].
     *
     * Wzorce po numerze katalogowym są sprawdzone na stronie producenta (16.09.2026): serie AW i BW
     * występują wyłącznie w amortyzatorach, HA008 obejmuje hełm Montana z osprzętem i nie rusza EVO LITE
     * (HA006). Wzorce po kategorii odpowiadają adresom kategorii w mapie strony.
     *
     * Wzorce po nazwie handlowej (ROLEX, LINOSTOP, BLOBMAX, SKR-BLOCK, GRIDER, BASIC, LIFTER) mają pewny
     * upust z tabeli, ale samo dopasowanie wybrano z nazw kart — warto je przejrzeć w panelu po pierwszym
     * pobraniu cennika, patrząc na kolumnę „Kart”.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: float}>
     */
    private const RULES = [
        // --- wąskie dopasowania po numerze katalogowym: jedna kategoria, kilka stawek ---
        ['Urządzenia samohamowne CR200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0],
        ['Urządzenia samohamowne CR300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR300', 20.0],
        ['Amortyzatory bezpieczeństwa ABM + BW', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0],
        ['Amortyzatory bezpieczeństwa ABW (seria AW)', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'AW', 40.0],
        ['Poziome liny kotwiczące AE300, AE320', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'AE3', 20.0],
        ['Poziome liny kotwiczące LP100', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'LP100', 45.0],
        ['Poziome liny kotwiczące LP120', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'LP120', 45.0],
        ['Poziome liny kotwiczące LP200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'LP200', 45.0],
        ['Poziome liny kotwiczące LP201', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'LP201', 45.0],
        ['Słupołazy SP401', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'SP401', 20.0],
        ['Słupołazy SP402', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'SP402', 20.0],
        ['Pasy do przenoszenia ciężkich rzeczy AP300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'AP300', 30.0],
        ['Liny AC100', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'AC100', 30.0],
        ['Hełmy Montana', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'HA008', 15.0],

        // --- dopasowania po nazwie handlowej (seria bez własnego prefiksu numeru) ---
        ['Urządzenia samohamowne ROLEX', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'ROLEX', 30.0],
        ['Urządzenia samozaciskowe LINOSTOP', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'LINOSTOP', 30.0],
        ['Urządzenia samozaciskowe BLOBMAX', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'BLOBMAX', 30.0],
        ['Urządzenia samozaciskowe SKR-BLOCK', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'SKR-BLOCK', 30.0],
        ['Urządzenia samozaciskowe AC200', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'AC 200', 30.0],
        ['Urządzenia do nadawania pozycji PROT', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'PROT ', 30.0],
        ['Słupki kotwiczące PROTON 1', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'PROTON 1', 20.0],
        ['Zestawy GRIDER', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'GRIDER', 30.0],
        ['Zestawy BASIC', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'BASIC', 25.0],
        ['Zestawy LIFTER', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'LIFTER', 25.0],
        ['Zawiesia WS', B2bDiscountRule::FIELD_NAME, B2bDiscountRule::TYPE_CONTAINS, 'WS Zawiesia', 25.0],
        ['Ławki do prac na wysokości BA100', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'BA1', 20.0],
        ['Ławki do prac na wysokości BA200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'BA2', 20.0],
        ['Ławki do prac na wysokości BA300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'BA3', 20.0],
        ['Systemy ewakuacyjne — zaczep AT300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'AT300', 20.0],
        ['Urządzenia do instalowania liny DT200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'DT200', 20.0],
        ['Urządzenia do instalowania liny DT600', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'DT600', 20.0],
        ['Urządzenia do instalowania liny DT650', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'DT650', 20.0],
        ['Urządzenia do instalowania liny DT651', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'DT651', 20.0],
        ['Urządzenia do instalowania liny DT710', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'DT710', 20.0],

        // --- kategorie ze strony producenta (po wąskich dopasowaniach powyżej) ---
        ['Szelki bezpieczeństwa', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'szelki-bezpieczenstwa', 45.0],
        ['Pasy i urządzenia do pracy w podparciu', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'pasy-i-urzadzenia-do-pracy-w-podparciu', 45.0],
        ['Linki bezpieczeństwa', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'linki-bezpieczenstwa', 45.0],
        ['Amortyzatory bezpieczeństwa', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'amortyzatory-bezpieczenstwa', 45.0],
        ['Urządzenia samohamowne', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'urzadzenia-samohamowne', 30.0],
        ['Urządzenia samozaciskowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'urzadzenia-samozaciskowe', 30.0],
        ['Punkty i urządzenia zaczepowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'punkty-i-urzadzenia-zaczepowe', 20.0],
        ['Zaczepy taśmowe i linkowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'zaczepy-tasmowe-i-linkowe', 20.0],
        ['Zatrzaśniki', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'zatrzasniki', 20.0],
        ['Sprzęt ewakuacyjny', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'sprzet-ewakuacyjny', 20.0],
        ['Statywy i trójnogi bezpieczeństwa', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'statywy', 30.0],
        ['Słupołazy', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'slupolazy', 20.0],
        ['Drzewołazy', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'drzewolazy', 20.0],
        ['Urządzenia do instalowania liny roboczej', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'urzadzenia-do-instalowania-liny-roboczej', 20.0],
        ['Hełmy przemysłowe, ochrona wzroku i słuchu', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'helmy-przemyslowe', 15.0],
        ['Drabiny, rusztowania i podesty', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'drabiny-rusztowania-i-podesty', 10.0],
        ['Zawiesia (pozostałe)', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'zawiesia', 20.0],
        ['Kamizelki i kombinezony', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'kamizelki', 20.0],
        ['Kombinezon z szelkami bezpieczeństwa', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'kombinezon', 20.0],
        ['Namioty, parawany i płotki odgradzające', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'namioty-parawany-i-plotki', 20.0],
        ['Sprzęt strażacki i ratunkowy', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'strazack', 20.0],
        ['Podpinki i linki strażackie', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'podpinki-i-linki-strazackie', 20.0],
        ['Pasy bojowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'pasy-bojowe', 20.0],
        ['Linki narzędziowe, worki, torby, plecaki', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'linki-narzedziowe', 20.0],
        ['Worki transportowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'worki-transportowe', 20.0],
        ['Torby transportowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'torby-transportowe', 20.0],
        ['Plecaki transportowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'plecaki-transportowe', 20.0],
        ['Akcesoria dodatkowe', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_CONTAINS, 'akcesoria', 20.0],
    ];

    public function handle(): int
    {
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o tym ID.');

            return self::FAILURE;
        }
        if ($account->connector !== 'protekt') {
            $this->error('Konto '.$account->id.' („'.$account->username.'”) nie używa łącznika protekt.pl.');

            return self::FAILURE;
        }

        $existing = $account->discountRules()->count();
        if ($existing > 0 && ! $this->option('force')) {
            $this->error('Konto ma już '.$existing.' reguł rabatowych. Użyj --force, żeby zastąpić je tabelą z cennika.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($account): void {
            $account->discountRules()->delete();
            foreach (self::RULES as $position => [$name, $field, $type, $pattern, $discount]) {
                $account->discountRules()->create([
                    'position' => $position,
                    'name' => $name,
                    'match_field' => $field,
                    'match_type' => $type,
                    'pattern' => $pattern,
                    'discount_percent' => $discount,
                ]);
            }
        });

        $this->info('Zapisano '.count(self::RULES).' reguł rabatowych na koncie „'.$account->username.'”.');
        $this->newLine();
        $this->warn('Przejrzyj listę w panelu (Cenniki → B2B → Rabaty) przed pierwszym pobraniem cennika:');
        $this->line(' • wzorce po nazwie handlowej (ROLEX, GRIDER, BASIC, LIFTER) wybrano z nazw kart '
            .'producenta — sprawdźcie, czy nie łapią czegoś spoza grupy;');
        $this->line(' • nie ma reguły „wszystko” na końcu listy: karta spoza tabeli zostanie pominięta z powodem, '
            .'zamiast dostać przypadkową stawkę. Stawkę dla reszty asortymentu dodajcie sami, jeśli ma obowiązywać;');
        $this->line(' • po pobraniu cennika kolumna „Kart” pokazuje, ile kart trafiło w każdą regułę — zero oznacza '
            .'wzorzec do poprawienia.');

        return self::SUCCESS;
    }
}
