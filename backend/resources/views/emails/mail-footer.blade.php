{{-- Stopka maila pracownika (Moje konto → Stopka maila; dane z App\Services\Campaigns\MailFooter::viewData): logo SUPON,
     żółta pionowa kreska, imię i nazwisko, stanowisko, telefony, e-mail i strona; pod spodem firma z adresem i pasek
     „Sprawdź: …”. Tylko tabele i style inline (klienci poczty nie czytają <style>), wszystkie treści escapowane. Bez
     publicznego adresu aplikacji (obrazki by nie doszły): bez logo, zamiast ikon tekst „tel.”, „e-mail”, „www”.
     $mailFooter = MailFooter::viewData. --}}
@php
    $mf = $mailFooter;
    $mfBlue = '#0066aa';
    $mfYellow = '#f5c400';
    $mfText = '#1b2328';
    $mfMuted = '#5d6970';
    $mfFont = 'font-family:Arial,Helvetica,sans-serif;';
    $mfRows = [];
    if ($mf['phones'] !== []) {
        $mfRows[] = ['icon' => $mf['icon_phone'], 'label' => 'tel.', 'items' => $mf['phones']];
    }
    if ($mf['email'] !== null) {
        $mfRows[] = ['icon' => $mf['icon_mail'], 'label' => 'e-mail', 'items' => [['text' => $mf['email'], 'href' => $mf['email_href']]]];
    }
    if ($mf['website_url'] !== null) {
        $mfRows[] = ['icon' => $mf['icon_web'], 'label' => 'www', 'items' => [['text' => $mf['website_text'], 'href' => $mf['website_url']]]];
    }
@endphp
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;{{ $mfFont }}">
  <tr>
@if ($mf['logo_url'] !== null)
    <td valign="middle" style="padding:0 14px 0 0;">
      <img src="{{ $mf['logo_url'] }}" width="80" height="80" alt="{{ $mf['company'] }}" style="display:block;width:80px;height:80px;border:0;">
    </td>
@endif
    <td width="3" bgcolor="{{ $mfYellow }}" style="width:3px;background:{{ $mfYellow }};font-size:0;line-height:0;">&nbsp;</td>
    <td valign="middle" style="padding:2px 0 2px 14px;{{ $mfFont }}">
      <div style="font-size:15px;line-height:1.3;font-weight:700;color:{{ $mfBlue }};">{{ $mf['name'] }}</div>
@if ($mf['position'] !== null)
      <div style="font-size:12px;line-height:1.4;color:{{ $mfMuted }};">{{ $mf['position'] }}</div>
@endif
@if ($mfRows !== [])
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin-top:6px;">
@foreach ($mfRows as $row)
        <tr>
          <td valign="middle" style="padding:2px 8px 2px 0;">
@if ($row['icon'] !== null)
            <img src="{{ $row['icon'] }}" width="22" height="22" alt="{{ $row['label'] }}" style="display:block;width:22px;height:22px;border:0;">
@else
            <span style="font-size:12px;color:{{ $mfMuted }};{{ $mfFont }}">{{ $row['label'] }}</span>
@endif
          </td>
          <td valign="middle" style="padding:2px 0;font-size:13px;line-height:1.4;color:{{ $mfText }};{{ $mfFont }}">
@foreach ($row['items'] as $i => $item)
@if ($i > 0) / @endif
@if ($item['href'] !== null)
            <a href="{{ $item['href'] }}" style="color:{{ $mfText }};text-decoration:none;">{{ $item['text'] }}</a>
@else
            {{ $item['text'] }}
@endif
@endforeach
          </td>
        </tr>
@endforeach
      </table>
@endif
    </td>
  </tr>
</table>
@if ($mf['company'] !== '' || $mf['address'] !== '' || $mf['links'] !== [])
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin-top:10px;{{ $mfFont }}">
@if ($mf['company'] !== '' || $mf['address'] !== '')
  <tr>
    <td style="padding:0 0 4px;font-size:11px;line-height:1.4;color:{{ $mfMuted }};{{ $mfFont }}">{{ implode(' · ', array_filter([$mf['company'], $mf['address']], static fn (string $part): bool => $part !== '')) }}</td>
  </tr>
@endif
@if ($mf['links'] !== [])
  <tr>
    <td style="padding:4px 0 0;border-top:2px solid {{ $mfYellow }};font-size:12px;line-height:1.4;color:{{ $mfMuted }};{{ $mfFont }}">
      Sprawdź:
@foreach ($mf['links'] as $i => $link)
@if ($i > 0) | @endif
<a href="{{ $link['url'] }}" style="color:{{ $mfBlue }};font-weight:700;text-decoration:none;">{{ $link['label'] }}</a>
@endforeach
    </td>
  </tr>
@endif
</table>
@endif
