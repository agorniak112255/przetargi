<?php

declare(strict_types=1);

namespace App\Support;

final class PermissionCatalog
{
    public const ALL = [
        'dashboard.view',
        'tenders.view_own',
        'tenders.view_all',
        'tenders.create',
        'tenders.import',
        'tenders.edit_offer',
        'tenders.export',
        'tenders.delete_items',
        'tenders.delete',
        'tenders.comment',
        'tenders.invite',
        'reports.view',
        'tenders.transition.draft',
        'tenders.transition.wycena',
        'tenders.transition.akceptacja_km',
        'tenders.transition.akceptacja_dyrektor',
        'tenders.transition.zatwierdzona',
        'tenders.transition.exported',
        'tenders.transition.archiwum',
        'tenders.transition.odrzucony',
        'substitutes.approve',
        'substitutes.manage',
        'products.view',
        'products.delete',
        'products.images.delete',
        'card_matches.view',
        'card_matches.decide',
        'price_lists.view',
        'price_lists.import',
        'price_lists.delete',
        'b2b_accounts.view',
        'b2b_accounts.manage',
        'clients.view',
        'clients.manage',
        'inquiries.use',
        'inquiries.view_all',
        'inquiries.view_others',
        'ai_settings.manage',
        'admin.access',
        'admin.users.manage',
        'admin.roles.manage',
        'admin.activity.view',
        'admin.sessions.view',
        'admin.sessions.manage',
        'admin.mail.manage',
        'admin.enrichment.view',
        'admin.presta.manage',
        'admin.search_sites.manage',
        'admin.ai_tuning.manage',
        'admin.ai_stats.view',
        'admin.catalog_slang.manage',
        'admin.dictionaries.manage',
        'admin.description_templates.manage',
        'presta.export',
    ];

    public const ROLES = [
        'handlowiec',
        'przetargi',
        'kierownik',
        'dyrektor',
        'admin',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        $handlowiec = [
            'dashboard.view',
            'tenders.view_own',
            'tenders.create',
            'tenders.edit_offer',
            'tenders.export',
            'tenders.comment',
            'tenders.transition.draft',
            'tenders.transition.wycena',
            'tenders.transition.akceptacja_km',
            'tenders.transition.exported',
            'substitutes.manage',
            'products.view',
            'price_lists.view',
            'clients.view',
            'clients.manage',
            'inquiries.use',
            // ten sam mail trafia do kilku handlowców — bez wglądu w cudze
            // zapytania dwie osoby robiłyby tę samą ofertę
            'inquiries.view_all',
        ];

        $przetargi = array_values(array_unique([
            ...$handlowiec,
            'tenders.view_all',
            'tenders.import',
        ]));

        $kierownik = [
            'dashboard.view',
            'tenders.view_own',
            'tenders.view_all',
            'tenders.create',
            'tenders.import',
            'tenders.edit_offer',
            'tenders.export',
            'tenders.transition.draft',
            'tenders.transition.wycena',
            'tenders.transition.akceptacja_km',
            'tenders.transition.akceptacja_dyrektor',
            'tenders.transition.exported',
            'tenders.transition.archiwum',
            'tenders.transition.odrzucony',
            'tenders.comment',
            'tenders.invite',
            'reports.view',
            'substitutes.approve',
            'substitutes.manage',
            'products.view',
            'price_lists.view',
            'price_lists.import',
            'price_lists.delete',
            'clients.view',
            'clients.manage',
            'inquiries.use',
            'inquiries.view_all',
            'inquiries.view_others',
        ];

        $dyrektor = [
            'dashboard.view',
            'tenders.view_own',
            'tenders.view_all',
            'tenders.export',
            'tenders.delete_items',
            'tenders.comment',
            'tenders.invite',
            'reports.view',
            'tenders.transition.zatwierdzona',
            'tenders.transition.exported',
            'tenders.transition.archiwum',
            'tenders.transition.odrzucony',
            'substitutes.approve',
            'products.view',
            'price_lists.view',
            'clients.view',
            'inquiries.use',
            'inquiries.view_all',
            'inquiries.view_others',
        ];

        return [
            'handlowiec' => $handlowiec,
            'przetargi' => $przetargi,
            'kierownik' => $kierownik,
            'dyrektor' => $dyrektor,
            'admin' => self::ALL,
        ];
    }

    public static function transitionPermission(string $status): string
    {
        return 'tenders.transition.'.$status;
    }

    /**
     * @return array<string, array{key: string, label: string, description: string, group: string}>
     */
    public static function definitions(): array
    {
        $items = [
            ['dashboard.view', 'Dashboard', 'Podgląd pulpitu z podsumowaniem przetargów i KPI.', 'Pulpit'],
            ['tenders.view_own', 'Przetargi — tylko własne', 'Widzi wyłącznie przetargi, których jest opiekunem.', 'Przetargi'],
            ['tenders.view_all', 'Widzi wszystkie przetargi', 'Widzi listę i szczegóły wszystkich przetargów w firmie (nie tylko własne i zaproszenia).', 'Przetargi'],
            ['tenders.create', 'Tworzenie przetargu', 'Może założyć nowy projekt przetargowy.', 'Przetargi'],
            ['tenders.import', 'Import dokumentów', 'Może wgrywać PDF/XLSX/DOCX i uruchamiać analizę SIWZ.', 'Przetargi'],
            ['tenders.edit_offer', 'Edycja oferty', 'Może zmieniać pozycje, ceny i dopasowania (status szkic/wycena).', 'Przetargi'],
            ['tenders.export', 'Eksport oferty', 'Może pobrać ofertę do Excela i PDF.', 'Przetargi'],
            ['tenders.delete_items', 'Usuwanie pozycji SIWZ', 'Może usuwać pojedyncze pozycje z przetargu (admin i dyrektor).', 'Przetargi'],
            ['tenders.delete', 'Usuwanie przetargu', 'Może usunąć cały przetarg wraz z pozycjami i dokumentami (tylko admin).', 'Przetargi'],
            ['tenders.comment', 'Komentarze w przetargu', 'Może dodawać komentarze do przetargu i pozycji.', 'Przetargi'],
            ['tenders.invite', 'Zapraszanie do przetargu', 'Może zapraszać użytkowników systemu do współpracy przy przetargu (jak opiekun).', 'Przetargi'],
            ['reports.view', 'Raporty', 'Podgląd raportów pipeline / marży i eksport CSV.', 'Pulpit'],
            ['tenders.transition.draft', 'Status → Szkic', 'Może cofnąć przetarg do statusu szkic.', 'Workflow statusów'],
            ['tenders.transition.wycena', 'Status → Wycena', 'Może ustawić status wycena (praca handlowca nad ofertą).', 'Workflow statusów'],
            ['tenders.transition.akceptacja_km', 'Status → Akceptacja kierownika', 'Może przekazać ofertę do akceptacji kierownika.', 'Workflow statusów'],
            ['tenders.transition.akceptacja_dyrektor', 'Status → Akceptacja dyrektora', 'Może przekazać ofertę do akceptacji dyrektora.', 'Workflow statusów'],
            ['tenders.transition.zatwierdzona', 'Status → Zatwierdzona', 'Może ostatecznie zatwierdzić ofertę (zwykle dyrektor).', 'Workflow statusów'],
            ['tenders.transition.exported', 'Status → Wyeksportowana', 'Może oznaczyć ofertę jako wyeksportowaną/wysłaną.', 'Workflow statusów'],
            ['tenders.transition.archiwum', 'Status → Archiwum', 'Może przenieść przetarg do archiwum.', 'Workflow statusów'],
            ['tenders.transition.odrzucony', 'Status → Odrzucony', 'Może odrzucić ofertę/przetarg.', 'Workflow statusów'],
            ['substitutes.approve', 'Akceptacja zamienników', 'Może zatwierdzać lub odrzucać zamienniki produktów.', 'Produkty i cenniki'],
            ['substitutes.manage', 'Zamienniki — edycja', 'Może dodawać, edytować i usuwać relacje produkt główny → zamiennik.', 'Produkty i cenniki'],
            ['products.view', 'Produkty — podgląd', 'Dostęp do katalogu produktów i listy zamienników.', 'Produkty i cenniki'],
            ['products.delete', 'Produkty — usuwanie', 'Może usuwać pozycje z katalogu produktów. W przetargach dopasowanie zostanie odpięte.', 'Produkty i cenniki'],
            ['products.images.delete', 'Produkty — usuwanie zdjęć', 'Może usunąć zdjęcie z karty produktu (przycisk × na miniaturze). Usunięte zdjęcie nie wróci przy synchronizacji z dostawcą ani przy ponownym pobieraniu danych.', 'Produkty i cenniki'],
            ['card_matches.view', 'Łączenie kart — podgląd', 'Widzi ekran „Łączenie kart”: propozycje połączenia karty dystrybutora z kartą producenta (ten sam EAN albo kod producenta) i historię decyzji.', 'Produkty i cenniki'],
            ['card_matches.decide', 'Łączenie kart — decyzje', 'Może łączyć i odrzucać propozycje oraz odświeżać ich listę. Po połączeniu zostaje karta producenta z nazwą i opisem, ceny dystrybutora dochodzą do niej, a karta dystrybutora znika (przed każdym połączeniem zapisuje się kopia zapasowa).', 'Produkty i cenniki'],
            ['price_lists.view', 'Cenniki — podgląd', 'Może przeglądać zaimportowane cenniki producentów.', 'Produkty i cenniki'],
            ['price_lists.import', 'Cenniki — import', 'Może analizować i importować nowe cenniki.', 'Produkty i cenniki'],
            ['price_lists.delete', 'Cenniki — usuwanie', 'Może usuwać import cennika wraz z produktami wyłącznie z tego importu.', 'Produkty i cenniki'],
            ['b2b_accounts.view', 'Konta B2B — podgląd', 'Widzi konta witryn B2B dostawców i może odsłonić hasło (każde odsłonięcie trafia do dziennika).', 'Produkty i cenniki'],
            ['b2b_accounts.manage', 'Konta B2B — edycja', 'Może dodawać, edytować i usuwać konta witryn B2B dostawców.', 'Produkty i cenniki'],
            ['clients.view', 'Klienci — podgląd', 'Może przeglądać listę klientów.', 'Klienci'],
            ['clients.manage', 'Klienci — edycja', 'Może dodawać i edytować klientów.', 'Klienci'],
            // Własna grupa, a nie „Klienci”: uprawnienia do zapytań były tam nie do znalezienia.
            ['inquiries.use', 'Zapytania — praca z mailem', 'Może wklejać zapytanie klienta (albo wysłać je z dodatku do Thunderbirda) i przygotować odpowiedź z katalogu.', 'Zapytania'],
            ['inquiries.view_all', 'Zapytania — podgląd wszystkich', 'Widzi na liście zapytania wszystkich pracowników, nie tylko własne, i może filtrować po użytkowniku. Bez tego widzi wyłącznie swoje.', 'Zapytania'],
            ['inquiries.view_others', 'Zapytania — otwieranie cudzych', 'Może otworzyć zapytanie innego pracownika i zobaczyć mail klienta, dobrane pozycje i przygotowany list. Tylko podgląd — zmieniać i wysyłać może wyłącznie autor.', 'Zapytania'],
            ['ai_settings.manage', 'Ustawienia AI', 'Może konfigurować model AI, klucz API i test połączenia.', 'Administracja'],
            ['admin.access', 'Panel Administracja', 'Widzi pozycję menu Administracja.', 'Administracja'],
            ['admin.users.manage', 'Zarządzanie użytkownikami', 'Może tworzyć, edytować i usuwać konta oraz przypisywać role.', 'Administracja'],
            ['admin.roles.manage', 'Zarządzanie rolami', 'Może zmieniać zestaw uprawnień przypisanych do ról.', 'Administracja'],
            ['admin.activity.view', 'Dziennik aktywności', 'Może przeglądać logowania i historię działań użytkowników (120 dni).', 'Administracja'],
            ['admin.sessions.view', 'Aktywne sesje', 'Widzi, kto jest teraz zalogowany, na jakiej podstronie pracuje i kiedy każdy użytkownik ostatnio korzystał z systemu.', 'Administracja'],
            ['admin.sessions.manage', 'Aktywne sesje — wylogowanie starych', 'Może wylogować sesje nieużywane od ponad 30 dni (np. logowanie zostawione na innym komputerze). Kto wróci do takiej przeglądarki albo do dodatku w Thunderbirdzie, zaloguje się ponownie.', 'Administracja'],
            ['admin.mail.manage', 'Konfiguracja SMTP', 'Może zmieniać ustawienia poczty wychodzącej i wysyłać test e-mail.', 'Administracja'],
            ['admin.enrichment.view', 'Logi AI', 'Może przeglądać zakończone pobierania opisów produktów.', 'Administracja'],
            ['admin.presta.manage', 'Sklep Presta', 'Może konfigurować połączenie ze sklepem PrestaShop i mapować kategorie.', 'Administracja'],
            ['admin.search_sites.manage', 'Strony wyszukiwarka', 'Może zarządzać domenami indeksu i liczbą linków wyszukiwarki.', 'Administracja'],
            ['admin.ai_tuning.manage', 'Strojenie AI', 'Może zmieniać limit wyników wyszukiwania w katalogu i progi dopasowania.', 'Administracja'],
            ['admin.ai_stats.view', 'Statystyki AI', 'Widzi koszt (tokeny) i przebieg wyszukiwań AI w wyszukiwarce, przetargach, zapytaniach z poczty i zamiennikach — z treścią zapytań, także z maili klientów.', 'Administracja'],
            ['admin.catalog_slang.manage', 'Żargon SIWZ', 'Może edytować słownik potocznych nazw z przetargów.', 'Administracja'],
            ['admin.dictionaries.manage', 'Słowniki', 'Może edytować słownik producentów, marek i wykluczeń używany do rozpoznawania marki w zapytaniach i przetargach.', 'Administracja'],
            ['admin.description_templates.manage', 'Szablony opisów', 'Może edytować instrukcje AI wg rodziny BHP.', 'Administracja'],
            ['presta.export', 'Eksport do Presty', 'Może wysyłać produkty (opis, rozmiary, termin na zamówienie) do sklepu PrestaShop.', 'Administracja'],
        ];

        $out = [];
        foreach ($items as [$key, $label, $description, $group]) {
            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'group' => $group,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, description: string, group: string}>
     */
    public static function definitionsList(): array
    {
        return array_values(self::definitions());
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            'handlowiec' => 'Handlowiec',
            'przetargi' => 'Przetargi / Marketing',
            'kierownik' => 'Kierownik',
            'dyrektor' => 'Dyrektor',
            'admin' => 'Administrator (IT)',
        ];
    }
}
