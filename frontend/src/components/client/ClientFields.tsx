import type { ReactNode } from 'react'
import type { ClientRecord } from '../../lib/api'
import { fmtDateTime } from '../../lib/campaignFormat'
import { formatPln } from '../../lib/campaigns'
import { nipText } from './clientText'

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-[11px] text-slate-500">{label}</dt>
      <dd className="break-words">{children || '—'}</dd>
    </div>
  )
}

/**
 * Dane klienta z karty (ERP XL albo wpisane ręcznie) i osoby kontaktowe — rozwinięty wiersz na liście Klienci i sekcja
 * „Dane klienta” na karcie klienta. `owner` — pole „Opiekun w aplikacji” (np. z wyborem opiekuna przy clients.manage).
 */
export function ClientFields({ c, owner }: { c: ClientRecord; owner: ReactNode }) {
  const contacts = c.contacts ?? []
  return (
    <div className="grid gap-4 bg-slate-50 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
        <Field label="Pełna nazwa">{c.name}</Field>
        <Field label="Akronim w ERP XL">{c.acronym}</Field>
        <Field label="NIP">{nipText(c)}</Field>
        <Field label="REGON">{c.regon}</Field>
        <Field label="Ulica">{[c.street, c.address_line2].filter(Boolean).join(', ')}</Field>
        <Field label="Kod i miejscowość">{[c.postal_code, c.city].filter(Boolean).join(' ')}</Field>
        <Field label="Powiat / gmina">{[c.county, c.commune].filter(Boolean).join(' / ')}</Field>
        <Field label="Województwo / kraj">{[c.voivodeship, c.country].filter(Boolean).join(' / ')}</Field>
        <Field label="Telefon">{[c.phone, c.phone2].filter(Boolean).join(', ')}</Field>
        <Field label="Faks">{c.fax}</Field>
        <Field label="E-mail">
          {(c.emails ?? []).length > 0
            ? (c.emails ?? []).map((e) => (
                <a key={e} href={`mailto:${e}`} className="mr-2 text-blue-700 hover:underline">
                  {e}
                </a>
              ))
            : null}
        </Field>
        <Field label="Strona internetowa">{c.website}</Field>
        <Field label="Opiekun w ERP XL">
          {c.account_manager}
          {c.account_manager_email ? <span className="text-slate-500"> · {c.account_manager_email}</span> : null}
        </Field>
        <Field label="Opiekun w aplikacji">{owner}</Field>
        {c.xl_gid != null && (
          <>
            <Field label={`Zakupy netto ${c.sales_year ?? ''}`}>
              {formatPln(c.sales_net == null ? null : Number(c.sales_net))}
              {c.sale_documents ? <span className="text-slate-500"> · {c.sale_documents} dokumentów</span> : null}
            </Field>
            <Field label="Odczyt z ERP XL">
              {fmtDateTime(c.xl_synced_at)} <span className="text-slate-500">(kontrahent numer {c.xl_gid})</span>
            </Field>
          </>
        )}
      </dl>
      <div className="text-xs">
        <h3 className="mb-1 text-[11px] text-slate-500">Osoby kontaktowe ({contacts.length})</h3>
        {contacts.length === 0 ? (
          <p className="text-slate-500">Brak osób kontaktowych na karcie ERP XL.</p>
        ) : (
          <table className="w-full text-left">
            <thead>
              <tr className="border-b text-slate-500">
                <th className="py-1 pr-2 font-normal">Osoba</th>
                <th className="py-1 pr-2 font-normal">Stanowisko</th>
                <th className="py-1 pr-2 font-normal">E-mail</th>
                <th className="py-1 font-normal">Telefon</th>
              </tr>
            </thead>
            <tbody>
              {contacts.map((p, i) => (
                <tr key={i} className="border-b border-slate-200 align-top">
                  <td className="py-1 pr-2">{p.name ?? '—'}</td>
                  <td className="py-1 pr-2">{p.position ?? '—'}</td>
                  <td className="py-1 pr-2">
                    {p.email ? (
                      <a href={`mailto:${p.email}`} className="text-blue-700 hover:underline">
                        {p.email}
                      </a>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="py-1">{[p.phone, p.mobile].filter(Boolean).join(', ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
