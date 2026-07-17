export function Help() {
  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Pomoc</h1>
      <div className="rounded-xl bg-white p-5 shadow-sm text-sm leading-relaxed">
        <h2 className="mb-2 font-semibold">Jak działa system w skrócie</h2>
        <ol className="list-decimal space-y-2 pl-5">
          <li>Import dokumentów klienta (SIWZ) do projektu przetargu.</li>
          <li>
            AI / system dopasowuje do każdej pozycji <strong>produkt główny</strong> z bazy Supon.
          </li>
          <li>
            Dopiero potem proponuje <strong>zamienniki tego głównego</strong> — nie listę z kategorii.
          </li>
          <li>Handlowiec wycenia, kierownik akceptuje zamienniki, dyrektor ofertę.</li>
          <li>Eksport Excel/PDF i archiwum.</li>
        </ol>
      </div>
      <div className="rounded-xl bg-white p-5 shadow-sm text-sm">
        <h2 className="mb-2 font-semibold">Demo logowania</h2>
        <p>
          <code className="rounded bg-slate-100 px-1">arek@supon.local</code> /{' '}
          <code className="rounded bg-slate-100 px-1">password</code>
        </p>
      </div>
    </div>
  )
}
