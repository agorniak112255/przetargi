<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Zapis ogłoszenia z Biuletynu (wynik BzpNoticeParser::parse) — jedno ogłoszenie = jeden wiersz po numerze
 * ogłoszenia z wersją. Pełny HTML jest zapisywany przy każdym ogłoszeniu (ponowny odczyt nową wersją parsera);
 * HTML ogłoszeń niepowiązanych z przetargiem czyści po 30 dniach system:prune (config bzp.html_retention_days).
 */
final class BzpNoticeStore
{
    /**
     * @param  array<string, mixed>  $record  kolumny procurement_notices
     */
    public function upsert(array $record): ProcurementNotice
    {
        $record['fetched_at'] = now();

        try {
            return $this->save($record);
        } catch (UniqueConstraintViolationException) {
            // równoległy zapis tego samego ogłoszenia — druga próba aktualizuje zapisany wiersz
            return $this->save($record);
        }
    }

    /**
     * Wersja parsera zapisanego ogłoszenia (null = ogłoszenia jeszcze nie ma) — bez wczytywania pełnego HTML.
     */
    public function storedVersion(string $noticeNumber): ?int
    {
        $version = ProcurementNotice::query()->where('notice_number', $noticeNumber)->value('parser_version');

        return $version !== null ? (int) $version : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function save(array $record): ProcurementNotice
    {
        $notice = ProcurementNotice::query()
            ->select(['id', 'notice_number'])
            ->where('notice_number', $record['notice_number'])
            ->first() ?? new ProcurementNotice;
        $notice->fill($record);
        $notice->save();

        return $notice;
    }
}
