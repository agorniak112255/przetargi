import { TENDER_STATUS_FLOW, tenderStatusLabel } from '../lib/tenderStatus'

type StepState = 'done' | 'now' | 'todo'

/** Pasek etapów przetargu. Widoczny tylko w szablonach, które pokazują .app-flow (base.css go ukrywa). */
export function StatusFlow({ status }: { status: string }) {
  const current = (TENDER_STATUS_FLOW as readonly string[]).indexOf(status)
  // Status poza ścieżką (odrzucony, archiwum): nie wiadomo, na którym etapie się zatrzymał.
  const offPath = current < 0

  return (
    <ol className="app-flow" aria-label="Etapy przetargu">
      {TENDER_STATUS_FLOW.map((step, i) => {
        const state: StepState = offPath || i > current ? 'todo' : i === current ? 'now' : 'done'
        return (
          <li
            key={step}
            className="app-flow-step"
            data-state={state}
            aria-current={state === 'now' ? 'step' : undefined}
          >
            {tenderStatusLabel(step)}
          </li>
        )
      })}
      {offPath && (
        <li className="app-flow-step" data-state="off" aria-current="step">
          {tenderStatusLabel(status)}
        </li>
      )}
    </ol>
  )
}
