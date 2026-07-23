<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zaproszenie do przetargu</title>
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
                Zaproszenie do współpracy
              </div>
              <div style="margin-top:6px;font-size:14px;color:rgba(255,255,255,0.9);">
                Otrzymałeś dostęp do przetargu w systemie
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <p style="margin:0 0 16px;font-size:15px;line-height:1.55;">
                Cześć <strong>{{ $inviteeName }}</strong>,
              </p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#334155;">
                <strong>{{ $inviterName }}</strong> zaprasza Cię do przetargu.
                Masz takie same uprawnienia pracy jak opiekun tego projektu.
              </p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                <tr>
                  <td style="padding:18px 20px;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;">
                      {{ $tenderNumber }}
                    </div>
                    <div style="margin-top:6px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.35;">
                      {{ $tenderTitle }}
                    </div>
                    @if($clientName)
                      <div style="margin-top:10px;font-size:13px;color:#475569;">
                        Klient: <strong style="color:#0f172a;">{{ $clientName }}</strong>
                      </div>
                    @endif
                    @if($deadline)
                      <div style="margin-top:4px;font-size:13px;color:#475569;">
                        Termin składania: <strong style="color:#0f172a;">{{ $deadline }}</strong>
                      </div>
                    @endif
                  </td>
                </tr>
              </table>
              @if($note)
                <div style="margin-top:18px;padding:14px 16px;border-left:3px solid #0ea5e9;background:#f0f9ff;border-radius:0 10px 10px 0;">
                  <div style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                    Wiadomość
                  </div>
                  <div style="font-size:14px;line-height:1.5;color:#0f172a;white-space:pre-wrap;">{{ $note }}</div>
                </div>
              @endif
              <div style="margin:28px 0 8px;text-align:center;">
                <a href="{{ $tenderUrl }}"
                   style="display:inline-block;background:#0f4c81;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">
                  Otwórz przetarg
                </a>
              </div>
              <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;text-align:center;">
                Jeśli przycisk nie działa, wklej link do przeglądarki:<br>
                <a href="{{ $tenderUrl }}" style="color:#0369a1;word-break:break-all;">{{ $tenderUrl }}</a>
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
