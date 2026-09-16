<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Przywraca właściciela plików w storage po poleceniu uruchomionym jako root.
 *
 * Polecenie artisan puszczone z konta root zostawia w storage pliki należące do roota (cache, dziennik, zdjęcia
 * i pliki produktów). Aplikacja — php-fpm, cron i kolejki — działa jako właściciel witryny i takich plików nie
 * nadpisze: 15.09.2026 pobranie cennika Bollé pominęło ~195 produktów („Permission denied” na pliku cache),
 * 16.09.2026 pobranie UVEX pomijało produkty („No such file or directory” — katalogu cache pod katalogiem roota
 * nie dało się utworzyć). Po każdym poleceniu uruchomionym jako root prostujemy to sami.
 *
 * Właściciela bierzemy z pliku artisan — to konto, do którego należy wdrożony kod (na serwerze: supon:psacln).
 */
final class StorageOwnership
{
    /** Katalogi, do których pisze aplikacja i polecenia artisan (względem katalogu aplikacji). */
    private const PATHS = ['storage', 'bootstrap/cache'];

    /**
     * @return int liczba plików i katalogów, którym zmieniono właściciela (0 = nie było czego prostować
     *             albo polecenie nie działa jako root)
     */
    public static function restoreAfterRoot(string $basePath): int
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return 0;
        }

        $artisan = $basePath.DIRECTORY_SEPARATOR.'artisan';
        $owner = @fileowner($artisan);
        $group = @filegroup($artisan);
        // kod należy do roota (albo nie da się odczytać) — nie ma komu oddać plików
        if ($owner === false || $group === false || $owner === 0) {
            return 0;
        }

        $fixed = 0;
        foreach (self::PATHS as $relative) {
            $path = $basePath.DIRECTORY_SEPARATOR.$relative;
            if (! is_dir($path)) {
                continue;
            }
            $fixed += self::fix($path, $owner, $group);
            foreach (self::entries($path) as $entry) {
                $fixed += self::fix($entry, $owner, $group);
            }
        }

        return $fixed;
    }

    /**
     * @return iterable<string> ścieżki wewnątrz katalogu; błąd odczytu katalogu przerywa tylko jego przeglądanie
     */
    private static function entries(string $path): iterable
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $item) {
                yield (string) $item;
            }
        } catch (Throwable) {
            return;
        }
    }

    private static function fix(string $path, int $owner, int $group): int
    {
        $changed = false;
        if (@fileowner($path) !== $owner) {
            $changed = @chown($path, $owner) || $changed;
        }
        if (@filegroup($path) !== $group) {
            $changed = @chgrp($path, $group) || $changed;
        }

        return $changed ? 1 : 0;
    }
}
