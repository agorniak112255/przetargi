# Supon Przetargi — dodatek do Thunderbirda

Zakłada zapytanie w aplikacji Przetargi z otwartego maila i wstawia przygotowaną
odpowiedź jako odpowiedź na ten sam mail.

## Jak to działa

1. Otwierasz mail od klienta i klikasz ikonę dodatku nad wiadomością.
2. Dodatek pokazuje oczyszczoną treść (bez cytatów i stopki) — możesz ją poprawić.
3. Klikasz **Wyślij do Przetargów**. Aplikacja otwiera się w przeglądarce na tym zapytaniu.
4. Wybierasz produkty w aplikacji.
5. Wracasz do Thunderbirda, klikasz ikonę dodatku i **Wstaw odpowiedź do maila**.
   Otwiera się zwykłe okno odpowiedzi — z adresatem, cytatem i podpisem — z gotową treścią na górze.
6. Wysyłasz. Dodatek sam oznacza zapytanie w aplikacji jako obsłużone.

## Instalacja

Dodatek nie jest podpisany przez Mozillę, więc trzeba raz wyłączyć wymóg podpisu.

1. Thunderbird → menu **☰** → **Ustawienia** → **Ogólne** → na dole **Edytor konfiguracji**.
2. Znajdź `xpinstall.signatures.required` i ustaw na **false**.
3. Menu **☰** → **Dodatki i motywy** → koło zębate → **Zainstaluj dodatek z pliku**.
4. Wskaż plik `supon-przetargi.xpi`.
5. Menu **☰** → **Dodatki i motywy** → przy dodatku **Ustawienia** → wpisz adres aplikacji,
   swój e-mail i hasło → **Połącz**.

Hasło nie jest zapisywane — służy tylko do jednorazowego pobrania klucza dostępu.

## Budowanie pliku XPI

XPI to zwykłe ZIP-owe archiwum zawartości tego katalogu (bez katalogu nadrzędnego):

```bash
cd thunderbird-addon && zip -r -FS ../supon-przetargi.xpi . -x '*.git*' 'README.md'
```

Na stanowisku bez polecenia `zip` (Windows) to samo robi PowerShell:

```bash
powershell -Command "Compress-Archive -Path C:\xampp\htdocs\Przetargi\thunderbird-addon\* -DestinationPath C:\xampp\htdocs\Przetargi\supon-przetargi.zip -Force; Move-Item C:\xampp\htdocs\Przetargi\supon-przetargi.zip C:\xampp\htdocs\Przetargi\supon-przetargi.xpi -Force"
```

Przy każdej poprawce podnieś `version` w `manifest.json` — bez tego Thunderbird
nie zaproponuje aktualizacji przy instalacji nowego pliku.

## Adres aplikacji

Dodatek ma w `manifest.json` uprawnienie do domeny
`https://przetargi.supon.rzeszow.pl/*` i tylko z nią może się łączyć — bez tego
Thunderbird blokuje zapytania (komunikat „Brak połączenia z…”). Po zmianie adresu
aplikacji trzeba dopisać nową domenę do `permissions` i zbudować XPI od nowa.

## Wymagania po stronie aplikacji

- konto z uprawnieniem `inquiries.use`,
- API: `POST /api/login`, `POST /api/inquiries`, `GET /api/inquiries/{id}`,
  `POST /api/inquiries/{id}/replied`.

## Ograniczenia

- Załączniki (PDF, Excel) nie są wysyłane — do analizy idzie sam tekst maila.
- Maile zaszyfrowane (OpenPGP, S/MIME) nie są odczytywane.
- Jeśli zamkniesz okno odpowiedzi i wyślesz ją później ręcznie, zapytanie trzeba
  oznaczyć w aplikacji przyciskiem „Kopiuj i oznacz wysłane”.
