import { useEffect, useState } from 'react'

type Props = {
  message?: string | null
  updatedAt?: string | null
  running?: boolean
  className?: string
}

export function EnrichmentLiveMessage({ message, updatedAt, running, className }: Props) {
  const waiting = Boolean(running && message && /czeka/.test(message))
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!waiting) {
      return
    }
    const t = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(t)
  }, [waiting, updatedAt])

  if (!message) {
    return null
  }

  const started = updatedAt ? Date.parse(updatedAt) : Number.NaN
  const elapsed =
    waiting && Number.isFinite(started) ? Math.max(0, Math.round((now - started) / 1000)) : null

  return (
    <span className={className} title={message}>
      {message}
      {elapsed != null ? ` ${elapsed} s` : ''}
    </span>
  )
}
