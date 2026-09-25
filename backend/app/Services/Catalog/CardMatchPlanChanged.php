<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use DomainException;

/**
 * Propozycja łączenia rozmiarów albo rozdzielania zmieniła się od wczytania ekranu (inny skrót planu, inny rodzaj po
 * ponownym sprawdzeniu) — API odpowiada 409 z code „plan_changed”, ekran odświeża listę. Nic nie zostało zmienione.
 */
final class CardMatchPlanChanged extends DomainException {}
