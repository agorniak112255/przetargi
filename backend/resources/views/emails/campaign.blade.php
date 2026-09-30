{{-- Mail kampanii: tabele i style inline (klienci poczty nie czytają <style>), szer. 640. Wszystkie treści escapowane.
     Bloki w kolejności z CampaignRenderer::viewBlocks; podpis i stopka z wypisem zawsze na końcu. --}}
@php
    $text = '#1b2328';
    $muted = '#5d6970';
    $line = '#e2e7ea';
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f3;">
@if ($preheader !== null && $preheader !== '')
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#eef1f3;opacity:0;">{{ $preheader }}</div>
@endif
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef1f3;">
    <tr>
      <td align="center" style="padding:18px 12px;">
@if (($notice ?? null) !== null)
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;margin-bottom:10px;">
          <tr>
            <td style="background:#fff4d6;border:1px solid #e8c56a;border-radius:6px;padding:10px 14px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.4;color:#5c4400;">{{ $notice }}</td>
          </tr>
        </table>
@endif
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:6px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:{{ $text }};">
@foreach ($blocks as $b)
@switch($b['type'])
@case('header')
          {{-- nagłówek: logo albo nazwa firmy --}}
          <tr>
            <td style="padding:16px 24px;border-bottom:3px solid {{ $brand }};font-family:Arial,Helvetica,sans-serif;">
@if ($b['logo_url'] !== null)
              <img src="{{ $b['logo_url'] }}" width="{{ $b['logo_width'] }}" height="{{ $b['logo_height'] }}" alt="{{ $company }}" style="display:block;width:{{ $b['logo_width'] }}px;height:{{ $b['logo_height'] }}px;border:0;">
@else
              <div style="font-weight:700;font-size:18px;color:{{ $brand }};letter-spacing:0.02em;">{{ $company }}</div>
              <div style="font-size:11px;color:{{ $muted }};">{{ $tagline }}</div>
@endif
            </td>
          </tr>
@break
@case('content')
          <tr>
            <td style="padding:20px 24px 8px;font-family:Arial,Helvetica,sans-serif;">
@foreach ($b['parts'] as $part)
@if ($part['type'] === 'heading')
              <h1 style="margin:0 0 8px;font-size:21px;line-height:1.25;font-weight:700;color:{{ $text }};">{{ $part['text'] }}</h1>
@else
              <p style="margin:0 0 10px;color:{{ $text }};">{!! nl2br(e($part['text']), false) !!}</p>
@endif
@endforeach
            </td>
          </tr>
@break
@case('image')
          <tr>
            <td align="center" style="padding:8px 24px;">
@if ($b['link'] !== null)<a href="{{ $b['link'] }}" style="text-decoration:none;">@endif
              <img src="{{ $b['url'] }}" width="{{ $b['width'] }}" height="{{ $b['height'] }}" alt="{{ $b['alt'] }}" style="display:block;max-width:100%;height:auto;border:0;margin:0 auto;">
@if ($b['link'] !== null)</a>@endif
            </td>
          </tr>
@break
@case('button')
          <tr>
            <td align="center" style="padding:8px 24px 16px;">
              <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td align="center" bgcolor="{{ $brand }}" style="background:{{ $brand }};border-radius:4px;">
                    <a href="{{ $b['url'] }}" style="display:inline-block;padding:11px 24px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;font-family:Arial,Helvetica,sans-serif;border-radius:4px;">{{ $b['label'] }}</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
@break
@case('footer')
          <tr>
            <td style="padding:6px 24px 14px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:{{ $muted }};">{!! nl2br(e($b['text']), false) !!}</td>
          </tr>
@break
@case('products')
@php
    $layout = $b['layout'];
    $columns = $b['columns'];
    $cellWidth = (int) floor(100 / $columns);
    $base = ['img' => 170, 'left' => false, 'desc' => false, 'norms' => false, 'sale' => false, 'compact' => false, 'big' => false];
    // opcje karty produktu dla układu (klucze = Campaign::LAYOUTS); hero: tu opcje siatki pod produktem wyróżnionym
    $o = [...$base, ...([
        'grid2' => ['img' => 262],
        'list' => ['img' => 120, 'left' => true, 'desc' => true],
        'grid2_desc' => ['img' => 262, 'desc' => true],
        'list_desc' => ['img' => 120, 'left' => true, 'desc' => true, 'norms' => true],
        'sale' => ['img' => 262, 'sale' => true],
        'grid4' => ['img' => 120, 'compact' => true],
        'big' => ['img' => 544, 'desc' => true, 'norms' => true, 'big' => true],
    ][$layout] ?? [])];
@endphp
          {{-- ważność cen zawsze nad pozycjami --}}
          <tr>
            <td style="padding:12px 24px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:{{ $muted }};">{{ $validUntil }}</td>
          </tr>
          {{-- pozycje --}}
          <tr>
            <td style="padding:8px 18px 16px;">
@if ($layout === 'pricelist')
              {{-- cennik: tabela bez zdjęć --}}
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid {{ $line }};border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;">
                <tr>
                  <td style="padding:8px 10px;background:#f6f8f9;font-size:11px;font-weight:700;color:{{ $muted }};">Produkt</td>
                  <td align="right" style="padding:8px 10px;background:#f6f8f9;font-size:11px;font-weight:700;color:{{ $muted }};white-space:nowrap;">Cena netto</td>
                  <td align="right" style="padding:8px 10px;background:#f6f8f9;font-size:11px;font-weight:700;color:{{ $muted }};white-space:nowrap;">Na stanie</td>
                  <td style="padding:8px 10px;background:#f6f8f9;"></td>
                </tr>
@foreach ($b['products'] as $p)
                <tr>
                  <td valign="top" style="padding:8px 10px;border-top:1px solid {{ $line }};font-size:13px;color:{{ $text }};">
@if ($p['product_url'] !== null)
                    <a href="{{ $p['product_url'] }}" style="font-weight:700;color:{{ $text }};text-decoration:none;">{{ $p['name'] }}</a>
@else
                    <b>{{ $p['name'] }}</b>
@endif
@if ($p['code'] !== '')
                    <div style="font-size:11px;color:{{ $muted }};">Kod {{ $p['code'] }}</div>
@endif
@if ($p['note'] !== null)
                    <div style="font-size:12px;color:{{ $text }};">{{ $p['note'] }}</div>
@endif
                  </td>
                  <td valign="top" align="right" style="padding:8px 10px;border-top:1px solid {{ $line }};white-space:nowrap;">
@if ($p['price'] !== null)
@if ($p['price_before'] !== null)
                    <span style="font-size:11px;color:{{ $muted }};text-decoration:line-through;">{{ $p['price_before'] }}</span><br>
@endif
                    <span style="font-size:14px;font-weight:700;color:{{ $brand }};">{{ $p['price'] }}</span><br>
                    <span style="font-size:11px;color:{{ $muted }};">/ {{ $p['unit'] }}</span>
@else
                    <span style="font-size:12px;color:{{ $muted }};">—</span>
@endif
                  </td>
                  <td valign="top" align="right" style="padding:8px 10px;border-top:1px solid {{ $line }};font-size:12px;color:{{ $muted }};white-space:nowrap;">{{ $p['stock_qty'] ?? '—' }}</td>
                  <td valign="top" align="right" style="padding:8px 10px;border-top:1px solid {{ $line }};">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                      <tr>
                        <td bgcolor="{{ $brand }}" style="background:{{ $brand }};border-radius:4px;">
                          <a href="{{ $p['ask_url'] }}" style="display:block;padding:5px 10px;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;font-family:Arial,Helvetica,sans-serif;white-space:nowrap;">Zapytaj</a>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
@endforeach
              </table>
@else
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
@if ($b['hero'] !== null)
                {{-- produkt wyróżniony na całą szerokość --}}
                <tr>
                  <td colspan="{{ $columns }}" valign="top" style="padding:6px;">
@include('emails.campaign-product', ['p' => $b['hero'], 'o' => [...$base, 'img' => 240, 'left' => true, 'desc' => true, 'norms' => true, 'big' => true]])
                  </td>
                </tr>
@endif
@foreach ($b['rows'] as $row)
                <tr>
@foreach ($row as $p)
                  <td width="{{ $cellWidth }}%" valign="top" style="padding:6px;">
@include('emails.campaign-product', ['p' => $p, 'o' => $o])
                  </td>
@endforeach
@for ($i = count($row); $i < $columns; $i++)
                  <td width="{{ $cellWidth }}%" style="padding:6px;"></td>
@endfor
                </tr>
@endforeach
              </table>
@endif
            </td>
          </tr>
@break
@endswitch
@endforeach
          {{-- podpis nadawcy --}}
          <tr>
            <td style="padding:6px 24px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:{{ $text }};">
@if ($signature !== null)
              {!! nl2br(e($signature), false) !!}
@else
              <b>{{ $fromName }}</b>
@if ($fromAddress !== '')
              <br>{{ $fromAddress }}
@endif
@endif
            </td>
          </tr>
          {{-- stopka z wypisem --}}
          <tr>
            <td style="padding:12px 24px 16px;background:#f6f8f9;border-top:1px solid {{ $line }};font-family:Arial,Helvetica,sans-serif;font-size:11px;color:{{ $muted }};border-radius:0 0 6px 6px;">
              Otrzymujesz tę wiadomość jako klient {{ $company }}.
              <a href="{{ $unsubscribeUrl }}" style="color:{{ $muted }};">Wypisz mnie z mailingu</a>
@if ($footerNote !== '')
              · {{ $footerNote }}
@endif
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
