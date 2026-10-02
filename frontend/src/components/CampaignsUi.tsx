import { useEffect, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { can } from '../lib/api'
import { campaignsTabHref, type CampaignsTab } from '../lib/campaignFormat'
import { CAMPAIGN_STATUS_LABEL, type CampaignStatus, type PageMeta } from '../lib/campaigns'
import type { TableSort } from '../lib/tableSort'

/** Wspólne drobne elementy stron Kampanie: zakładki, okno potwierdzenia, pasek błędu, stronicowanie, znaczniki. */

/**
 * Zakładki jak w Cennikach/Zapasach, ze znacznikami app-tab dla motywu. „Wszystkie” z campaigns.manage albo
 * campaigns.view; sam podgląd (bez campaigns.use) widzi tylko „Wszystkie”.
 */
export function CampaignsTabs({ active }: { active: CampaignsTab }) {
  const { user } = useAuth()
  const use = can(user, 'campaigns.use')
  const tabs: { key: CampaignsTab; label: string }[] = [
    ...(use ? [{ key: 'mine' as const, label: 'Moje kampanie' }] : []),
    ...(can(user, 'campaigns.manage') || can(user, 'campaigns.view') ? [{ key: 'all' as const, label: 'Wszystkie' }] : []),
    ...(use
      ? [
          { key: 'templates' as const, label: 'Moje szablony' },
          { key: 'groups' as const, label: 'Grupy odbiorców' },
          { key: 'suppressed' as const, label: 'Wypisani' },
        ]
      : []),
  ]
  return (
    <nav className="app-tabs mb-4 flex flex-wrap gap-1 border-b border-slate-200">
      {tabs.map((t) => {
        const on = t.key === active
        return (
          <Link
            key={t.key}
            to={campaignsTabHref(t.key)}
            aria-current={on ? 'page' : undefined}
            className={`app-tab -mb-px border-b-2 px-3 py-2 text-sm ${
              on
                ? 'app-tab--active border-blue-600 font-semibold text-blue-700'
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            {t.label}
          </Link>
        )
      })}
    </nav>
  )
}

export function ErrorBar({ message, onClose }: { message: string; onClose?: () => void }) {
  if (!message) return null
  return (
    <div className="mb-3 flex items-start justify-between gap-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="alert">
      <span>{message}</span>
      {onClose && (
        <button type="button" onClick={onClose} className="shrink-0 font-medium underline" aria-label="Zamknij komunikat">
          Zamknij
        </button>
      )}
    </div>
  )
}

export type ChipTone = 'slate' | 'amber' | 'red' | 'blue' | 'green'

const CHIP_TONE: Record<ChipTone, string> = {
  slate: 'bg-slate-100 text-slate-600',
  amber: 'bg-amber-100 text-amber-800',
  red: 'bg-red-100 text-red-700',
  blue: 'bg-sky-100 text-sky-800',
  green: 'bg-emerald-100 text-emerald-800',
}

export function Chip({ tone = 'slate', title, children }: { tone?: ChipTone; title?: string; children: ReactNode }) {
  return (
    <span
      title={title}
      className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium ${CHIP_TONE[tone]}`}
    >
      {children}
    </span>
  )
}

/** Znacznik funkcji z drugiego etapu (jak „ETAP 2” w makiecie). */
export function StageTwo() {
  return (
    <span
      className="whitespace-nowrap rounded border border-dashed border-slate-300 px-1.5 text-[10px] font-semibold tracking-wide text-slate-400"
      title="Będzie w drugim etapie"
    >
      Etap 2
    </span>
  )
}

const STATUS_TONE: Record<CampaignStatus, ChipTone> = {
  scheduled: 'blue',
  draft: 'slate',
  sending: 'amber',
  sent: 'green',
  cancelled: 'red',
}

export function CampaignStatusChip({
  status,
  sent,
  total,
}: {
  status: CampaignStatus
  sent?: number | null
  total?: number | null
}) {
  const label = CAMPAIGN_STATUS_LABEL[status] ?? status
  const progress = status === 'sending' && total != null && total > 0 ? ` ${sent ?? 0}/${total}` : ''
  return (
    <Chip tone={STATUS_TONE[status] ?? 'slate'}>
      {label}
      {progress && <span className="tabular-nums">{progress}</span>}
    </Chip>
  )
}

/** Pasek „zeszło z magazynu”: procent 0–100. */
export function DropBar({ percent, suffix }: { percent: number; suffix?: string }) {
  const p = Math.max(0, Math.min(100, percent))
  return (
    <span className="inline-flex items-center gap-2 whitespace-nowrap">
      <span className="inline-block h-1.5 w-24 overflow-hidden rounded bg-slate-200" aria-hidden>
        <span className="block h-full bg-emerald-600" style={{ width: `${p}%` }} />
      </span>
      <span className="tabular-nums text-slate-800">
        {p > 0 ? '−' : ''}
        {p.toLocaleString('pl-PL', { maximumFractionDigits: 0 })}%{suffix ?? ''}
      </span>
    </span>
  )
}

/** Nagłówek kolumny z sortowaniem — jak w Zapasach / towarach XL (▲ ▼, ↕ dla nieaktywnej). */
export function SortTh<K extends string>({
  label,
  k,
  sort,
  onSort,
  align,
}: {
  label: string
  k: K
  sort: TableSort<K>
  onSort: (k: K) => void
  align?: 'right'
}) {
  const active = sort?.key === k
  return (
    <th
      className={`p-2 ${align === 'right' ? 'text-right' : ''}`}
      aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
    >
      <button
        type="button"
        onClick={() => onSort(k)}
        className={`inline-flex items-center gap-0.5 hover:text-slate-900 ${active ? 'text-slate-900' : ''}`}
        title="Sortuj"
      >
        {label}
        <span className={active ? 'text-slate-700' : 'text-slate-300'} aria-hidden>
          {active ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}
        </span>
      </button>
    </th>
  )
}

export function Pager({ meta, disabled, onPage }: { meta: PageMeta | null | undefined; disabled?: boolean; onPage: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <nav className="flex flex-wrap items-center justify-end gap-2 py-2 text-xs" aria-label="Strony listy">
      <button
        type="button"
        disabled={disabled || meta.current_page <= 1}
        onClick={() => onPage(meta.current_page - 1)}
        className="rounded border border-slate-300 px-2.5 py-1 hover:bg-slate-50 disabled:opacity-40"
      >
        ← Poprzednia
      </button>
      <span className="tabular-nums text-slate-600">
        Strona {meta.current_page} z {meta.last_page}
      </span>
      <button
        type="button"
        disabled={disabled || meta.current_page >= meta.last_page}
        onClick={() => onPage(meta.current_page + 1)}
        className="rounded border border-slate-300 px-2.5 py-1 hover:bg-slate-50 disabled:opacity-40"
      >
        Następna →
      </button>
    </nav>
  )
}

/**
 * Okno strony (bez window.confirm). Renderowane do body, bo .app-main jest kontenerem (@container) i
 * position: fixed liczyłby się od niego.
 */
export function Modal({
  title,
  onClose,
  busy,
  wide,
  children,
  footer,
}: {
  title: string
  onClose: () => void
  busy?: boolean
  /** true = szerokie okno; 'full' = prawie cały ekran (duże listy do wyboru). */
  wide?: boolean | 'full'
  children: ReactNode
  footer?: ReactNode
}) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [busy, onClose])

  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className={`flex max-h-[90vh] w-full flex-col overflow-hidden rounded-xl bg-white shadow-lg ${
          wide === 'full' ? 'h-[90vh] max-w-7xl' : wide ? 'max-w-4xl' : 'max-w-lg'
        }`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <p className="text-sm font-semibold text-slate-900">{title}</p>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
          >
            ×
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3 text-sm">{children}</div>
        {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

/**
 * Okno potwierdzenia. Z confirmWord przycisk działa dopiero po wpisaniu tego słowa (wielkość liter bez znaczenia) —
 * dla operacji, których nie da się cofnąć.
 */
export function ConfirmDialog({
  title,
  message,
  confirmLabel,
  confirmWord,
  danger,
  busy,
  error,
  onConfirm,
  onClose,
}: {
  title: string
  message: ReactNode
  confirmLabel: string
  confirmWord?: string
  danger?: boolean
  busy?: boolean
  error?: string
  onConfirm: () => void
  onClose: () => void
}) {
  const [typed, setTyped] = useState('')
  const ready = confirmWord === undefined || typed.trim().toLocaleLowerCase('pl') === confirmWord.toLocaleLowerCase('pl')
  return (
    <Modal
      title={title}
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy || !ready}
            className={`rounded px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50 ${
              danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {busy ? 'Chwila…' : confirmLabel}
          </button>
        </>
      }
    >
      <div className="space-y-2 text-slate-800">{message}</div>
      {confirmWord !== undefined && (
        <label className="mt-3 block text-xs text-slate-700">
          Aby potwierdzić, wpisz <b>{confirmWord}</b>
          <input
            type="text"
            value={typed}
            onChange={(e) => setTyped(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && ready && !busy) onConfirm()
            }}
            disabled={busy}
            autoFocus
            autoComplete="off"
            spellCheck={false}
            className="mt-1 block w-40 rounded border border-slate-300 bg-white px-2 py-1 text-sm text-slate-800"
          />
        </label>
      )}
      {error && <p className="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>}
    </Modal>
  )
}

export const BTN = 'rounded border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50'
export const BTN_SM = 'rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-50'
export const BTN_PRIMARY = 'rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'
export const INPUT = 'rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800'
