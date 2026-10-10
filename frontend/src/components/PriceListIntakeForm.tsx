import { useMemo, useState } from 'react'
import { ApiError } from '../lib/api'
import { applyCheckboxRange } from '../lib/checkboxRange'
import {
  brandKeyOf,
  createIntake,
  intakeOf,
  updateIntake,
  type FilePriceListRow,
  type IntakePayload,
  type IntakeView,
} from '../lib/priceListIntake'
import {
  appendSites,
  ENRICHMENT_SITES_MAX,
  parseSiteLines,
  siteKey,
  type EnrichmentSitesMode,
  type SearchSiteOption,
} from '../lib/priceListSources'

/** Poniżej tylu zaindeksowanych stron importer może nie znaleźć kart na stronie producenta. */
const WEAK_INDEX_PAGES = 20

const WEAK_INDEX_NOTE = 'Strona słabo zaindeksowana — importer może nie znaleźć kart'

/** Tryb stron dostawców w cenniku z importerem — opisu z reszty internetu taki cennik nie pobiera. */
const INTAKE_MODE_LABEL: Record<EnrichmentSitesMode, string> = {
  first: 'Najpierw te strony, potem inne strony z listy „Strony wyszukiwarka”',
  only: 'Tylko strona producenta i te strony',
}

const NOTES_PLACEHOLDER = 'np. arkusz 2 to akcesoria, ceny w EUR, kod w kolumnie B'

type BrandRow = {
  /** Wiersz marki producenta cennika — klucz idzie za polem „Producent”. */
  primary: boolean
  /** Nazwa marki (dla dodatkowych marek) albo zapisany klucz marki przy edycji. */
  name: string
  text: string
}

type FieldErrors = Record<'manufacturer' | 'version' | 'hosts' | 'sites' | 'mode' | 'discount' | 'notes', string[]> & {
  general: string
}

const NO_ERRORS: FieldErrors = {
  manufacturer: [],
  version: [],
  hosts: [],
  sites: [],
  mode: [],
  discount: [],
  notes: [],
  general: '',
}

function errorsFrom(ex: unknown): FieldErrors {
  const out: FieldErrors = { ...NO_ERRORS, manufacturer: [], version: [], hosts: [], sites: [], mode: [], discount: [], notes: [] }
  if (ex instanceof ApiError && ex.status === 422) {
    const errors = ex.body.errors as Record<string, string[]> | undefined
    if (errors && typeof errors === 'object') {
      for (const [field, messages] of Object.entries(errors)) {
        const list = Array.isArray(messages) ? messages.map(String) : [String(messages)]
        if (field.startsWith('manufacturer_hosts')) out.hosts.push(...list)
        else if (field.startsWith('manufacturer')) out.manufacturer.push(...list)
        else if (field.startsWith('version')) out.version.push(...list)
        else if (field.startsWith('enrichment_sites_mode')) out.mode.push(...list)
        else if (field.startsWith('enrichment_sites')) out.sites.push(...list)
        else if (field.startsWith('discount_percent')) out.discount.push(...list)
        else if (field.startsWith('importer_notes')) out.notes.push(...list)
        else out.general = [out.general, ...list].filter(Boolean).join(' ')
      }
      return out
    }
  }
  out.general = ex instanceof Error ? ex.message : 'Nie udało się zapisać cennika'

  return out
}

function initialBrands(initial: IntakeView | null): BrandRow[] {
  if (!initial) return [{ primary: true, name: '', text: '' }]
  const primaryKey = brandKeyOf(initial.manufacturer)
  const hosts = initial.manufacturer_hosts ?? {}
  const rows: BrandRow[] = [{ primary: true, name: '', text: (hosts[primaryKey] ?? []).join('\n') }]
  for (const [key, list] of Object.entries(hosts)) {
    if (key === primaryKey) continue
    rows.push({ primary: false, name: key, text: (list ?? []).join('\n') })
  }

  return rows
}

function parseDiscount(raw: string): number | null | 'invalid' {
  const t = raw.trim().replace(',', '.')
  if (t === '') return null
  const v = Number(t)
  if (!Number.isFinite(v) || v < 0 || v >= 100) return 'invalid'

  return v
}

/** Odpowiedź 409: cennik tego producenta już jest. */
type Conflict = { message: string; priceListId: number | null }

type Props = {
  /** null = nowy cennik; IntakeView = edycja ustawień cennika nowym sposobem. */
  initial: IntakeView | null
  manufacturerOptions: string[]
  searchSites: SearchSiteOption[] | null
  searchSitesErr: string
  onNeedSearchSites: () => void
  /** Wiersz zakładki „Z pliku” o tym id (przy 409 — czy to dawny cennik z pliku). */
  findList: (priceListId: number) => FilePriceListRow | undefined
  onShowList: (priceListId: number) => void
  /** „Przyjmuj ten cennik nowym sposobem”: rodzic otwiera formularz w trybie edycji z ustawieniami tego cennika. */
  onSwitchExisting: (intake: IntakeView) => void
  onSaved: (intake: IntakeView, kind: 'created' | 'updated' | 'switched') => void
  onClose: () => void
  /**
   * Strony producenta innych marek niż marka cennika działają w całej aplikacji — przypisuje je tylko osoba
   * z uprawnieniem „Strony wyszukiwarka” (serwer: 403). Bez niego formularz pokazuje i wysyła tylko markę cennika.
   */
  canAssignOtherBrands: boolean
}

/**
 * Formularz cennika z pliku nowym sposobem: producent, wersja, strona producenta (z listy „Strony wyszukiwarka”
 * z liczbą zaindeksowanych stron), strony dostawców z długimi opisami i tryb, upust na cały cennik, ceny sugerowane,
 * uwagi dla programisty. Zapis: POST /price-lists/intake (nowy) albo PATCH /price-lists/{id}/intake (edycja).
 */
export function PriceListIntakeForm({
  initial,
  manufacturerOptions,
  searchSites,
  searchSitesErr,
  onNeedSearchSites,
  findList,
  onShowList,
  canAssignOtherBrands,
  onSwitchExisting,
  onSaved,
  onClose,
}: Props) {
  const editing = initial !== null
  // edycja dawnego cennika (status legacy): zapis włącza nowy sposób
  const switching = initial?.status === 'legacy'
  const [manufacturer, setManufacturer] = useState(initial?.manufacturer ?? '')
  const [version, setVersion] = useState(initial?.version ?? '')
  const [brands, setBrands] = useState<BrandRow[]>(() => initialBrands(initial))
  const [sitesText, setSitesText] = useState((initial?.enrichment_sites ?? []).join('\n'))
  const [mode, setMode] = useState<EnrichmentSitesMode>(initial?.enrichment_sites_mode ?? 'first')
  const [discount, setDiscount] = useState(
    initial?.discount_percent != null ? String(initial.discount_percent).replace('.', ',') : '',
  )
  const [suggested, setSuggested] = useState(Boolean(initial?.suggested_prices))
  const [notes, setNotes] = useState(initial?.importer_notes ?? '')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>(NO_ERRORS)
  const [conflict, setConflict] = useState<Conflict | null>(null)
  /** Otwarty wybór z listy „Strony wyszukiwarka”: indeks wiersza marki albo 'sites' (strony dostawców). */
  const [picker, setPicker] = useState<number | 'sites' | null>(null)

  const sites = useMemo(() => parseSiteLines(sitesText), [sitesText])
  const tooManySites = sites.length > ENRICHMENT_SITES_MAX
  const discountValue = parseDiscount(discount)

  const siteByKey = useMemo(() => {
    const map = new Map<string, SearchSiteOption>()
    for (const s of searchSites ?? []) map.set(siteKey(s.host), s)
    return map
  }, [searchSites])

  function brandName(row: BrandRow): string {
    return row.primary ? manufacturer.trim() : row.name.trim()
  }

  function brandKey(row: BrandRow): string {
    return brandKeyOf(brandName(row))
  }

  /** Strony z listy przypisane do marki — podpowiedź „jednym kliknięciem”. */
  function suggestedHosts(row: BrandRow): SearchSiteOption[] {
    const name = brandName(row).toLowerCase()
    const key = brandKey(row)
    if (name === '' || !searchSites) return []
    const present = new Set(parseSiteLines(row.text).map(siteKey))

    return searchSites
      // tylko marki przypisane ręcznie albo z konfiguracji — wykryte automatem bywają sklepami
      .filter((s) => (s.assigned_manufacturers ?? []).some((m) => m.trim().toLowerCase() === name || brandKeyOf(m) === key))
      .filter((s) => !present.has(siteKey(s.host)))
      .slice(0, 6)
  }

  function updateBrand(index: number, patch: Partial<BrandRow>) {
    setBrands((prev) => prev.map((b, i) => (i === index ? { ...b, ...patch } : b)))
    if (errors.hosts.length > 0) setErrors((prev) => ({ ...prev, hosts: [] }))
  }

  function payload(): IntakePayload {
    const hosts: Record<string, string[]> = {}
    for (const row of brands) {
      if (!row.primary && !canAssignOtherBrands) continue
      const key = brandKey(row)
      const list = parseSiteLines(row.text).map(siteKey).filter((h) => h !== '')
      if (key === '' || list.length === 0) continue
      hosts[key] = [...new Set([...(hosts[key] ?? []), ...list])]
    }

    return {
      manufacturer: manufacturer.trim(),
      version: version.trim(),
      manufacturer_hosts: hosts,
      enrichment_sites: sites,
      enrichment_sites_mode: mode,
      suggested_prices: suggested,
      importer_notes: notes.trim(),
      discount_percent: discountValue === 'invalid' ? null : discountValue,
    }
  }

  const missing = manufacturer.trim() === '' || version.trim() === ''
  const invalid = missing || tooManySites || discountValue === 'invalid'

  async function save() {
    setSaving(true)
    setErrors(NO_ERRORS)
    setConflict(null)
    try {
      if (initial) {
        const res = await updateIntake(initial.id, payload())
        onSaved(res.price_list, switching ? 'switched' : 'updated')
      } else {
        const res = await createIntake(payload())
        onSaved(res.price_list, 'created')
      }
    } catch (ex) {
      if (ex instanceof ApiError && ex.status === 409) {
        const id = Number(ex.body.price_list_id)
        setConflict({ message: ex.message, priceListId: Number.isFinite(id) && id > 0 ? id : null })
      } else {
        setErrors(errorsFrom(ex))
      }
    } finally {
      setSaving(false)
    }
  }

  const conflictList = conflict?.priceListId != null ? findList(conflict.priceListId) : undefined
  const pickerRow = typeof picker === 'number' ? brands[picker] : undefined

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="intake-form-title"
      onClick={() => {
        if (!saving) onClose()
      }}
    >
      <div
        className="flex max-h-[92vh] w-full max-w-4xl flex-col rounded-xl bg-white p-4 text-xs shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="intake-form-title" className="text-sm font-semibold text-slate-800">
          {editing
            ? `${switching ? 'Przyjmij cennik nowym sposobem' : 'Ustawienia cennika'} — ${initial.manufacturer} / ${initial.version}`
            : 'Dodaj cennik z pliku'}
        </p>
        <p className="mt-1 text-slate-500">
          Kolejność: cennik → plik → importer. Po zapisie dodasz plik przy cenniku; programista przygotuje importer, który
          przypisze każdej karcie stronę wyrobu (producenta, a gdy producent nie ma wyrobu — dostawcy). Karta bez strony
          z importera nie dostaje opisu z internetu — trafia do „Do przeglądu”, gdzie wskazuje się adres ręcznie.
        </p>

        <div className="mt-3 grid min-h-0 flex-1 gap-4 overflow-auto pr-1 lg:grid-cols-2">
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="block">
                <span className="font-medium text-slate-700">Producent *</span>
                {editing ? (
                  <p className="mt-1 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-slate-700">
                    {initial.manufacturer}
                  </p>
                ) : (
                  <>
                    <input
                      className={`mt-1 w-full rounded border px-2 py-1.5 ${errors.manufacturer.length > 0 ? 'border-red-400' : 'border-slate-300'}`}
                      value={manufacturer}
                      onChange={(e) => {
                        setManufacturer(e.target.value)
                        setConflict(null)
                        if (errors.manufacturer.length > 0) setErrors((prev) => ({ ...prev, manufacturer: [] }))
                      }}
                      list="intake-manufacturers"
                      placeholder="np. MAPA"
                      disabled={saving}
                      autoFocus
                    />
                    <datalist id="intake-manufacturers">
                      {manufacturerOptions.map((m) => (
                        <option key={m} value={m} />
                      ))}
                    </datalist>
                  </>
                )}
                {errors.manufacturer.map((e) => (
                  <span key={e} className="mt-0.5 block text-red-700">
                    {e}
                  </span>
                ))}
              </label>
              <label className="block">
                <span className="font-medium text-slate-700">Wersja *</span>
                <input
                  className={`mt-1 w-full rounded border px-2 py-1.5 ${errors.version.length > 0 ? 'border-red-400' : 'border-slate-300'}`}
                  value={version}
                  onChange={(e) => {
                    setVersion(e.target.value)
                    if (errors.version.length > 0) setErrors((prev) => ({ ...prev, version: [] }))
                  }}
                  placeholder="np. 2026-10"
                  disabled={saving}
                />
                {errors.version.map((e) => (
                  <span key={e} className="mt-0.5 block text-red-700">
                    {e}
                  </span>
                ))}
              </label>
            </div>

            <fieldset className="space-y-2 rounded border border-slate-200 p-2.5">
              <legend className="px-1 font-medium text-slate-700">Strona producenta</legend>
              <p className="text-slate-500">
                Domena, na której importer szuka kart wyrobów (jedna w wierszu). Najlepiej wybrać ją z listy „Strony
                wyszukiwarka” — program zna wtedy mapę strony.
                {editing &&
                  ' Usunięcie domeny z pola nie zdejmuje jej ze stron producenta — to robi się w Administracja → „Strony wyszukiwarka”.'}
              </p>
              {brands.map((row, index) => {
                // inne marki bez uprawnienia „Strony wyszukiwarka” — nie pokazujemy i nie wysyłamy (payload)
                if (!row.primary && !canAssignOtherBrands) return null
                const hosts = parseSiteLines(row.text)
                const suggestions = suggestedHosts(row)
                const label = row.primary ? manufacturer.trim() || 'marka producenta' : row.name
                return (
                  <div key={index} className="rounded bg-slate-50 p-2">
                    <div className="flex flex-wrap items-center gap-2">
                      {row.primary ? (
                        <span className="font-medium text-slate-700">Marka: {label}</span>
                      ) : (
                        <input
                          className="w-48 rounded border border-slate-300 px-2 py-1"
                          value={row.name}
                          onChange={(e) => updateBrand(index, { name: e.target.value })}
                          placeholder="Inna marka w tym cenniku"
                          disabled={saving}
                        />
                      )}
                      <button
                        type="button"
                        className="rounded border border-slate-300 bg-white px-2 py-1 hover:bg-slate-50 disabled:opacity-50"
                        onClick={() => {
                          onNeedSearchSites()
                          setPicker(index)
                        }}
                        disabled={saving || brandName(row) === ''}
                        title={brandName(row) === '' ? 'Najpierw wpisz producenta' : undefined}
                      >
                        Wybierz z listy „Strony wyszukiwarka”…
                      </button>
                      {!row.primary && (
                        <button
                          type="button"
                          className="text-slate-500 underline hover:text-slate-800"
                          onClick={() => setBrands((prev) => prev.filter((_, i) => i !== index))}
                          disabled={saving}
                        >
                          usuń markę
                        </button>
                      )}
                    </div>
                    <textarea
                      rows={2}
                      className="mt-1.5 w-full rounded border border-slate-300 px-2 py-1 font-mono text-xs"
                      value={row.text}
                      onChange={(e) => updateBrand(index, { text: e.target.value })}
                      placeholder="mapa-pro.com"
                      disabled={saving}
                    />
                    {suggestions.length > 0 && (
                      <p className="mt-1 flex flex-wrap items-center gap-1 text-slate-600">
                        Na liście przypisane do {label}:
                        {suggestions.map((s) => (
                          <button
                            key={s.host}
                            type="button"
                            className="rounded-full border border-blue-300 bg-white px-2 py-0.5 text-blue-700 hover:bg-blue-50"
                            onClick={() => updateBrand(index, { text: appendSites(row.text, [s.host]) })}
                            disabled={saving}
                          >
                            + {s.host} ({s.links.toLocaleString('pl-PL')} stron)
                          </button>
                        ))}
                      </p>
                    )}
                    {hosts.length > 0 && searchSites && (
                      <ul className="mt-1 space-y-0.5">
                        {hosts.map((h) => {
                          const site = siteByKey.get(siteKey(h))
                          const weak = !site || site.links < WEAK_INDEX_PAGES
                          return (
                            <li key={h} className={weak ? 'text-amber-800' : 'text-slate-600'}>
                              {siteKey(h)}:{' '}
                              {site ? `${site.links.toLocaleString('pl-PL')} stron w indeksie` : 'nie ma jej na liście „Strony wyszukiwarka”'}
                              {weak && <span className="font-medium"> — {WEAK_INDEX_NOTE}</span>}
                            </li>
                          )
                        })}
                      </ul>
                    )}
                  </div>
                )
              })}
              {canAssignOtherBrands ? (
                <button
                  type="button"
                  className="text-blue-700 underline hover:text-blue-900 disabled:opacity-50"
                  onClick={() => setBrands((prev) => [...prev, { primary: false, name: '', text: '' }])}
                  disabled={saving}
                >
                  + Inna marka w tym cenniku
                </button>
              ) : (
                <p className="text-slate-500">Strony producenta innych marek przypisuje administrator (Strony wyszukiwarka).</p>
              )}
              {searchSitesErr && (
                <p className="text-red-700">
                  {searchSitesErr}{' '}
                  <button type="button" className="underline" onClick={onNeedSearchSites}>
                    Spróbuj ponownie
                  </button>
                </p>
              )}
              {errors.hosts.map((e) => (
                <p key={e} className="text-red-700">
                  {e}
                </p>
              ))}
            </fieldset>

            <div className="grid gap-3 sm:grid-cols-2">
              <label className="block">
                <span className="font-medium text-slate-700">Upust na cały cennik (%)</span>
                <input
                  className={`mt-1 w-full rounded border px-2 py-1.5 ${
                    discountValue === 'invalid' || errors.discount.length > 0 ? 'border-red-400' : 'border-slate-300'
                  }`}
                  value={discount}
                  onChange={(e) => {
                    setDiscount(e.target.value)
                    if (errors.discount.length > 0) setErrors((prev) => ({ ...prev, discount: [] }))
                  }}
                  inputMode="decimal"
                  placeholder="puste = bez upustu"
                  disabled={saving}
                />
                <span className="mt-0.5 block text-slate-500">
                  Zmiana rabatu zadziała przy następnym imporcie pliku — obecnych kart nie przelicza. Puste pole = bez
                  rabatu (usuwa zapisany).
                </span>
                {discountValue === 'invalid' && (
                  <span className="mt-0.5 block text-red-700">Podaj liczbę od 0 do 99,99.</span>
                )}
                {errors.discount.map((e) => (
                  <span key={e} className="mt-0.5 block text-red-700">
                    {e}
                  </span>
                ))}
              </label>
              <label
                className="flex items-start gap-2 pt-5 text-slate-700"
                title="Ceny w pliku to ceny katalogowe/sugerowane, nie Twoje ceny zakupu — cena z konta B2B (np. dystrybutora) ma pierwszeństwo, plik zostaje tam, gdzie innej ceny nie ma"
              >
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={suggested}
                  onChange={(e) => setSuggested(e.target.checked)}
                  disabled={saving}
                />
                <span>
                  Cennik sugerowany (bez cen zakupu)
                  <span className="block text-slate-500">Cena z konta B2B ma wtedy pierwszeństwo przed plikiem.</span>
                </span>
              </label>
            </div>
          </div>

          <div className="space-y-3">
            <label className="block">
              <span className="font-medium text-slate-700">Strony dostawców z długimi opisami</span>{' '}
              <span className={tooManySites ? 'font-semibold text-red-700' : 'text-slate-400'}>
                ({sites.length} z {ENRICHMENT_SITES_MAX})
              </span>
              <textarea
                rows={5}
                className={`mt-1 w-full rounded border px-2 py-1.5 font-mono text-xs ${
                  errors.sites.length > 0 || tooManySites ? 'border-red-400' : 'border-slate-300'
                }`}
                value={sitesText}
                onChange={(e) => {
                  setSitesText(e.target.value)
                  if (errors.sites.length > 0) setErrors((prev) => ({ ...prev, sites: [] }))
                }}
                placeholder={'sklepbhp.pl\nhurtowniabhp.pl'}
                disabled={saving}
              />
              <span className="mt-0.5 block text-slate-500">
                Jedna strona w wierszu. Kolejność to ważność — importer bierze stronę dostawcy, gdy producent nie ma
                wyrobu albo ma za krótki opis.
              </span>
              {tooManySites && (
                <span className="mt-0.5 block text-red-700">Za dużo stron — usuń {sites.length - ENRICHMENT_SITES_MAX}.</span>
              )}
              {errors.sites.map((e) => (
                <span key={e} className="mt-0.5 block text-red-700">
                  {e}
                </span>
              ))}
            </label>
            <button
              type="button"
              className="rounded border border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-50"
              onClick={() => {
                onNeedSearchSites()
                setPicker('sites')
              }}
              disabled={saving}
            >
              Wybierz z listy „Strony wyszukiwarka”…
            </button>

            <fieldset className="space-y-1.5">
              <legend className="mb-1 font-medium text-slate-700">Gdzie szukać, gdy wyrobu nie ma u producenta</legend>
              {(['first', 'only'] as const).map((m) => (
                <label key={m} className="flex items-start gap-2">
                  <input
                    type="radio"
                    name="intake-mode"
                    className="mt-0.5"
                    checked={mode === m}
                    onChange={() => {
                      setMode(m)
                      if (errors.mode.length > 0) setErrors((prev) => ({ ...prev, mode: [] }))
                    }}
                    disabled={saving}
                  />
                  <span>{INTAKE_MODE_LABEL[m]}</span>
                </label>
              ))}
              <p className="text-slate-500">Opisu z reszty internetu cennik z importerem nie pobiera.</p>
              {errors.mode.map((e) => (
                <p key={e} className="text-red-700">
                  {e}
                </p>
              ))}
            </fieldset>

            <label className="block">
              <span className="font-medium text-slate-700">Uwagi dla programisty</span>
              <textarea
                rows={4}
                className={`mt-1 w-full rounded border px-2 py-1.5 ${errors.notes.length > 0 ? 'border-red-400' : 'border-slate-300'}`}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder={NOTES_PLACEHOLDER}
                disabled={saving}
              />
              <span className="mt-0.5 block text-slate-500">
                Co programista musi wiedzieć o pliku, żeby przygotować importer.
              </span>
              {errors.notes.map((e) => (
                <span key={e} className="mt-0.5 block text-red-700">
                  {e}
                </span>
              ))}
            </label>
          </div>
        </div>

        {conflict && (
          <div className="mt-3 rounded bg-amber-50 px-3 py-2 text-amber-900">
            <p>{conflict.message || 'Cennik tego producenta już jest.'}</p>
            {conflict.priceListId !== null && (
              <div className="mt-1.5 flex flex-wrap gap-2">
                {conflictList && (
                  <button
                    type="button"
                    className="rounded-full border border-amber-400 bg-white px-3 py-1 font-medium hover:bg-amber-100"
                    onClick={() => onShowList(conflict.priceListId as number)}
                  >
                    Pokaż ten cennik
                  </button>
                )}
                {conflictList && !intakeOf(conflictList) && conflictList.intake && (
                  <button
                    type="button"
                    className="rounded-full bg-blue-600 px-3 py-1 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    disabled={saving}
                    onClick={() => conflictList.intake && onSwitchExisting(conflictList.intake)}
                    title="Otworzy ustawienia istniejącego cennika (z jego obecnymi stronami, trybem, cenami sugerowanymi i rabatem). Po zapisie cennik zacznie przyjmować pliki nowym sposobem. Karty i ich ceny zostają bez zmian — rabat zadziała dopiero przy następnym imporcie pliku."
                  >
                    Przyjmuj ten cennik nowym sposobem
                  </button>
                )}
                {!conflictList && (
                  <span className="text-amber-800">
                    Tego cennika nie ma w zakładce „Z pliku” — sprawdź go w zakładce „Cenniki” albo „B2B”.
                  </span>
                )}
              </div>
            )}
          </div>
        )}
        {errors.general && <p className="mt-3 rounded bg-red-50 px-3 py-2 text-red-700">{errors.general}</p>}

        <div className="mt-3 flex items-center justify-end gap-2">
          {missing && <span className="mr-auto text-slate-500">Producent i wersja są wymagane.</span>}
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose} disabled={saving}>
            Anuluj
          </button>
          <button
            type="button"
            className="rounded bg-blue-600 px-3 py-1.5 text-white hover:bg-blue-700 disabled:opacity-50"
            disabled={saving || invalid}
            onClick={() => void save()}
          >
            {saving
              ? 'Zapisuję…'
              : switching
                ? 'Zapisz i przyjmuj nowym sposobem'
                : editing
                  ? 'Zapisz ustawienia'
                  : 'Zapisz cennik'}
          </button>
        </div>
      </div>

      {picker !== null && (
        <SearchSitesPicker
          manufacturer={picker === 'sites' ? manufacturer.trim() : pickerRow ? brandName(pickerRow) : ''}
          currentText={picker === 'sites' ? sitesText : (pickerRow?.text ?? '')}
          sites={searchSites}
          error={searchSitesErr}
          max={picker === 'sites' ? ENRICHMENT_SITES_MAX : null}
          onRetry={onNeedSearchSites}
          onAdd={(hosts) => {
            if (picker === 'sites') setSitesText((prev) => appendSites(prev, hosts))
            else if (pickerRow) updateBrand(picker, { text: appendSites(pickerRow.text, hosts) })
            setPicker(null)
          }}
          onClose={() => setPicker(null)}
        />
      )}
    </div>
  )
}

type PickerProps = {
  manufacturer: string
  currentText: string
  sites: SearchSiteOption[] | null
  error: string
  /** Największa liczba stron w polu (null = bez limitu, np. strony producenta). */
  max?: number | null
  onRetry: () => void
  onAdd: (hosts: string[]) => void
  onClose: () => void
}

/** Okno wyboru stron z listy Administracja → „Strony wyszukiwarka”; strony producenta cennika na górze. */
export function SearchSitesPicker({
  manufacturer,
  currentText,
  sites,
  error,
  max = ENRICHMENT_SITES_MAX,
  onRetry,
  onAdd,
  onClose,
}: PickerProps) {
  const [filter, setFilter] = useState('')
  const [selected, setSelected] = useState<Record<string, boolean>>({})
  const [anchor, setAnchor] = useState<number | null>(null)

  const present = useMemo(() => new Set(parseSiteLines(currentText).map(siteKey)), [currentText])
  const ownManufacturer = manufacturer.trim().toLowerCase()

  const rows = useMemo(() => {
    if (!sites) return []
    const q = filter.trim().toLowerCase()
    const matches = sites.filter(
      (s) => q === '' || s.host.toLowerCase().includes(q) || s.manufacturers.some((m) => m.toLowerCase().includes(q)),
    )
    const own = (s: SearchSiteOption) => s.manufacturers.some((m) => m.trim().toLowerCase() === ownManufacturer)

    return [...matches.filter(own), ...matches.filter((s) => !own(s))]
  }, [sites, filter, ownManufacturer])

  /** Strona przypisana tej marce tylko przez automat (jest w manufacturers, nie w assigned_manufacturers). */
  function detectedOnly(s: SearchSiteOption): boolean {
    if (ownManufacturer === '') return false
    const eq = (m: string) => m.trim().toLowerCase() === ownManufacturer

    return s.manufacturers.some(eq) && !(s.assigned_manufacturers ?? []).some(eq)
  }

  const orderedHosts = useMemo(() => rows.map((s) => s.host), [rows])
  const chosen = useMemo(
    () => (sites ?? []).map((s) => s.host).filter((h) => selected[h] && !present.has(siteKey(h))),
    [sites, selected, present],
  )
  const room = max === null ? Infinity : Math.max(0, max - present.size)

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="search-sites-picker-title"
      onClick={(e) => {
        e.stopPropagation()
        onClose()
      }}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl bg-white p-4 text-xs shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="search-sites-picker-title" className="text-sm font-semibold text-slate-800">
          Wybierz strony z listy „Strony wyszukiwarka” — {manufacturer}
        </p>
        <p className="mt-1 text-slate-500">
          Zaznaczone strony dopiszą się na koniec pola (bez powtórzeń); kolejność możesz potem zmienić w polu. Shift +
          kliknięcie zaznacza zakres. Strony przypisane do producenta {manufacturer} są na górze.
        </p>
        <input
          className="mt-2 w-full rounded border border-slate-300 px-2 py-1.5"
          placeholder="Filtruj po domenie albo producencie"
          value={filter}
          onChange={(e) => {
            setFilter(e.target.value)
            setAnchor(null)
          }}
          autoFocus
        />
        <div className="mt-2 min-h-[10rem] flex-1 overflow-auto rounded border border-slate-200">
          {sites === null && !error && <p className="p-3 text-slate-500">Ładowanie listy stron…</p>}
          {error && (
            <p className="p-3 text-red-700">
              {error}{' '}
              <button type="button" className="underline" onClick={onRetry}>
                Spróbuj ponownie
              </button>
            </p>
          )}
          {sites !== null && (
            <table className="w-full text-left">
              <thead className="sticky top-0 bg-slate-50">
                <tr className="border-b text-slate-700">
                  <th className="w-8 p-1.5" />
                  <th className="p-1.5 font-semibold">Domena</th>
                  <th className="p-1.5 text-right font-semibold">Stron w indeksie</th>
                  <th className="p-1.5 font-semibold">Producent</th>
                  <th className="p-1.5 text-right font-semibold">Ranga</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((s, index) => {
                  const already = present.has(siteKey(s.host))
                  return (
                    <tr key={s.host} className={`border-b ${already ? 'text-slate-400' : 'hover:bg-blue-50/50'}`}>
                      <td className="select-none p-1.5">
                        <input
                          type="checkbox"
                          checked={already || Boolean(selected[s.host])}
                          disabled={already}
                          aria-label={`Zaznacz ${s.host}`}
                          title={already ? 'Już w polu' : 'Shift + kliknięcie zaznacza wszystkie od ostatnio klikniętej'}
                          onChange={() => {
                            /* obsługa w onClick — potrzebny shiftKey */
                          }}
                          onClick={(e) => {
                            const next = applyCheckboxRange(orderedHosts, selected, anchor, index, e.shiftKey)
                            setSelected(next.selected)
                            setAnchor(next.anchorIndex)
                          }}
                        />
                      </td>
                      <td className="p-1.5">
                        {s.host}
                        {already && <span className="ml-1 text-[10px]">(już na liście)</span>}
                        {detectedOnly(s) && (
                          <span className="block text-amber-800">wykryta automatem — sprawdź, czy to strona producenta</span>
                        )}
                      </td>
                      <td className="p-1.5 text-right tabular-nums">{s.links.toLocaleString('pl-PL')}</td>
                      <td className="p-1.5">{s.manufacturers.length > 0 ? s.manufacturers.join(', ') : '—'}</td>
                      <td className="p-1.5 text-right tabular-nums">{s.priority ?? '—'}</td>
                    </tr>
                  )
                })}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="p-3 text-slate-500">
                      Brak stron pasujących do filtra.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        {chosen.length > room && (
          <p className="mt-2 text-amber-800">
            Zaznaczono {chosen.length}, a w polu zostało miejsca na {room} (najwyżej {max} stron) — przed zapisem trzeba
            będzie usunąć nadmiar.
          </p>
        )}
        <div className="mt-3 flex justify-end gap-2">
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose}>
            Anuluj
          </button>
          <button
            type="button"
            className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
            disabled={chosen.length === 0}
            onClick={() => onAdd(chosen)}
          >
            Dodaj zaznaczone ({chosen.length})
          </button>
        </div>
      </div>
    </div>
  )
}
