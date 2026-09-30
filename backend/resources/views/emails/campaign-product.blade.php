{{-- Karta jednego produktu w mailu kampanii (tabele, style inline). Opcje układu z campaign.blade.php:
     $o = [img => szer. zdjęcia px (0 = bez zdjęcia), left => zdjęcie z lewej, desc => krótki opis, norms => normy,
     sale => plakietka −% i „Zostało”, compact => sama nazwa i cena (kafelki), big => większe litery (produkt wyróżniony)]. --}}
@php
    $nameSize = $o['big'] ? 17 : ($o['compact'] ? 12 : 13);
    $priceSize = $o['big'] || $o['sale'] ? 20 : ($o['compact'] ? 15 : 17);
@endphp
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid {{ $line }};border-radius:6px;">
                      <tr>
@if ($o['left'] && $o['img'] > 0)
                        <td width="{{ $o['img'] }}" valign="top" style="padding:10px 0 10px 10px;width:{{ $o['img'] }}px;">
@if ($p['image_url'] !== null)
@if ($p['product_url'] !== null)<a href="{{ $p['product_url'] }}" style="text-decoration:none;">@endif
                          <img src="{{ $p['image_url'] }}" width="{{ $o['img'] }}" alt="{{ $p['name'] }}" style="display:block;width:{{ $o['img'] }}px;max-width:100%;height:auto;border:0;border-radius:4px;">
@if ($p['product_url'] !== null)</a>@endif
@else
                          <div style="width:{{ $o['img'] }}px;height:{{ $o['img'] }}px;background:#f3f5f6;border-radius:4px;"></div>
@endif
                        </td>
@endif
                        <td valign="top" style="padding:{{ $o['compact'] ? 8 : 10 }}px;font-family:Arial,Helvetica,sans-serif;">
@if ($o['sale'] && $p['discount'] !== null)
                          <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:6px;">
                            <tr>
                              <td bgcolor="{{ $brand }}" style="background:{{ $brand }};border-radius:10px;padding:3px 9px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#ffffff;">−{{ $p['discount'] }}%</td>
                            </tr>
                          </table>
@endif
@if (! $o['left'] && $o['img'] > 0)
@if ($p['image_url'] !== null)
@if ($p['product_url'] !== null)<a href="{{ $p['product_url'] }}" style="text-decoration:none;">@endif
                          <img src="{{ $p['image_url'] }}" width="{{ $o['img'] }}" alt="{{ $p['name'] }}" style="display:block;width:100%;max-width:{{ $o['img'] }}px;height:auto;border:0;border-radius:4px;margin:0 auto 8px;">
@if ($p['product_url'] !== null)</a>@endif
@else
                          <div style="height:{{ min($o['img'], 170) }}px;background:#f3f5f6;border-radius:4px;margin-bottom:8px;"></div>
@endif
@endif
@if ($p['product_url'] !== null)
                          <a href="{{ $p['product_url'] }}" style="display:block;font-size:{{ $nameSize }}px;line-height:1.3;font-weight:700;color:{{ $text }};text-decoration:none;">{{ $p['name'] }}</a>
@else
                          <div style="font-size:{{ $nameSize }}px;line-height:1.3;font-weight:700;color:{{ $text }};">{{ $p['name'] }}</div>
@endif
@if ($p['code'] !== '' && ! $o['compact'])
                          <div style="font-size:11px;color:{{ $muted }};margin-top:4px;">Kod {{ $p['code'] }}</div>
@endif
@if ($p['note'] !== null && ! $o['compact'])
                          <div style="font-size:12px;color:{{ $text }};margin-top:4px;">{{ $p['note'] }}</div>
@endif
@if ($o['desc'] && $p['description'] !== null)
                          <div style="font-size:{{ $o['big'] ? 13.5 : 12.5 }}px;line-height:1.45;color:{{ $text }};margin-top:6px;">{{ $p['description'] }}</div>
@endif
@if ($o['norms'] && $p['norms'] !== [])
                          <div style="margin-top:6px;">
@foreach ($p['norms'] as $norm)
                            <span style="display:inline-block;border:1px solid {{ $line }};border-radius:3px;padding:1px 6px;margin:0 4px 4px 0;font-size:11px;color:{{ $muted }};">{{ $norm }}</span>
@endforeach
                          </div>
@endif
@if ($p['price'] !== null)
                          <div style="margin-top:6px;">
@if ($p['price_before'] !== null)
                            <span style="font-size:{{ $o['sale'] ? 13 : 12 }}px;color:{{ $muted }};text-decoration:line-through;">{{ $p['price_before'] }}</span><br>
@endif
                            <span style="font-size:{{ $priceSize }}px;font-weight:700;color:{{ $brand }};">{{ $p['price'] }}</span>
                            <span style="font-size:11px;color:{{ $muted }};">netto / {{ $p['unit'] }}</span>
                          </div>
@endif
@if ($o['sale'] && $p['stock_left'] !== null)
                          <div style="font-size:12.5px;font-weight:700;color:{{ $brand }};margin-top:4px;">{{ $p['stock_left'] }}</div>
@elseif ($p['stock'] !== null && ! $o['compact'])
                          <div style="font-size:11.5px;color:{{ $muted }};margin-top:4px;">{{ $p['stock'] }}</div>
@endif
                          <table role="presentation"@if (! $o['big']) width="100%"@endif cellspacing="0" cellpadding="0" border="0" style="margin-top:8px;">
                            <tr>
                              <td align="center" bgcolor="{{ $brand }}" style="background:{{ $brand }};border-radius:4px;">
                                <a href="{{ $p['ask_url'] }}" style="display:block;padding:{{ $o['compact'] ? '5px' : ($o['big'] ? '9px 22px' : '7px') }};color:#ffffff;text-decoration:none;font-size:{{ $o['compact'] ? 12 : 12.5 }}px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">Zapytaj o ofertę</a>
                              </td>
                            </tr>
                          </table>
@if ($p['product_url'] !== null && ! $o['compact'])
                          <div style="text-align:{{ $o['big'] ? 'left' : 'center' }};margin-top:6px;"><a href="{{ $p['product_url'] }}" style="font-size:12px;color:{{ $brand }};font-family:Arial,Helvetica,sans-serif;">Zobacz produkt</a></div>
@endif
                        </td>
                      </tr>
                    </table>
