<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dane dostępu</title>
</head>
<body style="margin:0;padding:0;background:#eef2f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f6;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#0f4c81 0%,#1d6fa5 55%,#0ea5e9 100%);padding:28px 32px;">
              <div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.75);font-weight:600;">
                Przetargi Supon
              </div>
              <div style="margin-top:8px;font-size:22px;line-height:1.3;font-weight:700;color:#ffffff;">
                Dane dostępu do systemu
              </div>
              <div style="margin-top:6px;font-size:14px;color:rgba(255,255,255,0.9);">
                Login i hasło do Twojego konta
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <p style="margin:0 0 16px;font-size:15px;line-height:1.55;">
                Cześć <strong>{{ $userName }}</strong>,
              </p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#334155;">
                Poniżej znajdziesz aktualne dane logowania do systemu Przetargi Supon.
                Hasło zostało ustawione razem z tą wiadomością — poprzednie hasło przestało działać.
              </p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                <tr>
                  <td style="padding:18px 20px;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;">
                      Login
                    </div>
                    <div style="margin-top:4px;font-size:16px;font-weight:700;color:#0f172a;word-break:break-all;">
                      {{ $login }}
                    </div>
                    <div style="margin-top:14px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;">
                      Hasło
                    </div>
                    <div style="margin-top:4px;font-size:16px;font-weight:700;color:#0f172a;font-family:Consolas,Menlo,monospace;word-break:break-all;">
                      {{ $password }}
                    </div>
                    <div style="margin-top:14px;font-size:13px;color:#475569;">
                      Rola: <strong style="color:#0f172a;">{{ $roleLabel }}</strong>
                    </div>
                  </td>
                </tr>
              </table>
              <div style="margin:28px 0 8px;text-align:center;">
                <a href="{{ $appUrl }}"
                   style="display:inline-block;background:#0f4c81;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">
                  Zaloguj się
                </a>
              </div>
              <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;text-align:center;">
                Jeśli przycisk nie działa, wklej link do przeglądarki:<br>
                <a href="{{ $appUrl }}" style="color:#0369a1;word-break:break-all;">{{ $appUrl }}</a>
              </p>
              <p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#64748b;">
                Zachowaj tę wiadomość w bezpiecznym miejscu albo usuń ją po zapisaniu hasła.
                Hasło zmienisz po zalogowaniu w zakładce „Moje konto”.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px 28px;">
              <div style="border-top:1px solid #e2e8f0;padding-top:16px;font-size:11px;line-height:1.5;color:#94a3b8;text-align:center;">
                Wiadomość wysłana automatycznie z systemu Przetargi Supon<br>
                {{ config('mail.from.address') }}
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
