<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik witryny publicznej — bez konta i bez hasła u dostawcy (protekt.pl). Konto w panelu nadal istnieje,
 * bo trzyma harmonogram, historię przebiegów i rabaty, ale pole hasła jest dla niego bez sensu: formularz go
 * nie wymaga, a login() takiego łącznika nic nie robi.
 *
 * Nazwa konta (username) zostaje wymagana dla wszystkich łączników — służy za etykietę konta w panelu i w CLI.
 */
interface B2bPublicSite {}
