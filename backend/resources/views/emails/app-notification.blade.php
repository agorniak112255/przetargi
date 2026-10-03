<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
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
              <div style="margin-top:8px;font-size:20px;line-height:1.35;font-weight:700;color:#ffffff;">
                {{ $title }}
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <p style="margin:0 0 16px;font-size:15px;line-height:1.55;">
                Cześć <strong>{{ $recipientName }}</strong>,
              </p>
              <div style="margin:0 0 20px;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;line-height:1.6;color:#334155;white-space:pre-line;">{{ $body }}</div>
              <div style="margin:24px 0 8px;text-align:center;">
                <a href="{{ $actionUrl }}"
                   style="display:inline-block;background:#0f4c81;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">
                  Otwórz w aplikacji
                </a>
              </div>
              <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;text-align:center;">
                Jeśli przycisk nie działa, wklej link do przeglądarki:<br>
                <a href="{{ $actionUrl }}" style="color:#0369a1;word-break:break-all;">{{ $actionUrl }}</a>
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px 28px;">
              <div style="border-top:1px solid #e2e8f0;padding-top:16px;font-size:11px;line-height:1.5;color:#94a3b8;text-align:center;">
                Wiadomość wysłana automatycznie z systemu Przetargi Supon.<br>
                Co i jak Ci przypominać, ustawisz w <a href="{{ $settingsUrl }}" style="color:#0369a1;">Moim koncie</a>.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
