import type { ProductSourceModel } from '../lib/api'

/** „38–48” dla ciągu rozmiarów, „36” albo „36, 49” dla jednego–dwóch. */
function sizesLabel(sizes: string[]): string {
  if (sizes.length === 0) return ''
  return sizes.length > 2 ? `${sizes[0]}–${sizes[sizes.length - 1]}` : sizes.join(', ')
}

/**
 * Modele połączone w jednej karcie (ELTEN MAVERICK red 0723341-0 i black 0723381-0; kolory Portwest, Mascot, JHK…
 * z wierszy rozmiarów): nazwa u dostawcy albo kolor, numer i rozmiary — tyle pozycji, ile producent pokazuje w katalogu.
 */
export function SourceModelsList({ models, className }: { models: ProductSourceModel[]; className?: string }) {
  if (models.length < 2) return null
  return (
    <ul
      className={`text-[11px] leading-snug text-slate-600 ${className ?? ''}`}
      title="Modele dostawcy połączone w tej karcie (kolory albo rozmiary pod innym numerem)"
    >
      {models.map((m, i) => {
        const sizes = sizesLabel(m.sizes)
        return (
          // numer bywa wspólny dla kilku kolorów (kod dostawcy bez koloru) — klucz z pozycją
          <li key={`${i}-${m.number}`} className="break-words">
            <span className="text-slate-400">• </span>
            {m.name && <>{m.name} – </>}
            <span className="font-mono text-slate-700">{m.number}</span>
            {sizes && <span className="text-slate-500"> (rozm. {sizes})</span>}
          </li>
        )
      })}
    </ul>
  )
}
