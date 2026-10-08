{{-- Treść karty produktu (lista i siatka): nazwa, kod, uwaga, opis, normy, cena i stan. $p = produkt, $b = blok. --}}
@if ($p['product_url'] !== null)
                          <a href="{{ $p['product_url'] }}" style="display:block;font-size:13px;line-height:1.3;font-weight:700;color:{{ $text }};text-decoration:none;">{{ $p['name'] }}</a>
@else
                          <div style="font-size:13px;line-height:1.3;font-weight:700;color:{{ $text }};">{{ $p['name'] }}</div>
@endif
@if ($p['code'] !== '')
                          <div style="font-size:11px;color:{{ $muted }};margin-top:4px;">Kod {{ $p['code'] }}</div>
@endif
@if (($p['sizes'] ?? null) !== null)
                          <div style="font-size:11px;color:{{ $muted }};margin-top:2px;">Rozmiary: {{ $p['sizes'] }}</div>
@endif
@if ($p['note'] !== null)
                          <div style="font-size:12px;color:{{ $text }};margin-top:4px;">{{ $p['note'] }}</div>
@endif
@if ($b['desc'] && $p['description'] !== null)
                          <div style="font-size:12.5px;line-height:1.45;color:{{ $text }};margin-top:6px;">{{ $p['description'] }}</div>
@endif
@if ($b['norms'] && $p['norms'] !== [])
                          <div style="margin-top:6px;">
@foreach ($p['norms'] as $norm)
                            <span style="display:inline-block;border:1px solid {{ $line }};border-radius:3px;padding:1px 6px;margin:0 4px 4px 0;font-size:11px;color:{{ $muted }};">{{ $norm }}</span>
@endforeach
                          </div>
@endif
@if ($p['price'] !== null)
                          <div style="margin-top:6px;">
@if ($p['price_before'] !== null)
                            <span style="font-size:12px;color:{{ $muted }};text-decoration:line-through;">{{ $p['price_before'] }}</span><br>
@endif
                            <span style="font-size:17px;font-weight:700;color:{{ $brand }};">{{ $p['price'] }}</span>
                            <span style="font-size:11px;color:{{ $muted }};">{{ $priceLabel ?? 'netto' }} / {{ $p['unit'] }}</span>
                          </div>
@endif
@if ($p['stock'] !== null)
                          <div style="font-size:11.5px;color:{{ $muted }};margin-top:4px;">{{ $p['stock'] }}</div>
@endif
