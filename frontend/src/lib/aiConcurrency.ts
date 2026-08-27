export const AI_CONCURRENCY_DEFAULT = 4
export const AI_CONCURRENCY_MAX = 100

export function clampAiConcurrency(value: number | undefined): number {
  const n = Number(value)
  if (!Number.isFinite(n)) {
    return AI_CONCURRENCY_DEFAULT
  }

  return Math.max(1, Math.min(AI_CONCURRENCY_MAX, Math.round(n)))
}

export function clampEnrichmentBatchLimit(value: number | undefined): number {
  const n = Number(value)
  if (!Number.isFinite(n)) {
    return 5
  }

  return Math.max(1, Math.round(n))
}

export async function mapPool<T>(
  items: T[],
  concurrency: number,
  worker: (item: T) => Promise<void>,
): Promise<void> {
  let next = 0
  const run = async () => {
    while (next < items.length) {
      const i = next
      next += 1
      await worker(items[i])
    }
  }
  await Promise.all(
    Array.from({ length: Math.min(Math.max(1, concurrency), Math.max(items.length, 1)) }, () => run()),
  )
}
