import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

function isKeptSecret(value: string): boolean {
  const v = value.trim()
  return v === '' || v.includes('*')
}

type SearchEngine = 'tavily' | 'duckduckgo' | 'searxng'

type AiSettings = {
  enabled: boolean
  provider: string
  base_url: string
  model: string
  enrichment_model: string | null
  enrichment_use_large_model: boolean
  timeout_seconds: number
  temperature: number
  web_search_enabled: boolean
  search_engine: SearchEngine
  searxng_url: string | null
  search_fallback: string
  tavily_search_mode: 'eco' | 'balanced' | 'full'
  enrichment_batch_limit: number
  match_concurrency: number
  vector_enabled: boolean
  qdrant_url: string | null
  qdrant_collection: string | null
  embedding_model: string | null
  embedding_base_url: string | null
  has_api_key: boolean
  has_tavily_api_key: boolean
  has_qdrant_api_key: boolean
  has_embedding_api_key: boolean
  source: string
  api_key_masked: string | null
  tavily_api_key_masked: string | null
  qdrant_api_key_masked: string | null
  embedding_api_key_masked: string | null
}

export function AiSettingsPage() {
  const [cfg, setCfg] = useState<AiSettings | null>(null)
  const [apiKey, setApiKey] = useState('')
  const [tavilyKey, setTavilyKey] = useState('')
  const [qdrantKey, setQdrantKey] = useState('')
  const [embeddingKey, setEmbeddingKey] = useState('')
  const [showApiKey, setShowApiKey] = useState(false)
  const [showTavilyKey, setShowTavilyKey] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')

  function hydrateSecrets(next: AiSettings) {
    setApiKey(next.has_api_key ? (next.api_key_masked ?? '') : '')
    setTavilyKey(next.has_tavily_api_key ? (next.tavily_api_key_masked ?? '') : '')
    setQdrantKey(next.has_qdrant_api_key ? (next.qdrant_api_key_masked ?? '') : '')
    setEmbeddingKey(next.has_embedding_api_key ? (next.embedding_api_key_masked ?? '') : '')
  }

  async function load() {
    setErr('')
    try {
      const next = await api<AiSettings>('/ai-settings')
      setCfg(next)
      hydrateSecrets(next)
    } catch (ex) {
      setCfg(null)
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać ustawień AI')
    }
  }

  useEffect(() => {
    void load()
  }, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!cfg) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, unknown> = {
        enabled: cfg.enabled,
        provider: cfg.provider,
        base_url: cfg.base_url,
        model: cfg.model,
        enrichment_model: cfg.enrichment_model?.trim() || null,
        enrichment_use_large_model: Boolean(cfg.enrichment_use_large_model),
        timeout_seconds: cfg.timeout_seconds,
        temperature: cfg.temperature,
        web_search_enabled: cfg.web_search_enabled,
        search_engine: cfg.search_engine || 'tavily',
        searxng_url: cfg.searxng_url?.trim() || null,
        search_fallback: cfg.search_fallback,
        tavily_search_mode: cfg.tavily_search_mode || 'balanced',
        enrichment_batch_limit: cfg.enrichment_batch_limit || 5,
        match_concurrency: cfg.match_concurrency || 4,
        vector_enabled: cfg.vector_enabled,
        qdrant_url: cfg.qdrant_url?.trim() || null,
        qdrant_collection: cfg.qdrant_collection?.trim() || 'products',
        embedding_model: cfg.embedding_model?.trim() || null,
        embedding_base_url: cfg.embedding_base_url?.trim() || null,
      }
      if (!isKeptSecret(apiKey)) {
        body.api_key = apiKey.trim()
      }
      if (!isKeptSecret(tavilyKey)) {
        body.tavily_api_key = tavilyKey.trim()
      }
      if (!isKeptSecret(qdrantKey)) {
        body.qdrant_api_key = qdrantKey.trim()
      }
      if (!isKeptSecret(embeddingKey)) {
        body.embedding_api_key = embeddingKey.trim()
      }
      const saved = await api<AiSettings>('/ai-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setCfg(saved)
      hydrateSecrets(saved)
      setMsg('Zapisano konfigurację AI.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function onTest() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ ok: boolean; message: string }>('/ai-settings/test', {
        method: 'POST',
        body: '{}',
      })
      setMsg(res.message)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Test nieudany')
    } finally {
      setBusy(false)
    }
  }

  async function onTestVector() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ ok: boolean; message: string }>('/ai-settings/test-vector', {
        method: 'POST',
        body: '{}',
      })
      setMsg(res.message)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Test wektorowy nieudany')
    } finally {
      setBusy(false)
    }
  }

  if (!cfg) {
    return (
      <div>
        <h1 className="mb-2 text-xl font-semibold">Ustawienia AI</h1>
        {err ? (
          <div className="space-y-2">
            <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-1.5 text-xs"
              onClick={() => void load()}
            >
              Spróbuj ponownie
            </button>
          </div>
        ) : (
          <p className="text-sm text-slate-500">Ładowanie ustawień AI…</p>
        )}
      </div>
    )
  }

  return (
    <div>
      <h1 className="mb-2 text-xl font-semibold">Ustawienia AI</h1>
      <p className="mb-4 text-xs text-slate-500">
        Konfiguracja API zgodnego z OpenAI (OpenAI, Groq, Azure, vLLM, Ollama proxy). Źródło:{' '}
        <code>{cfg.source}</code>
        {cfg.has_api_key ? ` · klucz: ${cfg.api_key_masked}` : ' · brak klucza'}
        {cfg.has_tavily_api_key
          ? ` · Tavily: ${cfg.tavily_api_key_masked}`
          : ' · brak klucza Tavily'}
        .
      </p>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <form onSubmit={onSave} className="max-w-xl rounded-xl bg-white p-4 shadow-sm text-sm space-y-3">
        <label className="flex items-center gap-2 text-xs">
          <input
            type="checkbox"
            checked={cfg.enabled}
            onChange={(e) => setCfg({ ...cfg, enabled: e.target.checked })}
          />
          Włącz integrację AI
        </label>

        <label className="block text-xs">
          Base URL *
          <input
            required
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
            value={cfg.base_url}
            onChange={(e) => setCfg({ ...cfg, base_url: e.target.value })}
            placeholder="https://api.openai.com/v1"
          />
        </label>

        <label className="block text-xs">
          Model *
          <input
            required
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
            value={cfg.model}
            onChange={(e) => setCfg({ ...cfg, model: e.target.value })}
            placeholder="gpt-4o-mini"
            list="ai-models"
          />
          <datalist id="ai-models">
            <option value="openai/gpt-5.4" />
            <option value="openai/gpt-4o" />
            <option value="openai/gpt-4o-mini" />
            <option value="gpt-4o-mini" />
            <option value="gpt-4o" />
            <option value="gpt-4.1-mini" />
            <option value="llama-3.3-70b-versatile" />
          </datalist>
        </label>

        <label className="block text-xs">
          Klucz API {cfg.has_api_key ? '(zostaw puste, by nie zmieniać)' : '*'}
          <input
            type={showApiKey ? 'text' : 'password'}
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
            placeholder={cfg.has_api_key ? '••••••••' : 'sk-…'}
            autoComplete="new-password"
          />
          <span className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500">
            <input
              type="checkbox"
              checked={showApiKey}
              onChange={(e) => setShowApiKey(e.target.checked)}
            />
            Pokaż klucz
          </span>
        </label>

        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-xs">
            Timeout (s)
            <input
              type="number"
              min={10}
              max={300}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.timeout_seconds}
              onChange={(e) => setCfg({ ...cfg, timeout_seconds: Number(e.target.value) })}
            />
          </label>
          <label className="block text-xs">
            Temperature
            <input
              type="number"
              step="0.1"
              min={0}
              max={2}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.temperature}
              onChange={(e) => setCfg({ ...cfg, temperature: Number(e.target.value) })}
            />
          </label>
        </div>

        <div className="rounded border border-slate-200 bg-slate-50 p-3 space-y-2">
          <p className="text-xs font-semibold text-slate-700">Wyszukiwanie opisów produktów</p>
          <p className="text-[11px] text-slate-500">
            Szukanie stron produktu robi PHP (Tavily albo darmowo Google/Bing), potem model pisze
            opis. Lokalny LLM nie ma internetu — plugin OpenRouter <code>web</code> nic nie robi.
          </p>
          <label className="block text-xs">
            Szukanie w internecie
            <select
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.search_engine || 'tavily'}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  search_engine: e.target.value as SearchEngine,
                })
              }
            >
              <option value="tavily">Tavily (płatne, dokładniejsze)</option>
              <option value="searxng">SearXNG — własna instancja (darmowe, zalecane)</option>
              <option value="duckduckgo">Publiczne wyszukiwarki (darmowe, często blokowane)</option>
            </select>
            <span className="mt-1 block text-[11px] text-slate-500">
              {cfg.search_engine === 'searxng'
                ? 'PHP pyta Twój SearXNG (format json). Bez limitów i kredytów.'
                : cfg.search_engine === 'duckduckgo'
                  ? 'Google, Bing i DuckDuckGo blokują ruch z serwera (captcha, 403) — wyniki bywają puste lub nietrafione.'
                  : 'Tavily zużywa kredyty, ale nie da się zablokować przez captcha.'}
            </span>
          </label>
          {cfg.search_engine === 'searxng' && (
            <label className="block text-xs">
              Adres SearXNG *
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
                value={cfg.searxng_url ?? ''}
                onChange={(e) => setCfg({ ...cfg, searxng_url: e.target.value })}
                placeholder="http://127.0.0.1:8088"
              />
              <span className="mt-1 block text-[11px] text-slate-500">
                Kontener na tym samym serwerze — instalacja przez{' '}
                <code>deploy/searxng/install-on-server.sh</code>.
              </span>
            </label>
          )}
          <label className="flex items-start gap-2 text-xs">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={cfg.enrichment_use_large_model ?? false}
              onChange={(e) => setCfg({ ...cfg, enrichment_use_large_model: e.target.checked })}
            />
            <span>
              Tylko duży model (główny) — opis modelem z góry
              <span className="mt-0.5 block text-[11px] text-slate-500">
                Opis modelem z góry. Szukanie wg opcji powyżej (Tavily albo Google/Bing).
              </span>
            </span>
          </label>
          <label className="block text-xs">
            Tani model (opisy produktów)
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 disabled:bg-slate-100"
              value={cfg.enrichment_model ?? ''}
              onChange={(e) => setCfg({ ...cfg, enrichment_model: e.target.value || null })}
              placeholder={cfg.model || 'np. openai/gpt-4o-mini'}
              list="ai-enrichment-models"
              disabled={cfg.enrichment_use_large_model}
            />
            <datalist id="ai-enrichment-models">
              <option value="openai/gpt-4o-mini" />
              <option value="google/gemini-2.0-flash-001" />
              <option value="meta-llama/llama-3.3-70b-instruct" />
            </datalist>
          </label>
          <label className="block text-xs">
            Klucz Tavily{' '}
            {cfg.search_engine !== 'tavily'
              ? '(nieużywany w trybie darmowym)'
              : cfg.has_tavily_api_key
                ? '(zostaw puste, by nie zmieniać)'
                : '* (wymagany do pobierania)'}
            <input
              type={showTavilyKey ? 'text' : 'password'}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono disabled:bg-slate-100"
              disabled={cfg.search_engine !== 'tavily'}
              value={tavilyKey}
              onChange={(e) => setTavilyKey(e.target.value)}
              placeholder={cfg.has_tavily_api_key ? '••••••••' : 'tvly-…'}
              autoComplete="new-password"
            />
            <span className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500">
              <input
                type="checkbox"
                checked={showTavilyKey}
                onChange={(e) => setShowTavilyKey(e.target.checked)}
              />
              Pokaż klucz
            </span>
          </label>
          <label className="block text-xs">
            Tryb wyszukiwania Tavily (zużycie kredytów)
            <select
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 disabled:bg-slate-100"
              disabled={cfg.search_engine !== 'tavily'}
              value={cfg.tavily_search_mode || 'balanced'}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  tavily_search_mode: e.target.value as 'eco' | 'balanced' | 'full',
                })
              }
            >
              <option value="eco">Oszczędny — 1 zapytanie, 1 faza (najmniej kredytów)</option>
              <option value="balanced">Zbalansowany — stop po 1 wyniku, dłuższy cache (domyślny)</option>
              <option value="full">Pełny — agresywne szukanie (dużo kredytów)</option>
            </select>
            <span className="mt-1 block text-[11px] text-slate-500">
              Przy limicie planu (HTTP 432) batch się zatrzymuje i nie ponawia zapytań.
            </span>
          </label>
          <label className="block text-xs">
            Max produktów w kolejce enrichmentu (1–100)
            <input
              type="number"
              min={1}
              max={100}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.enrichment_batch_limit ?? 5}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  enrichment_batch_limit: Math.max(1, Math.min(100, Number(e.target.value) || 5)),
                })
              }
            />
            <span className="mt-1 block text-[11px] text-slate-500">
              Limit na jedno kliknięcie: cenniki, „Pobierz widoczne bez opisu”, zaznaczone. Resztę
              trzeba uruchomić kolejnymi batchami.
            </span>
          </label>
          <label className="block text-xs">
            Ile zapytań AI naraz (1–100)
            <input
              type="number"
              min={1}
              max={100}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.match_concurrency ?? 4}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  match_concurrency: Math.max(1, Math.min(100, Number(e.target.value) || 4)),
                })
              }
            />
            <span className="mt-1 block text-[11px] text-slate-500">
              Dopasowanie SIWZ i Pobierz z cennika. 1 = kolejka. 3–6 zwykle bezpiecznie dla
              lokalnego modelu. 20–100 tylko gdy PHP-FPM i GPU dają radę — inaczej timeouty.
            </span>
          </label>
          <label className="flex items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={cfg.web_search_enabled ?? false}
              onChange={(e) => setCfg({ ...cfg, web_search_enabled: e.target.checked })}
            />
            Drogi fallback: AI web search (OpenRouter) — tylko Tavily + OpenRouter, nie lokalny LLM
          </label>
        </div>

        <div className="rounded border border-slate-200 bg-slate-50 p-3 space-y-2">
          <p className="text-xs font-semibold text-slate-700">Wyszukiwanie wektorowe (RAG)</p>
          <p className="text-[11px] text-slate-500">
            Qdrant + embeddingi OpenAI-compatible. Prefilter katalogu AI i kandydaci SIWZ. Gdy
            wyłączone lub błąd — fallback do SQL LIKE. Po włączeniu: reindex{' '}
            <code>php artisan products:reindex-embeddings</code>.
          </p>
          <label className="flex items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={cfg.vector_enabled ?? false}
              onChange={(e) => setCfg({ ...cfg, vector_enabled: e.target.checked })}
            />
            Włącz wyszukiwanie wektorowe
          </label>
          <label className="block text-xs">
            Qdrant URL
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.qdrant_url ?? ''}
              onChange={(e) => setCfg({ ...cfg, qdrant_url: e.target.value || null })}
              placeholder="http://127.0.0.1:6333"
            />
          </label>
          <label className="block text-xs">
            Kolekcja
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.qdrant_collection ?? 'products'}
              onChange={(e) => setCfg({ ...cfg, qdrant_collection: e.target.value || 'products' })}
              placeholder="products"
            />
          </label>
          <label className="block text-xs">
            Klucz Qdrant (opcjonalnie){' '}
            {cfg.has_qdrant_api_key ? '(zostaw puste, by nie zmieniać)' : ''}
            <input
              type="password"
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
              value={qdrantKey}
              onChange={(e) => setQdrantKey(e.target.value)}
              placeholder={cfg.has_qdrant_api_key ? '••••••••' : ''}
              autoComplete="new-password"
            />
          </label>
          <label className="block text-xs">
            Model embeddings
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.embedding_model ?? ''}
              onChange={(e) => setCfg({ ...cfg, embedding_model: e.target.value || null })}
              placeholder="text-embedding-3-small"
              list="ai-embedding-models"
            />
            <datalist id="ai-embedding-models">
              <option value="text-embedding-3-small" />
              <option value="text-embedding-3-large" />
              <option value="text-embedding-ada-002" />
            </datalist>
          </label>
          <label className="block text-xs">
            Embedding base URL (puste = ten sam co chat)
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.embedding_base_url ?? ''}
              onChange={(e) => setCfg({ ...cfg, embedding_base_url: e.target.value || null })}
              placeholder={cfg.base_url || 'https://api.openai.com/v1'}
            />
          </label>
          <label className="block text-xs">
            Embedding API key (puste = klucz chatu){' '}
            {cfg.has_embedding_api_key ? '(zostaw puste, by nie zmieniać)' : ''}
            <input
              type="password"
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
              value={embeddingKey}
              onChange={(e) => setEmbeddingKey(e.target.value)}
              placeholder={cfg.has_embedding_api_key ? '••••••••' : ''}
              autoComplete="new-password"
            />
          </label>
        </div>

        <div className="flex flex-wrap gap-2 pt-1">
          <button
            type="submit"
            disabled={busy}
            className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            {busy ? 'Zapisuję…' : 'Zapisz'}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void onTest()}
            className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
          >
            Test połączenia
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void onTestVector()}
            className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
          >
            Test Qdrant / embeddings
          </button>
        </div>
      </form>
    </div>
  )
}
