{{-- Wersja tekstowa maila kampanii (text/plain — bez encji HTML, więc bez escapowania). Bloki jak w wersji HTML. --}}
@if (($notice ?? null) !== null)
*** {!! $notice !!} ***

@endif
@foreach ($blocks as $b)
@switch($b['type'])
@case('header')
{!! $company !!} — {!! $tagline !!}

@break
@case('content')
@foreach ($b['parts'] as $part)
{!! $part['text'] !!}

@endforeach
@break
@case('image')
@if ($b['alt'] !== '')
[{!! $b['alt'] !!}]@if ($b['link'] !== null) {!! $b['link'] !!}@endif


@elseif ($b['link'] !== null)
{!! $b['link'] !!}

@endif
@break
@case('button')
{!! $b['label'] !!}: {!! $b['url'] !!}

@break
@case('footer')
{!! $b['text'] !!}

@break
@case('products')
{!! $validUntil !!}

@foreach ($b['products'] as $p)
* {!! $p['name'] !!}
@if ($p['code'] !== '')
  Kod {!! $p['code'] !!}
@endif
@if ($p['note'] !== null)
  {!! $p['note'] !!}
@endif
@if ($b['desc'] && ($p['description'] ?? null) !== null)
  {!! $p['description'] !!}
@endif
@if ($p['price'] !== null)
  {!! $p['price'] !!} netto / {!! $p['unit'] !!}@if ($p['price_before'] !== null) (wcześniej {!! $p['price_before'] !!})@endif

@endif
@if ($p['stock'] !== null)
  {!! $p['stock'] !!}
@endif
@if ($p['product_url'] !== null)
  Zobacz produkt: {!! $p['product_url'] !!}
@endif
@if ($p['ask_url'] !== '#')
  Zapytaj o ofertę: {!! $p['ask_url'] !!}
@endif
@if (($p['link'] ?? null) !== null)
  {!! $p['link']['label'] !!}: {!! $p['link']['url'] !!}
@endif

@endforeach
@break
@endswitch
@endforeach
--
@if ($signature !== null)
{!! $signature !!}
@else
{!! $fromName !!}
@if ($fromAddress !== '')
{!! $fromAddress !!}
@endif
@endif

Otrzymujesz tę wiadomość jako klient {!! $company !!}.
Wypisz mnie z mailingu: {!! $unsubscribeUrl !!}
@if ($footerNote !== '')
{!! $footerNote !!}
@endif
