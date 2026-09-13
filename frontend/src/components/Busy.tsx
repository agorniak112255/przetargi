import { useEffect, useState } from 'react'

export function useBusySeconds(busy: boolean): number {
  const [sec, setSec] = useState(0)
  useEffect(() => {
    if (!busy) {
      setSec(0)
      return
    }
    const id = window.setInterval(() => setSec((s) => s + 1), 1000)
    return () => window.clearInterval(id)
  }, [busy])
  return sec
}

export function BusyLabel({ label, seconds }: { label: string; seconds: number }) {
  return (
    <span className="inline-flex items-center justify-center gap-2">
      <span
        className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
        aria-hidden
      />
      {label} · {seconds}s
    </span>
  )
}
