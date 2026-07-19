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
        'price_lists.view',
        'price_lists.import',
        'price_lists.delete',
        'clients.view',
        'clients.manage',
        'ai_settings.manage',
        'admin.access',
        'admin.users.manage',
        'admin.roles.manage',
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
            'tenders.transition.draft',
            'tenders.transition.wycena',
            'tenders.transition.akceptacja_km',
            'tenders.transition.exported',
            'substitutes.manage',
            'products.view',
            'price_lists.view',
            'clients.view',
            'clients.manage',
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
            'substitutes.approve',
            'substitutes.manage',
            'products.view',
            'price_lists.view',
            'price_lists.import',
            'price_lists.delete',
            'clients.view',
            'clients.manage',
        ];

        $dyrektor = [
            'dashboard.view',
            'tenders.view_own',
            'tenders.view_all',
            'tenders.export',
            'tenders.transition.zatwierdzona',
            'tenders.transition.exported',
            'tenders.transition.archiwum',
            'tenders.transition.odrzucony',
            'substitutes.approve',
            'products.view',
            'price_lists.view',
            'clients.view',
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
            ['tenders.view_all', 'Przetargi — wszystkie', 'Widzi listę wszystkich przetargów w firmie.', 'Przetargi'],
            ['tenders.create', 'Tworzenie przetargu', 'Może założyć nowy projekt przetargowy.', 'Przetargi'],
            ['tenders.import', 'Import dokumentów', 'Może wgrywać PDF/XLSX/DOCX i uruchamiać analizę SIWZ.', 'Przetargi'],
            ['tenders.edit_offer', 'Edycja oferty', 'Może zmieniać pozycje, ceny i dopasowania (status szkic/wycena).', 'Przetargi'],
            ['tenders.export', 'Eksport oferty', 'Może pobrać ofertę do Excela i PDF.', 'Przetargi'],
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
            ['price_lists.view', 'Cenniki — podgląd', 'Może przeglądać zaimportowane cenniki producentów.', 'Produkty i cenniki'],
            ['price_lists.import', 'Cenniki — import', 'Może analizować i importować nowe cenniki.', 'Produkty i cenniki'],
            ['price_lists.delete', 'Cenniki — usuwanie', 'Może usuwać import cennika wraz z produktami wyłącznie z tego importu.', 'Produkty i cenniki'],
            ['clients.view', 'Klienci — podgląd', 'Może przeglądać listę klientów.', 'Klienci'],
            ['clients.manage', 'Klienci — edycja', 'Może dodawać i edytować klientów.', 'Klienci'],
            ['ai_settings.manage', 'Ustawienia AI', 'Może konfigurować model AI, klucz API i test połączenia.', 'Administracja'],
            ['admin.access', 'Panel Administracja', 'Widzi pozycję menu Administracja.', 'Administracja'],
            ['admin.users.manage', 'Zarządzanie użytkownikami', 'Może tworzyć, edytować i usuwać konta oraz przypisywać role.', 'Administracja'],
            ['admin.roles.manage', 'Zarządzanie rolami', 'Może zmieniać zestaw uprawnień przypisanych do ról.', 'Administracja'],
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
