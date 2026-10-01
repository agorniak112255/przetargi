{{-- Siatka produktów (grid2, grid3, grid2_desc). Każdy rząd kart to trzy wiersze tabeli: zdjęcia, treść, przyciski —
     komórki jednego wiersza mają zawsze tę samą wysokość, więc karty w rzędzie są równe, a przyciski w jednej linii
     (także w Outlooku, który nie zna flexa ani height:100%). Ramka karty złożona z ramek trzech komórek.
     Zdjęcie w kwadratowym polu $b['image'] px: kwadrat z ProductImageThumbService::squareJpeg (na wąskim ekranie
     zmniejsza się z kolumną); starsze miniatury (dowolne proporcje, ≤160 px) mieszczą się dzięki max-height.
     Tylko width w atrybucie — bez height, żeby klient bez CSS nie rozciągał niekwadratowych zdjęć.
     $b = blok produktów z CampaignRenderer::viewBlocks. --}}
@php
    $columns = $b['columns'];
    $side = $b['image'];
    $cellWidth = (int) floor(100 / $columns);
    $edge = '1px solid '.$line;
@endphp
              {{-- table-layout:fixed: kolumny zawsze równe, także na wąskim ekranie (bez tego szerokość ciągnie zawartość) --}}
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout:fixed;">
@foreach ($b['rows'] as $r => $row)
@php
    $slots = array_pad($row, $columns, null);
@endphp
@if ($r > 0)
                <tr><td colspan="{{ $columns * 2 - 1 }}" height="12" style="height:12px;font-size:0;line-height:0;">&nbsp;</td></tr>
@endif
                <tr>
@foreach ($slots as $i => $p)
@if ($i > 0)
                  <td width="12" style="width:12px;font-size:0;line-height:0;">&nbsp;</td>
@endif
@if ($p === null)
                  <td width="{{ $cellWidth }}%"></td>
@else
                  <td width="{{ $cellWidth }}%" align="center" valign="middle" style="padding:10px 10px 8px;border-top:{{ $edge }};border-left:{{ $edge }};border-right:{{ $edge }};border-radius:6px 6px 0 0;">
@if ($p['image_url'] !== null)
@if ($p['product_url'] !== null)<a href="{{ $p['product_url'] }}" style="text-decoration:none;">@endif
                    <img src="{{ $p['image_url'] }}" width="{{ $side }}" alt="{{ $p['name'] }}" style="display:block;margin:0 auto;width:auto;height:auto;max-width:100%;max-height:{{ $side }}px;border:0;border-radius:4px;">
@if ($p['product_url'] !== null)</a>@endif
@else
                    <div style="width:{{ $side }}px;max-width:100%;height:{{ $side }}px;margin:0 auto;background:#f3f5f6;border-radius:4px;"></div>
@endif
                  </td>
@endif
@endforeach
                </tr>
                <tr>
@foreach ($slots as $i => $p)
@if ($i > 0)
                  <td width="12" style="width:12px;font-size:0;line-height:0;">&nbsp;</td>
@endif
@if ($p === null)
                  <td width="{{ $cellWidth }}%"></td>
@else
                  <td width="{{ $cellWidth }}%" valign="top" style="padding:0 10px;border-left:{{ $edge }};border-right:{{ $edge }};font-family:Arial,Helvetica,sans-serif;word-wrap:break-word;overflow-wrap:break-word;">
@include('emails.campaign-product-info')
                  </td>
@endif
@endforeach
                </tr>
                <tr>
@foreach ($slots as $i => $p)
@if ($i > 0)
                  <td width="12" style="width:12px;font-size:0;line-height:0;">&nbsp;</td>
@endif
@if ($p === null)
                  <td width="{{ $cellWidth }}%"></td>
@else
                  <td width="{{ $cellWidth }}%" valign="bottom" style="padding:0 10px 10px;border-bottom:{{ $edge }};border-left:{{ $edge }};border-right:{{ $edge }};border-radius:0 0 6px 6px;font-family:Arial,Helvetica,sans-serif;">
@include('emails.campaign-product-actions')
                  </td>
@endif
@endforeach
                </tr>
@endforeach
              </table>
