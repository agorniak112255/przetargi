<?php

declare(strict_types=1);

/*
 * Stan systemu: zadania harmonogramu, których przebiegi zapisuje ScheduledTaskRecorder (scheduled_task_runs),
 * i progi ekranu „Stan systemu”.
 *
 * Klucz zadania = tekst polecenia dokładnie jak w $schedule->command('…') w bootstrap/app.php albo nazwa
 * ->name('…') zadania-funkcji. Godziny dla ludzi liczy SystemStatusService z nextRunDate() w czasie polskim
 * (część zadań jest planowana w UTC), dlatego `schedule` opisuje tylko częstotliwość.
 *
 * - label: nazwa zadania na ekranie
 * - schedule: częstotliwość słowami
 * - nightly: liczy się do kafelka „Zadania nocne”
 * - only_failures: zadanie częste — zapisywane są tylko błędy (bez wiersza na każdy udany przebieg)
 * - reopen_within_minutes (opcjonalnie): okno ponownego otwarcia incydentu tego zadania (zamiast globalnego
 *   reopen_within_minutes niżej, które dotyczy tylko zadań częstych)
 */
return [
    'tasks' => [
        'erp:sync --match' => ['label' => 'Towary i stany z ERP XL', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:rw-pw' => ['label' => 'Pary wydań i przyjęć (RW i PW) z ERP XL', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:customers' => ['label' => 'Kontrahenci z ERP XL do kampanii', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:clients' => ['label' => 'Klienci z ERP XL', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:campaign-sales' => ['label' => 'Sprzedaż po kampaniach z ERP XL', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:suggest --limit=2000' => ['label' => 'Propozycje powiązań z ERP XL', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:inventory-history --outdated' => ['label' => 'Historia zapasów', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'activity-logs:prune' => ['label' => 'Czyszczenie starego dziennika aktywności', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'search-events:prune' => ['label' => 'Czyszczenie starych wyszukiwań', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'campaigns:stock-followup' => ['label' => 'Wynik kampanii po 7 i 30 dniach', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'system:prune' => ['label' => 'Czyszczenie starych przebiegów i powiadomień', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'products:match-candidates' => ['label' => 'Propozycje łączenia kart', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'bzp:fetch' => ['label' => 'Ogłoszenia i wyniki przetargów z Biuletynu', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'erp:client-documents' => ['label' => 'Faktury i paragony klientów z ERP XL', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'inspections:find-emails' => ['label' => 'Przeglądy: adresy e-mail klientów ze stron WWW', 'schedule' => 'codziennie w nocy', 'nightly' => true, 'only_failures' => false],
        'erp:inspections' => ['label' => 'Przeglądy: usługi i faktury z ERP XL', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'inquiries:order-hints' => ['label' => 'Powiązania zapytań z klientami i podpowiedzi zamówień', 'schedule' => 'codziennie rano', 'nightly' => true, 'only_failures' => false],
        'storage:prune' => ['label' => 'Czyszczenie plików tymczasowych', 'schedule' => 'co godzinę', 'nightly' => false, 'only_failures' => false],
        'jina-usage-snapshot' => ['label' => 'Saldo usługi wyszukiwania stron', 'schedule' => 'co godzinę', 'nightly' => false, 'only_failures' => false],
        'products:retry-images' => ['label' => 'Ponowne pobieranie zablokowanych zdjęć', 'schedule' => 'co 3 godziny', 'nightly' => false, 'only_failures' => false],
        // raz dziennie (po 7:00) wysyła najwięcej e-maili — stały błąd poczty u jednego odbiorcy psuje przebieg co dzień
        'tenders:remind' => ['label' => 'Przypomnienia o terminach i wynikach przetargów', 'schedule' => 'co 15 minut', 'nightly' => false, 'only_failures' => true, 'reopen_within_minutes' => 1560],
        // jak tenders:remind — najwięcej e-maili raz dziennie po 7:00
        'crm:remind' => ['label' => 'Przypomnienia z notatek o klientach i o ważności ofert', 'schedule' => 'co 15 minut', 'nightly' => false, 'only_failures' => true, 'reopen_within_minutes' => 1560],
        'campaigns:replies' => ['label' => 'Odpowiedzi klientów na kampanie', 'schedule' => 'co 10 minut', 'nightly' => false, 'only_failures' => true],
        'system:check' => ['label' => 'Sprawdzanie stanu systemu', 'schedule' => 'co 10 minut', 'nightly' => false, 'only_failures' => true],
        'b2b:sync-due' => ['label' => 'Uruchamianie pobierania z kont dostawców', 'schedule' => 'co minutę', 'nightly' => false, 'only_failures' => true],
        'campaigns:dispatch' => ['label' => 'Wysyłka kampanii', 'schedule' => 'co minutę', 'nightly' => false, 'only_failures' => true],
    ],

    /** Brak sygnału harmonogramu (b2b-scheduler-heartbeat) dłużej niż tyle minut = harmonogram nie działa. */
    'scheduler_stale_minutes' => 5,

    /**
     * Zadanie nocne bez przebiegu rozpoczętego od ostatniego planowego terminu, gdy od terminu minęło więcej niż tyle
     * minut = „nie ruszyło o czasie” (alert z system:check). Zapas na zadania, które czekają na poprzednie.
     */
    'stale_grace_minutes' => 120,

    /**
     * Błąd zadania częstego (only_failures) w ciągu tylu minut od zamknięcia jego incydentu otwiera ten incydent
     * na nowo — bez nowego e-maila (zadanie, które raz działa, raz nie, nie zasypuje skrzynki).
     */
    'reopen_within_minutes' => 360,

    /**
     * Seria błędów konta dostawcy (nieudane przebiegi od ostatniego udanego) zaczęta wcześniej niż tyle godzin temu
     * (np. zastana przy pierwszym system:check po wdrożeniu, konto psujące się od tygodni) zakłada incydent widoczny
     * na ekranie, ale bez e-maila — e-mail wyjdzie przy nowej serii błędów, po udanym przebiegu.
     */
    'b2b_mail_max_age_hours' => 48,

    /**
     * Błąd konta dostawcy w ciągu tylu minut od zamknięcia jego incydentu otwiera ten incydent na nowo (bez nowego
     * e-maila) — według częstotliwości pobierania konta: dzienne 26 h, tygodniowe 8 dni (konto, które co drugi
     * przebieg działa, nie wysyła e-maila za każdym razem).
     */
    'b2b_reopen_within_minutes' => [
        'daily' => 1560,
        'weekly' => 11520,
    ],

    /** Tyle ostatnich znaków komunikatu polecenia trafia do scheduled_task_runs.output_tail. */
    'output_tail_chars' => 2000,

    /** Przebiegi zadań starsze niż tyle dni czyści system:prune. */
    'runs_retention_days' => 60,

    /** Najwięcej wierszy na liście „Dane do uzupełnienia → Pokaż”. */
    'gap_rows_limit' => 100,
];
