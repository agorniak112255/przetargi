{{-- Karta produktu w układach listy (list, list_desc): kwadratowe zdjęcie z lewej, obok nazwa, opis, cena i przycisk.
     Tabele i style inline. $b = blok produktów z CampaignRenderer::viewBlocks (image = bok zdjęcia, desc, norms). --}}
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid {{ $line }};border-radius:6px;">
                      <tr>
                        <td width="{{ $b['image'] }}" valign="top" style="padding:10px 0 10px 10px;width:{{ $b['image'] }}px;">
@if ($p['image_url'] !== null)
@if ($p['product_url'] !== null)<a href="{{ $p['product_url'] }}" style="text-decoration:none;">@endif
                          <img src="{{ $p['image_url'] }}" width="{{ $b['image'] }}" alt="{{ $p['name'] }}" style="display:block;width:{{ $b['image'] }}px;max-width:100%;height:auto;border:0;border-radius:4px;">
@if ($p['product_url'] !== null)</a>@endif
@else
                          <div style="width:{{ $b['image'] }}px;height:{{ $b['image'] }}px;background:#f3f5f6;border-radius:4px;"></div>
@endif
                        </td>
                        <td valign="top" style="padding:10px;font-family:Arial,Helvetica,sans-serif;">
@include('emails.campaign-product-info')
@include('emails.campaign-product-actions')
                        </td>
                      </tr>
                    </table>
