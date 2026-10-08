<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\MailFooter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * „Moje konto → Stopka maila” (users.mail_footer): imię i nazwisko, stanowisko, telefony i e-mail pracownika. Zapisana
 * stopka zastępuje zwykły podpis pod ofertą i kampanią wysyłaną z aplikacji (MailFooter, CampaignRenderer). Działa także
 * bez skonfigurowanej skrzynki (PDF oferty). Bez zapisanej stopki odpowiedź podpowiada imię z konta i adres skrzynki
 * (saved = false), a podgląd pokazuje, jak stopka będzie wyglądać.
 */
class UserMailFooterController extends Controller
{
    private const NO_NEWLINE = '/[\r\n]/';

    /** Telefon: cyfry, spacje i + ( ) / - . */
    private const PHONE = '/^[0-9 +()\/.\-]*$/';

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'name' => ['nullable', 'string', 'max:100', 'not_regex:'.self::NO_NEWLINE],
            'position' => ['nullable', 'string', 'max:100', 'not_regex:'.self::NO_NEWLINE],
            'mobile' => ['nullable', 'string', 'max:40', 'regex:'.self::PHONE],
            'phone' => ['nullable', 'string', 'max:40', 'regex:'.self::PHONE],
            'email' => ['nullable', 'string', 'email', 'max:254'],
        ], [
            'name.string' => 'Imię i nazwisko wpisz jako tekst.',
            'name.max' => 'Imię i nazwisko może mieć najwyżej 100 znaków.',
            'name.not_regex' => 'Imię i nazwisko wpisz w jednej linii.',
            'position.string' => 'Stanowisko wpisz jako tekst.',
            'position.max' => 'Stanowisko może mieć najwyżej 100 znaków.',
            'position.not_regex' => 'Stanowisko wpisz w jednej linii.',
            'mobile.string' => 'Telefon komórkowy wpisz jako tekst.',
            'mobile.max' => 'Telefon komórkowy może mieć najwyżej 40 znaków.',
            'mobile.regex' => 'Telefon może mieć tylko cyfry, spacje i znaki + ( ) / - .',
            'phone.string' => 'Telefon stacjonarny wpisz jako tekst.',
            'phone.max' => 'Telefon stacjonarny może mieć najwyżej 40 znaków.',
            'phone.regex' => 'Telefon może mieć tylko cyfry, spacje i znaki + ( ) / - .',
            'email.string' => 'Wpisz poprawny adres e-mail.',
            'email.email' => 'Wpisz poprawny adres e-mail.',
            'email.max' => 'Adres e-mail może mieć najwyżej 254 znaki.',
        ]);

        $footer = MailFooter::fields($request->only(MailFooter::FIELDS));
        $filled = array_filter($footer, static fn (?string $value): bool => $value !== null);
        if ($filled !== [] && $footer['name'] === null) {
            throw ValidationException::withMessages(['name' => ['Wpisz imię i nazwisko.']]);
        }
        // wszystkie pola puste = stopka wyłączona (jak DELETE)
        $user->forceFill(['mail_footer' => $filled === [] ? null : $footer])->save();

        return response()->json($this->present($user));
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['mail_footer' => null])->save();

        return response()->json($this->present($user));
    }

    /** @return array{footer: array<string, string|null>, saved: bool, preview_html: string} */
    private function present(User $user): array
    {
        $saved = MailFooter::of($user);
        if ($saved !== null) {
            $footer = $saved;
        } else {
            // podpowiedzi: imię z konta, adres skrzynki „Moja poczta” albo konta; reszta do wpisania
            $from = trim((string) UserMailAccount::query()->where('user_id', $user->id)->value('from_address'));
            $footer = MailFooter::fields([
                'name' => (string) $user->name,
                'email' => $from !== '' ? $from : (string) $user->email,
            ]);
        }

        return [
            'footer' => $footer,
            'saved' => $saved !== null,
            'preview_html' => MailFooter::previewHtml($footer),
        ];
    }
}
