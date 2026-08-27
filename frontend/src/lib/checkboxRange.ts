/** Shift+klik: zaznacz / odznacz wszystkie pozycje między kotwicą a celem. */
export function applyCheckboxRange<T extends number | string>(
  orderedIds: T[],
  selected: Record<T, boolean>,
  anchorIndex: number | null,
  targetIndex: number,
  shiftKey: boolean,
): { selected: Record<T, boolean>; anchorIndex: number } {
  const targetId = orderedIds[targetIndex]
  if (targetId === undefined) {
    return { selected, anchorIndex: anchorIndex ?? 0 }
  }

  const next = { ...selected }
  const turnOn = !selected[targetId]
  const useRange =
    shiftKey &&
    anchorIndex !== null &&
    anchorIndex >= 0 &&
    anchorIndex < orderedIds.length

  if (useRange) {
    const from = Math.min(anchorIndex, targetIndex)
    const to = Math.max(anchorIndex, targetIndex)
    for (let i = from; i <= to; i++) {
      const id = orderedIds[i]
      if (id === undefined) continue
      if (turnOn) next[id] = true
      else delete next[id]
    }
  } else if (turnOn) {
    next[targetId] = true
  } else {
    delete next[targetId]
  }

  return { selected: next, anchorIndex: targetIndex }
}
