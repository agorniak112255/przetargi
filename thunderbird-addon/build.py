#!/usr/bin/env python3
"""Buduje plik XPI dodatku i publikuje go razem z informacją o wersji.

Uruchomienie (z dowolnego katalogu):

    python C:/xampp/htdocs/Przetargi/thunderbird-addon/build.py

Efekt:
  backend/public/dodatek/supon-przetargi.xpi   — plik do instalacji
  backend/public/dodatek/updates.json          — z niego Thunderbird czyta wersję
  thunderbird-addon/dist/supon-przetargi-<wersja>.xpi (kopia poza repozytorium)

Po wgraniu na serwer (deploy/server-update.sh) Thunderbird sam zauważy nową
wersję i zaktualizuje dodatek — bez odinstalowywania i bez ponownego logowania,
bo identyfikator dodatku się nie zmienia.
"""

from __future__ import annotations

import json
import os
import zipfile

ADDON_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_DIR = os.path.dirname(ADDON_DIR)
PUBLIC_DIR = os.path.join(REPO_DIR, 'backend', 'public', 'dodatek')
DIST_DIR = os.path.join(ADDON_DIR, 'dist')

# Do archiwum nie pakujemy rzeczy, które są tylko dla nas.
SKIP_FILES = {'README.md', 'build.py'}
SKIP_DIRS = {'dist', '.git'}

UPDATE_BASE = 'https://przetargi.supon.rzeszow.pl/dodatek'


def manifest() -> dict:
    with open(os.path.join(ADDON_DIR, 'manifest.json'), encoding='utf-8') as handle:
        return json.load(handle)


def build_xpi(target: str) -> None:
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for root, dirs, files in os.walk(ADDON_DIR):
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
            for name in files:
                path = os.path.join(root, name)
                inside = os.path.relpath(path, ADDON_DIR).replace(os.sep, '/')
                if inside in SKIP_FILES or inside.startswith('.git'):
                    continue
                archive.write(path, inside)


def write_updates(version: str, addon_id: str, min_version: str) -> None:
    updates = {
        'addons': {
            addon_id: {
                'updates': [
                    {
                        'version': version,
                        'update_link': UPDATE_BASE + '/supon-przetargi.xpi',
                        'applications': {
                            'gecko': {'strict_min_version': min_version},
                        },
                    },
                ],
            },
        },
    }
    path = os.path.join(PUBLIC_DIR, 'updates.json')
    with open(path, 'w', encoding='utf-8', newline='\n') as handle:
        json.dump(updates, handle, ensure_ascii=False, indent=2)
        handle.write('\n')


def main() -> None:
    data = manifest()
    version = data['version']
    gecko = data['browser_specific_settings']['gecko']

    os.makedirs(PUBLIC_DIR, exist_ok=True)
    os.makedirs(DIST_DIR, exist_ok=True)

    published = os.path.join(PUBLIC_DIR, 'supon-przetargi.xpi')
    build_xpi(published)
    build_xpi(os.path.join(DIST_DIR, 'supon-przetargi-' + version + '.xpi'))
    write_updates(version, gecko['id'], gecko.get('strict_min_version', '115.0'))

    print('Wersja:      ' + version)
    print('Plik XPI:    ' + published + ' (' + str(os.path.getsize(published)) + ' bajtów)')
    print('updates.json: ' + os.path.join(PUBLIC_DIR, 'updates.json'))


if __name__ == '__main__':
    main()
