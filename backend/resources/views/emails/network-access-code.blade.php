<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kod dostępu</title>
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
                Kod dostępu spoza sieci firmy
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <p style="margin:0 0 16px;font-size:15px;line-height:1.55;">
                Cześć <strong>{{ $userName }}</strong>,
              </p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#334155;">
                Ktoś zalogował się na Twoje konto spoza sieci firmy (adres <strong>{{ $ip }}</strong>) i poprosił o kod dostępu.
              </p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                <tr>
                  <td style="padding:20px;text-align:center;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;">
                      Kod
                    </div>
                    <div style="margin-top:6px;font-size:32px;font-weight:700;letter-spacing:0.25em;color:#0f172a;font-family:Consolas,Menlo,monospace;">
                      {{ $code }}
                    </div>
                    <div style="margin-top:8px;font-size:13px;color:#475569;">
                      ważny {{ $validMinutes }} minut
                    </div>
                  </td>
                </tr>
              </table>
              <p style="margin:20px 0 0;font-size:14px;line-height:1.55;color:#334155;">
                Po wpisaniu kodu konto będzie działać z tego adresu przez {{ $grantHours }} godziny — w przeglądarce i w dodatku do Thunderbirda.
              </p>
              <p style="margin:16px 0 0;font-size:13px;line-height:1.55;color:#b45309;">
                Jeśli to nie Ty — nie wpisuj kodu nigdzie, zmień hasło w zakładce „Moje konto” i powiadom administratora.
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
