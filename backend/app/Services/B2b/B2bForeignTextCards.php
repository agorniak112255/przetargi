<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego tylko część kart ma opis w obcym języku — inaczej niż B2bForeignLanguageSource, gdzie obcy
 * jest cały sklep. UVEX: część kart ma w miejscu opisu odnośnik do strony producenta po angielsku i opis bierzemy
 * stamtąd; reszta kart ma opis po polsku i tłumaczyć nie ma czego.
 *
 * Tłumaczenie zleca synchronizacja po zapisie karty (TranslateB2bProductTextJob), tak samo jak przy całym sklepie.
 * Zaległości nadrabia kolejny przebieg: dopóki karta ma niezmieniony tekst źródła, zlecenie powtarza się.
 */
interface B2bForeignTextCards
{
    /** Czy opis tej karty pochodzi z obcojęzycznego źródła (pytane po description()). */
    public function hasForeignDescription(B2bRemoteProduct $product): bool;
}
