#!/usr/bin/env python3
"""Sprawdza pliki dodatku pod kątem wywołań, których nigdzie nie ma.

Po co: pliki dodatku ładują się do jednej przestrzeni nazw (manifest: common.js,
tags.js, background.js), więc funkcja z jednego pliku jest widoczna w drugim.
Ceną jest to, że literówka albo wywołanie funkcji, której nikt nie napisał,
wychodzi dopiero u handlowca — i to po cichu, bo wyjątek zwykle wpada w catch.
Tak przepadły trzy błędy z rzędu: `skipFolder()` bez definicji, `ensureTag(api…)`
z funkcją HTTP zamiast obiektu znaczników i brak uprawnienia do listy znaczników.

Jak: zbieramy nazwy zadeklarowane na najwyższym poziomie we wszystkich plikach
dodatku, wpisujemy je do konfiguracji jako zmienne globalne i puszczamy oxlint
z regułą `no-undef`. Zostaje dokładnie to, czego nie ma nigdzie.

Uruchomienie:
    python C:/xampp/htdocs/Przetargi/thunderbird-addon/lint.py

Zwraca 1, gdy coś znajdzie — dlatego build.py nie zbuduje wtedy XPI.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import tempfile

ADDON_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_DIR = os.path.dirname(ADDON_DIR)

# oxlint stoi w zależnościach frontendu — nie dokładamy dodatkowi własnych.
OXLINT = os.path.join(REPO_DIR, 'frontend', 'node_modules', '.bin', 'oxlint.cmd')
OXLINT_UNIX = os.path.join(REPO_DIR, 'frontend', 'node_modules', '.bin', 'oxlint')

SKIP_DIRS = {'dist', '.git', 'icons'}

# Deklaracje najwyższego poziomu: „function x(”, „class X”, „const X =”.
TOP_LEVEL = re.compile(
    r'^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)'
    r'|^class\s+([A-Za-z_$][\w$]*)'
    r'|^(?:const|let|var)\s+([A-Za-z_$][\w$]*)',
    re.M,
)


def addon_files() -> list[str]:
    out = []
    for name in sorted(os.listdir(ADDON_DIR)):
        if name.endswith('.js') and name not in SKIP_DIRS:
            out.append(name)

    return out


def shared_names(files: list[str]) -> list[str]:
    names = set()
    for name in files:
        with open(os.path.join(ADDON_DIR, name), encoding='utf-8') as handle:
            for found in TOP_LEVEL.finditer(handle.read()):
                names.add(found.group(1) or found.group(2) or found.group(3))

    return sorted(names)


def oxlint_path() -> str | None:
    # Ścieżkę można wskazać zmienną OXLINT_BIN — przydaje się, gdy sprawdzamy
    # kopię katalogu poza repozytorium albo gdy frontend stoi gdzie indziej.
    override = os.environ.get('OXLINT_BIN', '')
    if override != '' and os.path.exists(override):
        return override

    for candidate in (OXLINT, OXLINT_UNIX):
        if os.path.exists(candidate):
            return candidate

    return None


def main() -> int:
    files = addon_files()
    if files == []:
        print('Nie ma czego sprawdzać.')

        return 0

    binary = oxlint_path()
    if binary is None:
        print('Pomijam sprawdzanie: brak oxlint (zainstaluj zależności frontendu: npm ci).')

        return 0

    config = {
        'env': {'browser': True, 'es2023': True, 'webextensions': True},
        # Nazwy z innych plików dodatku są globalne w czasie działania.
        'globals': {name: 'readonly' for name in shared_names(files)},
        'rules': {'no-undef': 'error'},
    }
    config['globals'].update({'browser': 'readonly', 'messenger': 'readonly'})

    with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8') as handle:
        json.dump(config, handle, ensure_ascii=False)
        config_path = handle.name

    try:
        result = subprocess.run(
            [binary, '--config', config_path, *files],
            cwd=ADDON_DIR,
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace',
        )
    finally:
        os.unlink(config_path)

    output = (result.stdout or '') + (result.stderr or '')
    problems = [line for line in output.splitlines() if ' error ' in line]

    if problems != []:
        print('Wywołania bez definicji (XPI nie powstanie):')
        for line in problems:
            print('  ' + line.strip())

        return 1

    print('Sprawdzenie nazw: czysto (' + str(len(files)) + ' plików).')

    return 0


if __name__ == '__main__':
    sys.exit(main())
