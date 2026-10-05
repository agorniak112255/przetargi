{{-- Przycisk „Zapytaj o ofertę” (kampanie; oferta z ceną: ask_url null — bez niego), opcjonalny drugi przycisk z linkiem
     handlowca (np. do sklepu) i link „Zobacz produkt” (lista i siatka). $p = produkt. --}}
@if ($p['ask_url'] !== null)
                          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:8px;">
                            <tr>
                              <td align="center" bgcolor="{{ $brand }}" style="background:{{ $brand }};border-radius:4px;">
                                <a href="{{ $p['ask_url'] }}" style="display:block;padding:7px;color:#ffffff;text-decoration:none;font-size:12.5px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">Zapytaj o ofertę</a>
                              </td>
                            </tr>
                          </table>
@endif
@if (($p['link'] ?? null) !== null)
                          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:{{ $p['ask_url'] !== null ? 6 : 8 }}px;">
                            <tr>
                              <td align="center" bgcolor="{{ $p['link']['color'] }}" style="background:{{ $p['link']['color'] }};border-radius:4px;">
                                <a href="{{ $p['link']['url'] }}" style="display:block;padding:7px;color:#ffffff;text-decoration:none;font-size:12.5px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">{{ $p['link']['label'] }}</a>
                              </td>
                            </tr>
                          </table>
@endif
@if ($p['product_url'] !== null)
                          <div style="text-align:center;margin-top:6px;"><a href="{{ $p['product_url'] }}" style="font-size:12px;color:{{ $brand }};font-family:Arial,Helvetica,sans-serif;">Zobacz produkt</a></div>
@endif
