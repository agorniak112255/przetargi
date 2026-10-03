# Czat firmowy z rozmowami głosowymi i wideo — plan

Stan na 03.10.2026. Plan przeszedł recenzję drugiego agenta (Plan) — jego poprawki są uwzględnione,
a dwa kluczowe zarzuty (treść w dzienniku aktywności, `openDefaultBrowser` w dodatku) sprawdzone w kodzie.
Kodu jeszcze nie ma. Przed etapem 1 potrzebne są odpowiedzi na pytania z §12 i jedna sesja roota (§9).

## 1. Cel

Pracownicy Supon (6–10 osób) piszą do siebie i rozmawiają głosem i wideo bez zewnętrznego komunikatora.
Przewaga nad Teams i WhatsApp: rozmowa może nieść link do zapytania, przetargu albo maila
(„Wyślij koledze” z Thunderbirda, „sprawdź cenę w poz. 4” z linkiem prosto do pozycji).

## 2. Stan obecny (sprawdzony w kodzie)

- Backend: Laravel 12, Sanctum (token w `localStorage.supon_token`), Spatie permission, MariaDB 10.5,
  kolejki na tabelach `jobs*`, workery jako usługi systemd (`deploy/ensure-enrichment-workers.sh`).
- Brak broadcastingu i websocketów (ani Reverb, ani Pusher). Brak biblioteki JWT w `vendor`.
- Obecność: `POST /api/me/presence` → `users.last_seen_at` (AuthController::presence).
- Wszystkie trasy API w grupie `['auth:sanctum', 'log.activity']` (`routes/api.php:103`).
  `LogApiActivity` zapisuje payload każdego udanego POST (przycięty do 500 znaków, `ActivityLogger::sanitizePayload`)
  — klucz `body` nie jest maskowany. **Czat musi być z tego wyłączony.**
- Frontend: React 19 + Vite + Tailwind 4 + react-router, bez innych bibliotek.
- Dodatek Thunderbirda (MV2, TB ≥ 115): tło pyta `GET /api/inquiries/queued` co 5 s albo 30 s (`X-Poll-After`) —
  82% ruchu API na produkcji (pomiar 25.09). Już używa `browser.windows.openDefaultBrowser` (`background.js:83`)
  i `browser.notifications`. Samoaktualizacja co 15 min. Nowe uprawnienie w manifeście może wstrzymać cichą aktualizację.
- Serwer: Plesk (nginx przed Apache), systemd, Docker (SearXNG), kilka publicznych IPv4. Port 443 zajmuje Plesk.
  Zmiany roota (nginx, porty, zapora, systemctl, DNS) robi właściciel.

## 3. Architektura w skrócie

```
 Przeglądarka (Chrome/Edge)        Thunderbird
 ┌───────────────────────┐   ┌──────────────────────────────┐
 │ aplikacja /czat        │   │ przycisk „Czat” na lewym     │
 │ + rozmowa wideo        │   │ pasku = ta sama strona /czat │
 └──────┬──────────┬─────┘   │ tło dodatku: powiadomienia,  │
        │          │         │ dzwonek → otwiera przeglądarkę│
   HTTPS API   wss (Reverb)  └──────┬──────────┬────────────┘
        │          │                │          │
 ┌──────▼──────────▼────────────────▼──────────▼───────────┐
 │ Laravel (API czatu)  ──zdarzenie──▶  Reverb (systemd)     │
 │        │ token JWT                                       │
 │        ▼                                                 │
 │ LiveKit (Docker): obraz i dźwięk, UDP 7882 / TCP 7881     │
 └──────────────────────────────────────────────────────────┘
```

## 4. Decyzje

### 4.1 Jeden interfejs czatu — strona `/czat` w aplikacji
W Thunderbirdzie dodatek dodaje przycisk na lewym pasku (API `spaces`, od TB 115, **bez nowego uprawnienia**),
który otwiera tę samą stronę `/czat` w karcie Thunderbirda. Liczba nieprzeczytanych na przycisku
(`buttonProperties.badgeText`, najwyżej „99+”). Nie budujemy drugiego interfejsu w dodatku.
Przestrzeń tworzona przy każdym starcie tła (najpierw `spaces.query`, potem `create`/`update`).

### 4.2 Czas rzeczywisty — Laravel Reverb, od etapu 1
Dzwonek z opóźnieniem do 30 s jest bezużyteczny, więc websocket wchodzi od razu, nie „później”.
- Usługa systemd `przetargi-reverb` na 127.0.0.1:8080, `Restart=always`, `MemoryMax=256M`, `RuntimeMaxSec=1d`.
  `deploy/server-update.sh` po `config:cache` wywołuje `artisan reverb:restart`. Bez Pulse, bez skalowania.
- nginx w Plesku przekazuje `/app` i `/apps` z nagłówkami `Upgrade` / `Connection "Upgrade"`.
- `allowed_origins = *` (tło dodatku ma Origin `moz-extension://<UUID>`, inny na każdej instalacji);
  dostęp chronią kanały prywatne. `accept_client_events_from = none`.
- Autoryzacja: `->withBroadcasting(routes/channels.php, ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])`
  w `bootstrap/app.php` — poza grupą `log.activity`. Kanał `user.{id}`: `(int) $u->id === (int) $id && $u->can('chat')`.
- Zdarzenia: `ShouldBroadcastNow + ShouldDispatchAfterCommit + ShouldRescue`, w `broadcasting.php`
  `client_options => ['timeout' => 2, 'connect_timeout' => 1]`. Bez kolejki (workery są zajęte AI —
  kolejka opóźniałaby dzwonek). Awaria Reverb nie psuje zapisu wiadomości.
- **Zdarzenie to sygnał, nie treść**: niesie id wiadomości, id rozmowy, autora i skrót do 300 znaków.
  Limit Reverb to 10 000 B na zdarzenie, a 4000 polskich znaków w JSON-ie w JSON-ie go przekracza.
  Klient dociąga pełną treść z API (`messages?after_id=`).
- **API jest źródłem prawdy**: po każdym (ponownym) połączeniu klient odświeża listę rozmów i dociąga
  `after_id` w otwartej rozmowie; duplikaty odrzuca po `id`. Bez `toOthers` — nadawca ma kilka urządzeń.
- Frontend: `laravel-echo` + `pusher-js` (npm), `authorizer` z nagłówkiem Bearer; nowa instancja Echo
  po zalogowaniu i wylogowaniu (`auth.tsx`).
- Dodatek: przypięta wersja `pusher-js` jako plik w `background.scripts` (≈70 kB), autoryzacja kanału przez
  istniejące `api()` z `common.js`. Ręczny klient protokołu odrzucony — kody 4000–4299, `activity_timeout`,
  ponowna subskrypcja i `socket_id` to źródło trudnych do zauważenia błędów.
- Zapas, gdy websocket nie działa: liczba nieprzeczytanych w nagłówku `X-Chat-Unread` na istniejącym
  `inquiries/queued`. **Wystarcza tylko dla tekstu — dzwonienie bez websocketu nie działa** i interfejs to mówi.

### 4.3 Głos i wideo — LiveKit na własnym serwerze

| | P2P + coturn | **LiveKit (wybrany)** | Jitsi Meet |
|---|---|---|---|
| 3+ osoby z wideo | słabo | tak | tak |
| Na serwerze | coturn | 1 program (Go), Docker | 4 kontenery (Java JVB, Prosody…) |
| Logowanie z aplikacji | własna sygnalizacja | token JWT z Laravela | JWT przez wtyczkę Prosody |
| Interfejs | od zera | nasz, gotowa biblioteka JS | gotowy, w ramce |

LiveKit: SFU bez przekodowywania, przy 10 osobach małe obciążenie CPU obok workerów AI.
P2P + coturn wystarczyłby tylko przy rozmowach wyłącznie 1:1 (pytanie 5). Jitsi — zapas, gdyby LiveKit
nie przeszedł prototypu.
- Docker `livekit/livekit-server`, sieć hosta. Sygnalizacja 7880 przez nginx: `wss://rtc.supon.rzeszow.pl`
  (subdomena + Let's Encrypt w Plesku). Media: UDP 7882 (jeden port, mux) + TCP 7881.
- Jawnie `rtc.node_ip` (serwer ma kilka IP — STUN mógłby wykryć inny adres niż DNS), `use_external_ip: false`.
  `room.empty_timeout` i `departure_timeout`, żeby pokoje same się zamykały.
- **TURN/TLS nie w etapie 0**: wymaga osobnej domeny, certyfikatu i portu 443 (zajęty przez Plesk).
  Gdy w prototypie któraś sieć (hotel, LTE operatora) nie przejdzie na UDP/TCP: `turn.supon…` na 443
  na wolnym dodatkowym IP, na którym nginx Pleska nie nasłuchuje, + hak odnowienia certyfikatu.
- Token: HS256 podpisany w Laravelu, identity = id użytkownika, room = `call-{id}`, grants: roomJoin,
  canPublish, canSubscribe, ważny 2 min (dotyczy tylko pierwszego połączenia; nie da się go unieważnić,
  więc krótko). Dodać `firebase/php-jwt` zamiast własnego podpisu (podpis + weryfikacja webhooka, `alg`,
  `exp`, `nbf`, `hash_equals` — mniej miejsc na błąd niż 30 linii własnego kodu).
- Webhook LiveKit: weryfikacja JWT i sha256 surowego body; trasa poza `auth:sanctum` i `log.activity`,
  z limitem żądań. Zapas: komenda w harmonogramie co minutę kończy `ringing` > 45 s jako `missed`.
- Frontend: `livekit-client` ładowany leniwie tylko na stronie rozmowy.

### 4.4 Gdzie odbywa się rozmowa
**W przeglądarce (Chrome/Edge)**, strona `/czat/rozmowa/{id}`. Nie ma potwierdzenia, że Thunderbird
obsługuje pytanie o kamerę i mikrofon w karcie. Dodatek przy połączeniu przychodzącym: powiadomienie
+ dźwięk; kliknięcie → `openDefaultBrowser`. Powiadomienia Thunderbirda nie mają przycisków
(„Odbierz/Odrzuć” są dopiero na stronie). Prototyp sprawdzi kamerę w karcie Thunderbirda — jeśli działa,
rozmowa może zostać w Thunderbirdzie.

## 5. Model danych (migracje odwracalne)

- `chat_conversations`: id, type (`channel` | `direct`), name null, direct_key null unique („minId:maxId”),
  created_by, last_message_id null, timestamps.
- `chat_participants`: conversation_id, user_id, last_read_message_id null (tylko rośnie: `GREATEST`),
  unique (conversation_id, user_id).
- `chat_messages`: id, conversation_id, user_id (nullOnDelete), kind (`text` | `system` | `call` | `mail` | `link`),
  body text null (maks. 4000 znaków), meta json null (link do zapytania/przetargu, temat i nadawca maila),
  client_uuid, unique (user_id, client_uuid), deleted_at null, created_at; indeks (conversation_id, id).
  Wiadomość systemowa poznajemy po `kind`, nie po `user_id null` — `user_id null` przy `text` = „konto usunięte”.
- `chat_calls`: id, conversation_id, started_by, kind (`audio` | `video`), status (`ringing` | `active` | `ended` | `missed`),
  started_at, answered_at null, ended_at null. Nazwa pokoju wyliczana: `call-{id}`.

Zmiany po recenzji: bez typu `context` (link do zapytania/przetargu to `meta` wiadomości — nie trzeba
dziedziczyć uprawnień do zapytania), bez `muted`, `joined_at`, `left_at`, `edited_at`, `room_name`.

## 6. API (`auth:sanctum` + uprawnienie `chat`, **poza `log.activity`**)

- `GET /chat/users` — imię i „w pracy” (last_seen_at < 15 min).
- `GET /chat/conversations` — moje rozmowy, ostatnia wiadomość, nieprzeczytane jednym zapytaniem (bez N+1):
  JOIN `chat_messages` po `id > COALESCE(last_read_message_id, 0)`, `user_id <> ja`, `deleted_at IS NULL`, GROUP BY rozmowa.
- `POST /chat/conversations` — 1:1 (`user_id`, zwraca istniejącą) albo kanał (`name`, `user_ids`).
- `GET /chat/conversations/{id}/messages?after_id=|before_id=&limit=50`
- `POST /chat/conversations/{id}/messages` (`body`, `client_uuid`, `meta`) — powtórka z tym samym `client_uuid`
  zwraca istniejący wiersz (200) tylko w tej samej rozmowie.
- `DELETE /chat/messages/{id}` — tylko własna; czyści `body` i `meta`, ustawia `deleted_at` („wiadomość usunięta”).
- `POST /chat/conversations/{id}/read` (`message_id`)
- `GET /chat/unread` — suma (dla zapasu i przycisku w Thunderbirdzie).
- `POST /chat/conversations/{id}/calls` (`kind`) — `lockForUpdate` na rozmowie; gdy jest już `ringing`/`active`,
  zwraca tę rozmowę zamiast drugiej → call + token + adres LiveKit.
- `POST /chat/calls/{id}/join` → token; `/decline` (w grupie nie kończy połączenia); `/end`.
- `POST /chat/livekit/webhook` (osobno, bez Sanctum).

Zdarzenia na `private-user.{id}`: `chat.message` (sygnał + skrót), `chat.read`, `chat.call.ringing`, `chat.call.updated`
(wycisza dzwonek wszędzie po odebraniu/odrzuceniu/końcu).

Limity: wiadomości 60/min, nowe rozmowy i połączenia 10/min na użytkownika.

## 7. Dodatek Thunderbirda

- Przycisk „Czat” (`spaces`) z liczbą nieprzeczytanych → karta `/czat` (logowanie raz w karcie).
- Tło: `pusher-js` + kanał użytkownika. Powiadomienie o wiadomości tylko wtedy, gdy aktywna karta nie jest
  przestrzenią czatu (`Tab.spaceId`). Połączenie przychodzące: powiadomienie + dźwięk co 3 s przez 30 s,
  przerwane przez `chat.call.updated`; kliknięcie otwiera rozmowę w przeglądarce.
- Okienko nad mailem: „Wyślij koledze” — osoba + komentarz → wiadomość `kind=mail` (temat, nadawca, data,
  Message-ID; treść maila tylko po zaznaczeniu).
- **Przy okazji (etap 1)**: skoro dodatek ma websocket, zdarzenie „nowa prośba w kolejce” idzie przez Reverb,
  a `X-Poll-After` rośnie do 60–120 s. To usuwa większość z 82% ruchu API.
- Bez nowych uprawnień w manifeście.

## 8. Frontend

- Pozycja „Czat” w menu (`Layout.tsx`) z liczbą nieprzeczytanych.
- `/czat`: rozmowy po lewej, wiadomości po prawej, pole wpisywania; starsze przy przewijaniu w górę.
- Tekst renderowany jako tekst (bez `dangerouslySetInnerHTML`); klikalne tylko linki http/https z
  `rel="noopener noreferrer"`. Token w localStorage + treści od innych osób = XSS byłby groźny; rozważyć CSP.
- Przy zapytaniu i przetargu: „Wyślij w czacie” → wiadomość `kind=link` z linkiem.
- `/czat/rozmowa/{id}`: głos/wideo, wycisz, kamera, udostępnij ekran, rozłącz. Okno dzwonienia na każdej stronie
  aplikacji (gdy jest otwarta w przeglądarce).
- Pomoc: nowy moduł w `pages/help/`. Słownictwo bez skrótów.

## 9. Jedna sesja roota u właściciela (przed etapem 1)

Zebrane w jednym miejscu, żeby nie wracać:
1. nginx (Plesk → „Additional nginx directives” dla przetargi): przekazanie `/app` i `/apps` do 127.0.0.1:8080.
2. Usługa systemd `przetargi-reverb` (skrypt w `deploy/`, jak workery).
3. DNS + Let's Encrypt dla `rtc.supon.rzeszow.pl`, nginx → 127.0.0.1:7880.
4. Zapora Pleska: TCP 7881, UDP 7882.
5. Docker `livekit/livekit-server` z plikiem konfiguracji (skrypt w `deploy/`, jak SearXNG).

## 10. Etapy

0. **Prototyp (0,5–1 dnia)**, nic na danych produkcji:
   (a) `spaces` z adresem aplikacji w TB 115 i 128 — czy logowanie zostaje po restarcie;
   (b) kamera i mikrofon w karcie Thunderbirda;
   (c) Reverb za nginx Pleska (wss), także z tła dodatku;
   (d) LiveKit: rozmowa z sieci firmowej i z telefonu na LTE; obciążenie CPU i pasma przy 4 osobach z wideo;
   (e) kliknięcie powiadomienia na Windows i dźwięk `Audio` w tle dodatku; autoodtwarzanie dzwonka w karcie przeglądarki.
1. **Czat tekstowy + Reverb** (2–3 dni): tabele, API, zdarzenia, `/czat`, przycisk w Thunderbirdzie, powiadomienia,
   wypychanie kolejki zapytań przez Reverb.
2. **Linki do zapytań/przetargów i „Wyślij koledze”** (1 dzień).
3. **Głos i wideo** (3–4 dni): LiveKit, dzwonienie, strona rozmowy, ekran, webhook.
4. Później: pliki w czacie, wyszukiwanie, powiadomienia na telefon (PWA + Web Push), TURN/TLS jeśli potrzebny.

## 11. Testy

- PHPUnit (Feature): tylko uczestnik widzi rozmowę i wiadomości; unikalność 1:1; idempotencja `client_uuid`
  (także próba w cudzej rozmowie); liczniki nieprzeczytanych bez własnych wiadomości i usuniętych;
  `last_read` nie cofa się; **brak treści czatu w `activity_logs`**; zdarzenie z 4000 znaków „ą” < 10 kB;
  awaria Reverb nie daje 500 przy wysyłce; autoryzacja kanału `user.{id}` (cudzy id → 403, bez uprawnienia → 403);
  dwa równoczesne połączenia → jedna rozmowa; odrzucenie w grupie nie kończy połączenia; token LiveKit
  (claims, ważność); webhook ze złym podpisem → 401; nieodebrana po 45 s (`freezeTime`); usunięcie konta → „konto usunięte”.
- Dodatek: symulacja w Node + vm (udawany Pusher i zegar), jak przy harmonogramie kolejki.
- Frontend: `tsc` + `oxlint`, atrapa w `preview.local`.
- Zapytania z GROUP BY sprawdzone tinkerem READ ONLY na produkcji (MariaDB ONLY_FULL_GROUP_BY vs SQLite w testach).

## 12. Pytania do właściciela (przed etapem 1)

1. Kto ma czat — wszyscy z kontem w aplikacji?
2. Czy ktokolwiek (właściciel, admin) może czytać cudze rozmowy? Plan zakłada, że **nie**. Jeśli tak —
   to monitoring korespondencji: pracownicy muszą być o tym poinformowani na piśmie.
3. Jak długo trzymać wiadomości? Propozycja: 24 miesiące, potem automatyczne czyszczenie.
4. Czy pokazywać innym, kto jest teraz „w pracy”? (też informacja dla pracowników)
5. Ile osób najwięcej w jednej rozmowie wideo? (gdy tylko 1:1 — prostsza technika)
6. Czy potrzebne są powiadomienia na telefonie poza biurem? (zmienia priorytet etapu 4)

## 13. Ryzyka

- Reverb to długo żyjący proces PHP — restart co dobę i limit pamięci w usłudze.
- Thunderbird zamknięty i aplikacja nie otwarta = brak dzwonka (do czasu powiadomień na telefon).
- Sieci blokujące UDP i nietypowe porty — bez TURN/TLS na 443 rozmowa się tam nie połączy (sprawdzić w etapie 0).
- Obciążenie serwera wideo obok 16 workerów AI — pomiar w etapie 0.
- Kamera w Thunderbirdzie niepewna — dlatego rozmowa w przeglądarce.
