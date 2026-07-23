<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Test SMTP</title>
</head>
<body style="margin:0;padding:0;background:#eef2f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f6;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#0f4c81 0%,#1d6fa5 55%,#10b981 100%);padding:28px 32px;">
              <div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.75);font-weight:600;">
                Przetargi Supon · Administracja
              </div>
              <div style="margin-top:8px;font-size:22px;line-height:1.3;font-weight:700;color:#ffffff;">
                Test poczty wychodzącej
              </div>
              <div style="margin-top:6px;font-size:14px;color:rgba(255,255,255,0.92);">
                Potwierdzenie, że konfiguracja SMTP działa poprawnie
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 28px 8px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;margin-bottom:20px;">
                <tr>
                  <td style="padding:16px 18px;">
                    <div style="font-size:13px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:0.06em;">
                      Co oznacza ta wiadomość?
                    </div>
                    <div style="margin-top:6px;font-size:14px;line-height:1.55;color:#065f46;">
                      To automatyczny test z panelu <strong>Administracja → SMTP</strong>.
                      Jeśli ją widzisz, serwer poczty przyjął wiadomość i dostarczył ją na adres
                      <strong style="word-break:break-all;">{{ $recipient }}</strong>.
                    </div>
                  </td>
                </tr>
              </table>

              <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
                Parametry połączenia
              </div>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:8px;">
                <tr>
                  <td width="50%" style="padding:0 6px 12px 0;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;height:100%;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Host</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;word-break:break-all;">{{ $host }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td width="50%" style="padding:0 0 12px 6px;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Port</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;">{{ $port }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td width="50%" style="padding:0 6px 12px 0;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Login SMTP</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;word-break:break-all;">{{ $username }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td width="50%" style="padding:0 0 12px 6px;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">TLS / schemat</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;">{{ $scheme }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td width="50%" style="padding:0 6px 12px 0;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Nadawca</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;">{{ $fromName }}</div>
                          <div style="margin-top:2px;font-size:12px;color:#475569;word-break:break-all;">{{ $fromAddress }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td width="50%" style="padding:0 0 12px 6px;vertical-align:top;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Wysłano</div>
                          <div style="margin-top:4px;font-size:14px;font-weight:700;color:#0f172a;">{{ $sentAt }}</div>
                          <div style="margin-top:2px;font-size:12px;color:#475569;">sterownik: {{ $mailer }}</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <p style="margin:8px 0 0;font-size:13px;line-height:1.55;color:#64748b;">
                Ta wiadomość nie wymaga odpowiedzi. Służy wyłącznie do weryfikacji ustawień poczty
                używanych m.in. przy zaproszeniach do przetargów.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 28px 28px;">
              <div style="border-top:1px solid #e2e8f0;padding-top:16px;font-size:11px;line-height:1.5;color:#94a3b8;text-align:center;">
                System Przetargi Supon
                @if($appUrl)
                  <br><a href="{{ $appUrl }}" style="color:#0369a1;text-decoration:none;">{{ $appUrl }}</a>
                @endif
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
