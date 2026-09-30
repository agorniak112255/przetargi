{{-- Wersja tekstowa maila kampanii (text/plain — bez encji HTML, więc bez escapowania). --}}
{!! $company !!} — {!! $tagline !!}
{!! $validUntil !!}

@if ($heading !== null && trim($heading) !== '')
{!! $heading !!}

@endif
@if ($intro !== null && trim($intro) !== '')
{!! $intro !!}

@endif
@foreach ($products as $p)
* {!! $p['name'] !!}
@if ($p['code'] !== '')
  Kod {!! $p['code'] !!}
@endif
@if ($p['note'] !== null)
  {!! $p['note'] !!}
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
