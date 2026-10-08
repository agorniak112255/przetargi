{{-- Stopka maila pracownika w wersji tekstowej (text/plain — bez escapowania), te same dane co emails/mail-footer.
     $mailFooter = App\Services\Campaigns\MailFooter::viewData. --}}
{!! $mailFooter['name'] !!}
@if ($mailFooter['position'] !== null)
{!! $mailFooter['position'] !!}
@endif
@if ($mailFooter['phones'] !== [])
tel. {!! implode(' / ', array_column($mailFooter['phones'], 'text')) !!}
@endif
@if ($mailFooter['email'] !== null)
e-mail: {!! $mailFooter['email'] !!}
@endif
@if ($mailFooter['website_text'] !== null)
www: {!! $mailFooter['website_text'] !!}
@endif
@if ($mailFooter['company'] !== '' || $mailFooter['address'] !== '')
{!! implode(' · ', array_filter([$mailFooter['company'], $mailFooter['address']], static fn (string $part): bool => $part !== '')) !!}
@endif
@if ($mailFooter['links'] !== [])
Sprawdź: {!! implode(' | ', array_map(static fn (array $l): string => $l['label'].' '.$l['url'], $mailFooter['links'])) !!}
@endif
