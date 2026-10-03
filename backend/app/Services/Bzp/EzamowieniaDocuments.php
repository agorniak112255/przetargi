<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Dokumenty postępowania z ogłoszenia (okno szczegółów w zakładce Ogłoszenia i „Załóż przetarg z pozycjami”).
 *
 * Automatycznie pobieramy wyłącznie z platformy e-Zamówienia (ezamowienia.gov.pl): lista z publicznego API
 * (config bzp.ezamowienia.documents_url, w pamięci podręcznej), plik — adresem z aplikacji klienta e-Zamówień
 * (bzp.ezamowienia.download_url; ustalenia w config/bzp.php). Postępowanie jest „na e-Zamówieniach”, gdy adres strony
 * prowadzonego postępowania z ogłoszenia (parsed.procedure_url) wskazuje ten serwis i ogłoszenie ma identyfikator
 * postępowania (ocds_id). Inne platformy (platformazakupowa.pl, Logintrade, ezamawiajacy.pl…) — tylko informacja,
 * skąd pobrać pliki ręcznie; część z nich zabrania pobierania automatem (robots.txt), więc ich nie odpytujemy.
 *
 * Rodzaj dokumentu (kind) to podpowiedź z nazwy pliku i dokumentu, nie fakt — reguły w kindOf().
 */
final class EzamowieniaDocuments
{
    public const SOURCE = 'ezamowienia';

    public const KIND_DESCRIPTION = 'description';

    public const KIND_FORM = 'form';

    public const KIND_SWZ = 'swz';

    public const KIND_OTHER = 'other';

    /** formaty, które odczytuje import dokumentów przetargu (TenderDocumentController::analyze) */
    public const IMPORTABLE_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'];

    public function __construct(
        private readonly NoticeBhpLots $bhpLots = new NoticeBhpLots,
    ) {}

    /**
     * Dokumenty do okna szczegółów ogłoszenia.
     *
     * @return array{
     *     available: bool,
     *     source: ?string,
     *     items: list<array{id: string, name: string, file_name: string, published_at: ?string, kind: string, importable: bool, suggested: bool, lot_no: ?int, lot_bhp: ?bool}>,
     *     note: string
     * }
     */
    public function forNotice(ProcurementNotice $notice): array
    {
        $url = self::procedureUrl($notice);
        if (! self::isEzamowienia($notice)) {
            $host = $url !== null ? self::host($url) : null;

            return [
                'available' => false,
                'source' => null,
                'items' => [],
                'note' => $host !== null
                    ? 'Dokumenty są na platformie '.$host.' — pobierz je ze strony postępowania i dodaj tutaj.'
                    : 'Ogłoszenie nie podaje adresu strony postępowania — dokumenty trzeba uzyskać od zamawiającego, a potem dodać tutaj.',
            ];
        }

        try {
            $list = $this->listFor((string) $notice->ocds_id);
        } catch (RuntimeException $e) {
            return [
                'available' => false,
                'source' => self::SOURCE,
                'items' => [],
                'note' => 'Nie udało się pobrać listy dokumentów z platformy e-Zamówienia ('.$e->getMessage().'). '
                    .'Spróbuj ponownie za kilka minut albo pobierz pliki ze strony postępowania i dodaj je tutaj.',
            ];
        }

        // pakiety z towarami BHP: dokumenty innych pakietów (numer w nazwie) nie są podpowiadane do odczytu — tylko gdy
        // postępowanie ma kilka części i choć jedna jest BHP (inaczej nie ma czego zawężać)
        $lots = $this->bhpLots->forNotice($notice);
        $narrowToBhp = count($lots) > 1 && in_array(true, array_column($lots, 'bhp'), true);
        $skippedLots = 0;

        $items = [];
        $links = 0;
        $unreadable = 0;
        foreach ($list['documents'] as $document) {
            if ($document['file_name'] === null) {
                $links++;

                continue;
            }
            $kind = self::kindOf($document['name'], $document['file_name']);
            $importable = self::importable($document['file_name']);
            if (! $importable) {
                $unreadable++;
            }
            $lotNo = NoticeBhpLots::lotNumberOf($document['name'].' '.$document['file_name']);
            $lotNo = $lotNo !== null && isset($lots[$lotNo]) ? $lotNo : null;
            $lotBhp = $lotNo !== null ? $lots[$lotNo]['bhp'] : null;
            $suggested = $importable && in_array($kind, [self::KIND_DESCRIPTION, self::KIND_FORM], true);
            if ($suggested && $narrowToBhp && $lotBhp === false) {
                $suggested = false;
                $skippedLots++;
            }
            $items[] = [
                'id' => $document['id'],
                'name' => $document['name'],
                'file_name' => $document['file_name'],
                'published_at' => $document['published_at'],
                'kind' => $kind,
                'importable' => $importable,
                // podpowiedź zaznaczenia: opis przedmiotu zamówienia i formularz cenowy/ofertowy — z pakietów z towarami BHP
                'suggested' => $suggested,
                'lot_no' => $lotNo,
                'lot_bhp' => $lotBhp,
            ];
        }

        $fetched = PolishTime::format(CarbonImmutable::createFromTimestamp($list['fetched_at']));
        $notes = [];
        if ($items === []) {
            $notes[] = 'Na platformie e-Zamówienia nie ma opublikowanych plików tego postępowania (stan z '.$fetched.'). '
                .'Jeśli zamawiający podał dokumenty gdzie indziej, pobierz je i dodaj tutaj.';
        } else {
            $notes[] = 'Pliki opublikowane przez zamawiającego na platformie e-Zamówienia (stan z '.$fetched.').';
        }
        if ($skippedLots > 0) {
            $bhpNumbers = array_keys(array_filter($lots, static fn (array $lot): bool => $lot['bhp']));
            $notes[] = 'Zaznaczone są tylko dokumenty '.(count($bhpNumbers) === 1 ? 'pakietu ' : 'pakietów ')
                .implode(', ', $bhpNumbers).' — z towarami BHP; dokumentów pozostałych pakietów ('.$skippedLots
                .') nie zaznaczono (możesz je zaznaczyć).';
        }
        if ($unreadable > 0) {
            $notes[] = 'Plików, których aplikacja nie odczyta (inny format niż PDF, Word, Excel albo CSV): '.$unreadable
                .' — otwórz je na stronie postępowania.';
        }
        if ($links > 0) {
            $notes[] = 'Odnośników do innych stron zamiast plików: '.$links.' — są na stronie postępowania.';
        }

        return [
            'available' => $items !== [],
            'source' => self::SOURCE,
            'items' => $items,
            'note' => implode(' ', $notes),
        ];
    }

    /**
     * Pobiera jeden plik z listy dokumentów postępowania do pliku tymczasowego (wywołujący go usuwa).
     * Tylko pliki z listy tego postępowania, w formacie odczytywanym przez import, do bzp.ezamowienia.max_file_mb.
     *
     * @return array{path: string, id: string, name: string, file_name: string, extension: string, size: int, url: string}
     *
     * @throws RuntimeException komunikat dla człowieka
     */
    public function download(ProcurementNotice $notice, string $documentId): array
    {
        if (! self::isEzamowienia($notice)) {
            throw new RuntimeException('To postępowanie nie jest prowadzone na platformie e-Zamówienia — pobierz dokumenty ze strony postępowania i dodaj je ręcznie.');
        }
        $ocds = (string) $notice->ocds_id;
        try {
            $list = $this->listFor($ocds);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać listy dokumentów z platformy e-Zamówienia ('.$e->getMessage().') — spróbuj ponownie za kilka minut.', 0, $e);
        }
        $document = null;
        foreach ($list['documents'] as $candidate) {
            if ($candidate['id'] === $documentId) {
                $document = $candidate;
                break;
            }
        }
        if ($document === null || $document['file_name'] === null) {
            throw new RuntimeException('Tego dokumentu nie ma (już) na liście plików postępowania na platformie e-Zamówienia.');
        }
        $fileName = $document['file_name'];
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (! self::importable($fileName)) {
            throw new RuntimeException('Plik „'.$fileName.'” ma format, którego aplikacja nie odczytuje (PDF, Word, Excel albo CSV) — otwórz go na stronie postępowania.');
        }

        $config = (array) config('bzp.ezamowienia');
        $max = max(1, (int) ($config['max_file_mb'] ?? 25)) * 1024 * 1024;
        $url = str_replace(['{tenderId}', '{objectId}'], [rawurlencode($ocds), rawurlencode($document['id'])], (string) $config['download_url']);

        try {
            $response = Http::withUserAgent((string) config('bzp.user_agent'))
                ->connectTimeout(10)
                ->timeout((int) ($config['download_timeout'] ?? 90))
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Brak połączenia z platformą e-Zamówienia przy pobieraniu „'.$fileName.'”.', 0, $e);
        }
        $body = $response->toPsrResponse()->getBody();
        if (! $response->successful()) {
            $body->close();
            throw new RuntimeException('Platforma e-Zamówienia odpowiedziała błędem HTTP '.$response->status().' przy pobieraniu „'.$fileName.'”.');
        }
        $tooBig = 'Plik „'.$fileName.'” ma ponad '.(int) ($max / 1024 / 1024).' MB — pobierz go ze strony postępowania.';
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $max) {
            $body->close();
            throw new RuntimeException($tooBig);
        }

        $path = tempnam(sys_get_temp_dir(), 'ezam');
        $out = $path !== false ? fopen($path, 'wb') : false;
        if ($path === false || $out === false) {
            $body->close();
            if ($path !== false) {
                @unlink($path);
            }
            throw new RuntimeException('Nie można zapisać pobranego pliku na serwerze.');
        }
        $size = 0;
        $failure = null;
        // limit czasu całego pobrania (timeout żądania przy strumieniu dotyczy pojedynczego odczytu)
        $deadline = microtime(true) + max(1, (int) ($config['download_timeout'] ?? 90));
        try {
            // bez Content-Length (odpowiedź chunked) — limit rozmiaru liczony w trakcie pobierania
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $size += strlen($chunk);
                if ($size > $max) {
                    $failure = $tooBig;
                    break;
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    $failure = 'Nie można zapisać pobranego pliku na serwerze.';
                    break;
                }
                if (microtime(true) > $deadline) {
                    $failure = 'Pobieranie „'.$fileName.'” z platformy e-Zamówienia trwało za długo — spróbuj ponownie później.';
                    break;
                }
            }
        } catch (Throwable $e) {
            report($e);
            $failure = 'Przerwane pobieranie „'.$fileName.'” z platformy e-Zamówienia — spróbuj ponownie.';
        } finally {
            fclose($out);
            $body->close();
        }
        if ($failure !== null) {
            @unlink($path);
            throw new RuntimeException($failure);
        }
        if ($size === 0) {
            @unlink($path);
            throw new RuntimeException('Platforma e-Zamówienia zwróciła pusty plik „'.$fileName.'”.');
        }

        return [
            'path' => $path,
            'id' => $document['id'],
            'name' => $document['name'],
            'file_name' => $fileName,
            'extension' => $extension,
            'size' => $size,
            'url' => $url,
        ];
    }

    /** Postępowanie prowadzone na platformie e-Zamówienia (adres strony postępowania z ogłoszenia i identyfikator). */
    public static function isEzamowienia(ProcurementNotice $notice): bool
    {
        $url = self::procedureUrl($notice);

        return $url !== null
            && self::host($url) === (string) config('bzp.ezamowienia.host', 'ezamowienia.gov.pl')
            && preg_match('/^ocds-[A-Za-z0-9-]+$/', (string) $notice->ocds_id) === 1;
    }

    /**
     * Rodzaj dokumentu po nazwie (podpowiedź do zaznaczenia, nie fakt). Kolejność reguł:
     * 1) wyjaśnienia, zmiany, odpowiedzi, informacje z otwarcia, zawiadomienia → other (dotyczą SWZ, ale nie są nią),
     * 2) „opis przedmiotu zamówienia”, „OPZ”, „specyfikacja techniczna”, „szczegółowy opis” → description,
     * 3) „formularz cenowy / ofertowy / oferty / asortymentowy”, „kosztorys” → form,
     * 4) „SWZ”, „SIWZ”, „specyfikacja warunków zamówienia” → swz,
     * 5) reszta → other.
     */
    public static function kindOf(string $name, string $fileName): string
    {
        $text = mb_strtolower($name.' '.pathinfo($fileName, PATHINFO_FILENAME));
        $text = (string) preg_replace('/[_\s]+/u', ' ', $text);

        if (preg_match('/^\s*(?:wyjaśnieni|zmian|modyfikacj|odpowied|pytani|informacja z otwarcia|zawiadomieni|unieważnieni|ogłoszenie o zmianie|sprostowani)/u', $text) === 1) {
            return self::KIND_OTHER;
        }
        if (preg_match('/opis\w*\s+przedmiotu\s+zam|(?<![\p{L}\d])opz(?![\p{L}\d])|specyfikacj\w*\s+techniczn|szczegółow\w*\s+opis/u', $text) === 1) {
            return self::KIND_DESCRIPTION;
        }
        if (preg_match('/formularz\w*\s+(?:cenow|ofert|asortyment)|(?<![\p{L}\d])kosztorys/u', $text) === 1) {
            return self::KIND_FORM;
        }
        if (preg_match('/(?<![\p{L}\d])(?:swz|siwz)(?![\p{L}\d])|specyfikacj\w*\s+warunków\s+zamówienia/u', $text) === 1) {
            return self::KIND_SWZ;
        }

        return self::KIND_OTHER;
    }

    public static function importable(string $fileName): bool
    {
        return in_array(mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::IMPORTABLE_EXTENSIONS, true);
    }

    /**
     * Opublikowane dokumenty postępowania (bez usuniętych), z pamięci podręcznej albo z API.
     *
     * @return array{documents: list<array{id: string, name: string, file_name: ?string, published_at: ?string}>, fetched_at: int}
     *
     * @throws RuntimeException
     */
    private function listFor(string $ocds): array
    {
        $key = 'ezamowienia:documents:'.$ocds;
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['error']) && is_string($cached['error'])) {
            throw new RuntimeException($cached['error']);
        }
        if (is_array($cached) && isset($cached['documents'], $cached['fetched_at'])) {
            return $cached;
        }

        $config = (array) config('bzp.ezamowienia');
        try {
            $list = $this->fetchList($ocds, $config);
        } catch (RuntimeException $e) {
            Cache::put($key, ['error' => $e->getMessage()], max(1, (int) ($config['error_cache_seconds'] ?? 120)));
            throw $e;
        }
        Cache::put($key, $list, max(1, (int) ($config['cache_seconds'] ?? 3600)));

        return $list;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{documents: list<array{id: string, name: string, file_name: ?string, published_at: ?string}>, fetched_at: int}
     */
    private function fetchList(string $ocds, array $config): array
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('bzp.user_agent'))
                ->connectTimeout(10)
                ->timeout((int) ($config['timeout'] ?? 15))
                ->get((string) $config['documents_url'], ['tenderId' => $ocds]);
        } catch (ConnectionException) {
            throw new RuntimeException('brak połączenia');
        }
        if (! $response->successful()) {
            throw new RuntimeException('błąd HTTP '.$response->status());
        }
        $data = $response->json();
        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException('odpowiedź w nieznanym formacie');
        }

        $documents = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = is_string($row['objectId'] ?? null) ? trim($row['objectId']) : '';
            // identyfikator dokumentu = identyfikator postępowania + „_N” (inny → nie nasz, nie pobieramy)
            if ($id === '' || ! str_starts_with($id, $ocds.'_')) {
                continue;
            }
            if (($row['tenderDocumentState'] ?? null) !== 'Published' || ! empty($row['deleteDate'])) {
                continue;
            }
            $fileName = is_string($row['fileName'] ?? null) ? self::safeFileName($row['fileName']) : '';
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $published = is_string($row['publishedDate'] ?? null) ? self::isoMoment($row['publishedDate']) : null;
            $documents[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ($fileName !== '' ? $fileName : $id),
                'file_name' => $fileName !== '' ? $fileName : null,
                'published_at' => $published,
            ];
        }

        return ['documents' => $documents, 'fetched_at' => CarbonImmutable::now()->getTimestamp()];
    }

    private static function procedureUrl(ProcurementNotice $notice): ?string
    {
        $parsed = is_array($notice->parsed) ? $notice->parsed : [];
        $url = $parsed['procedure_url'] ?? null;

        return is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = mb_strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** nazwa pliku bez ścieżki i znaków sterujących (kolumna original_name ma 255 znaków) */
    private static function safeFileName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(basename(str_replace('\\', '/', $name)));
        if ($name === '.' || $name === '..') {
            return '';
        }
        if (mb_strlen($name) > 200) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190).($extension !== '' ? '.'.$extension : '');
        }

        return $name;
    }

    private static function isoMoment(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value)->utc()->toJSON();
        } catch (Throwable) {
            return null;
        }
    }
}
