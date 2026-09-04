import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { clampEnrichmentBatchLimit } from '../lib/aiConcurrency'
import { api } from '../lib/api'

function isKeptSecret(value: string): boolean {
  const v = value.trim()
  return v === '' || v.includes('*')
}

type ReasoningEffort = 'auto' | 'off' | 'none' | 'low' | 'medium' | 'xhigh'

const REASONING_EFFORTS: { id: ReasoningEffort; label: string }[] = [
  { id: 'auto', label: 'Auto (Qwen 3.8 → low)' },
  { id: 'off', label: 'Nie wysyłaj' },
  { id: 'none', label: 'Wyłącz myślenie' },
  { id: 'low', label: 'low' },
  { id: 'medium', label: 'medium' },
  { id: 'xhigh', label: 'xhigh' },
]

/** Odpowiedź sprzed wdrożenia profili nie ma tych pól — bez tego lista by się wysypała. */
function withProfileDefaults(next: AiSettings): AiSettings {
  return {
    ...next,
    reasoning_effort: next.reasoning_effort ?? 'auto',
    product_search_card_detail: next.product_search_card_detail === 'short' ? 'short' : 'long',
    model_profiles: (next.model_profiles ?? []).map((p) => ({
      ...p,
      reasoning_effort: p.reasoning_effort ?? null,
    })),
    ai_tasks: next.ai_tasks ?? [],
  }
}

type SearchEngine = 'tavily' | 'duckduckgo' | 'searxng'

type EmbeddingProvider = 'local' | 'openai' | 'openrouter'

const OPENROUTER_EMBEDDING_MODELS = [
  { id: 'baai/bge-m3', hint: 'wielojęzyczny, 1024 wymiary, ~$0,01/1M' },
  { id: 'qwen/qwen3-embedding-8b', hint: 'wielojęzyczny, mocny, ~$0,01/1M' },
  { id: 'qwen/qwen3-embedding-4b', hint: 'wielojęzyczny, lżejszy, ~$0,02/1M' },
  { id: 'intfloat/multilingual-e5-large', hint: 'klasyk wielojęzyczny, ~$0,01/1M' },
  { id: 'openai/text-embedding-3-small', hint: 'OpenAI przez OpenRouter, ~$0,02/1M' },
  { id: 'openai/text-embedding-3-large', hint: 'OpenAI przez OpenRouter, ~$0,13/1M' },
  { id: 'google/gemini-embedding-2', hint: 'najwyższa jakość, ~$0,20/1M' },
]

const OPENAI_EMBEDDING_MODELS = [
  { id: 'text-embedding-3-small', hint: '1536 wymiarów, ~$0,02/1M' },
  { id: 'text-embedding-3-large', hint: '3072 wymiary, ~$0,13/1M' },
]

type AiTaskInfo = {
  key: string
  label: string
  hint: string
}

type AiModelProfile = {
  id: string
  name: string
  base_url: string | null
  model: string | null
  timeout_seconds: number | null
  temperature: number | null
  reasoning_effort: ReasoningEffort | null
  tasks: string[]
  has_api_key: boolean
  api_key_masked: string | null
}

type AiSettings = {
  enabled: boolean
  provider: string
  base_url: string
  model: string
  enrichment_model: string | null
  enrichment_use_large_model: boolean
  timeout_seconds: number
  temperature: number
  reasoning_effort: ReasoningEffort
  web_search_enabled: boolean
  search_engine: SearchEngine
  searxng_url: string | null
  search_fallback: string
  tavily_search_mode: 'eco' | 'balanced' | 'full'
  enrichment_batch_limit: number
  match_concurrency: number
  product_search_card_detail: 'long' | 'short'
  vector_enabled: boolean
  qdrant_url: string | null
  qdrant_collection: string | null
  embedding_model: string | null
  embedding_base_url: string | null
  embedding_provider: EmbeddingProvider
  embedding_cloud_model: string | null
  embedding_collection: string
  model_profiles: AiModelProfile[]
  ai_tasks: AiTaskInfo[]
  has_api_key: boolean
  has_tavily_api_key: boolean
  has_qdrant_api_key: boolean
  has_embedding_api_key: boolean
  has_embedding_cloud_api_key: boolean
  source: string
  api_key_masked: string | null
  tavily_api_key_masked: string | null
  qdrant_api_key_masked: string | null
  embedding_api_key_masked: string | null
  embedding_cloud_api_key_masked: string | null
}

export function AiSettingsPage() {
  const [cfg, setCfg] = useState<AiSettings | null>(null)
  const [apiKey, setApiKey] = useState('')
  const [tavilyKey, setTavilyKey] = useState('')
  const [qdrantKey, setQdrantKey] = useState('')
  const [embeddingKey, setEmbeddingKey] = useState('')
  const [cloudEmbeddingKey, setCloudEmbeddingKey] = useState('')
  const [profileKeys, setProfileKeys] = useState<Record<string, string>>({})
  const [showApiKey, setShowApiKey] = useState(false)
  const [showTavilyKey, setShowTavilyKey] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [connTests, setConnTests] = useState<
    { id: string; label: string; ok: boolean; message: string }[] | null
  >(null)

  function hydrateSecrets(next: AiSettings) {
    setApiKey('')
    setTavilyKey('')
    setQdrantKey('')
    setEmbeddingKey('')
    setCloudEmbeddingKey('')
    setProfileKeys(Object.fromEntries((next.model_profiles ?? []).map((p) => [p.id, ''])))
  }

  async function load() {
    setErr('')
    try {
      const next = withProfileDefaults(await api<AiSettings>('/ai-settings'))
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

  function setProfiles(next: AiModelProfile[]) {
    setCfg((prev) => (prev ? { ...prev, model_profiles: next } : prev))
  }

  function patchProfile(id: string, patch: Partial<AiModelProfile>) {
    if (!cfg) return
    setProfiles(cfg.model_profiles.map((p) => (p.id === id ? { ...p, ...patch } : p)))
  }

  /** Zadanie ma jednego właściciela — zaznaczenie tutaj zabiera je pozostałym profilom. */
  function toggleTask(id: string, task: string, on: boolean) {
    if (!cfg) return
    setProfiles(
      cfg.model_profiles.map((p) => {
        if (p.id !== id) {
          return on ? { ...p, tasks: p.tasks.filter((t) => t !== task) } : p
        }
        return {
          ...p,
          tasks: on ? [...p.tasks, task] : p.tasks.filter((t) => t !== task),
        }
      })
    )
  }

  function addProfile() {
    if (!cfg) return
    const id = crypto.randomUUID().slice(0, 12)
    setProfiles([
      ...cfg.model_profiles,
      {
        id,
        name: `Profil ${cfg.model_profiles.length + 1}`,
        base_url: null,
        model: null,
        timeout_seconds: null,
        temperature: null,
        reasoning_effort: null,
        tasks: [],
        has_api_key: false,
        api_key_masked: null,
      },
    ])
    setProfileKeys((prev) => ({ ...prev, [id]: '' }))
  }

  function removeProfile(id: string) {
    if (!cfg) return
    setProfiles(cfg.model_profiles.filter((p) => p.id !== id))
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!cfg) return
    setBusy(true)
    setErr('')
    setMsg('')
    setConnTests(null)
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
        reasoning_effort: cfg.reasoning_effort,
        web_search_enabled: cfg.web_search_enabled,
        search_engine: cfg.search_engine || 'tavily',
        searxng_url: cfg.searxng_url?.trim() || null,
        search_fallback: cfg.search_fallback,
        tavily_search_mode: cfg.tavily_search_mode || 'balanced',
        enrichment_batch_limit: cfg.enrichment_batch_limit || 5,
        match_concurrency: cfg.match_concurrency || 4,
        product_search_card_detail: cfg.product_search_card_detail === 'short' ? 'short' : 'long',
        vector_enabled: cfg.vector_enabled,
        qdrant_url: cfg.qdrant_url?.trim() || null,
        qdrant_collection: cfg.qdrant_collection?.trim() || 'products',
        embedding_model: cfg.embedding_model?.trim() || null,
        embedding_base_url: cfg.embedding_base_url?.trim() || null,
        embedding_provider: cfg.embedding_provider || 'local',
        embedding_cloud_model: cfg.embedding_cloud_model?.trim() || null,
        model_profiles: cfg.model_profiles.map((p) => {
          const entry: Record<string, unknown> = {
            id: p.id,
            name: p.name.trim() || 'Profil',
            base_url: p.base_url?.trim() || null,
            model: p.model?.trim() || null,
            timeout_seconds: p.timeout_seconds,
            temperature: p.temperature,
            reasoning_effort: p.reasoning_effort,
            tasks: p.tasks,
          }
          const key = profileKeys[p.id] ?? ''
          if (!isKeptSecret(key)) {
            entry.api_key = key.trim()
          }
          return entry
        }),
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
      if (!isKeptSecret(cloudEmbeddingKey)) {
        body.embedding_cloud_api_key = cloudEmbeddingKey.trim()
      }
      const saved = withProfileDefaults(
        await api<AiSettings>('/ai-settings', {
          method: 'PUT',
          body: JSON.stringify(body),
        })
      )
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
    setConnTests(null)
    try {
      const res = await api<{
        ok: boolean
        message: string
        results: { id: string; label: string; ok: boolean; message: string }[]
      }>('/ai-settings/test', {
        method: 'POST',
        body: '{}',
      })
      setConnTests(res.results ?? [])
      if (res.ok) setMsg('Wszystkie endpointy odpowiadają.')
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

  const isOpenRouter = cfg.embedding_provider === 'openrouter'

  const taskOwner = new Map<string, string>()
  for (const profile of cfg.model_profiles) {
    for (const task of profile.tasks) {
      if (!taskOwner.has(task)) taskOwner.set(task, profile.id)
    }
  }
  const defaultTasks = cfg.ai_tasks.filter((t) => !taskOwner.has(t.key))

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
      {connTests && connTests.length > 0 && (
        <ul className="mb-3 space-y-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs">
          {connTests.map((row) => (
            <li
              key={row.id}
              className={row.ok ? 'text-green-800' : 'text-red-700'}
            >
              <span className="font-semibold">{row.ok ? 'Połączono' : 'Brak połączenia'}</span>
              {' — '}
              {row.label}
              {row.message ? `: ${row.message}` : ''}
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={onSave} className="max-w-3xl rounded-xl bg-white p-4 shadow-sm text-sm space-y-3">
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

        <label className="block text-xs">
          Głębokość myślenia
          <select
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
            value={cfg.reasoning_effort}
            onChange={(e) =>
              setCfg({ ...cfg, reasoning_effort: e.target.value as ReasoningEffort })
            }
          >
            {REASONING_EFFORTS.map((opt) => (
              <option key={opt.id} value={opt.id}>
                {opt.label}
              </option>
            ))}
          </select>
          <span className="mt-1 block text-[11px] text-slate-500">
            Qwen 3.8 domyślnie myśli w xhigh. Auto ustawia low tylko dla Qwen 3.8 — przy zmianie
            na GPT/Gemini nic nie wysyła. Profil może to nadpisać.
          </span>
        </label>

        <div className="rounded border border-slate-200 bg-slate-50 p-3 space-y-3">
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs font-semibold text-slate-700">Profile modeli</p>
            <button
              type="button"
              onClick={addProfile}
              className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px]"
            >
              Dodaj profil
            </button>
          </div>
          <p className="text-[11px] text-slate-500">
            Każde zadanie obsługuje dokładnie jeden profil — zaznaczenie go tutaj zabiera je
            pozostałym. Zadanie niezaznaczone nigdzie idzie do konfiguracji powyżej. Puste pole w
            profilu też schodzi do konfiguracji głównej, więc profil z samym modelem użyje tego
            samego serwera i klucza.
          </p>

          <div className="rounded border border-slate-200 bg-white p-2">
            <p className="text-[11px] font-semibold text-slate-600">
              Konfiguracja główna — {cfg.model || 'brak modelu'}
            </p>
            <p className="mt-1 text-[11px] text-slate-500">
              {defaultTasks.length > 0
                ? defaultTasks.map((t) => t.label).join(' · ')
                : 'Wszystkie zadania przejęły profile.'}
            </p>
          </div>

          {cfg.model_profiles.map((profile) => (
            <div key={profile.id} className="rounded border border-slate-200 bg-white p-2 space-y-2">
              <div className="flex items-center gap-2">
                <input
                  className="flex-1 rounded border border-slate-300 px-2 py-1 text-xs"
                  value={profile.name}
                  onChange={(e) => patchProfile(profile.id, { name: e.target.value })}
                  placeholder="Nazwa profilu"
                />
                <button
                  type="button"
                  onClick={() => removeProfile(profile.id)}
                  className="rounded border border-red-200 px-2 py-1 text-[11px] text-red-700"
                >
                  Usuń
                </button>
              </div>

              <label className="block text-[11px]">
                Base URL
                <input
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                  value={profile.base_url ?? ''}
                  onChange={(e) => patchProfile(profile.id, { base_url: e.target.value || null })}
                  placeholder={cfg.base_url || 'jak w konfiguracji głównej'}
                />
              </label>

              <label className="block text-[11px]">
                Model
                <input
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                  value={profile.model ?? ''}
                  onChange={(e) => patchProfile(profile.id, { model: e.target.value || null })}
                  placeholder={cfg.model || 'jak w konfiguracji głównej'}
                  list="ai-models"
                />
              </label>

              <label className="block text-[11px]">
                Klucz API {profile.has_api_key ? '(zostaw puste, by nie zmieniać)' : ''}
                <input
                  type="password"
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono"
                  value={profileKeys[profile.id] ?? ''}
                  onChange={(e) =>
                    setProfileKeys((prev) => ({ ...prev, [profile.id]: e.target.value }))
                  }
                  placeholder={profile.has_api_key ? '••••••••' : 'puste = klucz z konfiguracji głównej'}
                  autoComplete="new-password"
                />
              </label>

              <div className="grid gap-2 sm:grid-cols-2">
                <label className="block text-[11px]">
                  Timeout (s)
                  <input
                    type="number"
                    min={10}
                    max={600}
                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    value={profile.timeout_seconds ?? ''}
                    onChange={(e) =>
                      patchProfile(profile.id, {
                        timeout_seconds: e.target.value === '' ? null : Number(e.target.value),
                      })
                    }
                    placeholder={String(cfg.timeout_seconds)}
                  />
                </label>
                <label className="block text-[11px]">
                  Temperature
                  <input
                    type="number"
                    step="0.1"
                    min={0}
                    max={2}
                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                    value={profile.temperature ?? ''}
                    onChange={(e) =>
                      patchProfile(profile.id, {
                        temperature: e.target.value === '' ? null : Number(e.target.value),
                      })
                    }
                    placeholder={String(cfg.temperature)}
                  />
                </label>
              </div>

              <label className="block text-[11px]">
                Głębokość myślenia
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                  value={profile.reasoning_effort ?? ''}
                  onChange={(e) =>
                    patchProfile(profile.id, {
                      reasoning_effort:
                        e.target.value === '' ? null : (e.target.value as ReasoningEffort),
                    })
                  }
                >
                  <option value="">
                    jak główna (
                    {REASONING_EFFORTS.find((o) => o.id === cfg.reasoning_effort)?.label ??
                      cfg.reasoning_effort}
                    )
                  </option>
                  {REASONING_EFFORTS.map((opt) => (
                    <option key={opt.id} value={opt.id}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </label>

              <fieldset>
                <legend className="text-[11px] font-semibold text-slate-600">Zadania</legend>
                <div className="mt-1 grid gap-1 sm:grid-cols-2">
                  {cfg.ai_tasks.map((task) => {
                    const owner = taskOwner.get(task.key)
                    const takenByOther = owner !== undefined && owner !== profile.id
                    return (
                      <label
                        key={task.key}
                        title={task.hint}
                        className="flex items-start gap-1.5 text-[11px] text-slate-700"
                      >
                        <input
                          type="checkbox"
                          className="mt-0.5"
                          checked={profile.tasks.includes(task.key)}
                          onChange={(e) => toggleTask(profile.id, task.key, e.target.checked)}
                        />
                        <span className={takenByOther ? 'text-slate-400' : ''}>{task.label}</span>
                      </label>
                    )
                  })}
                </div>
              </fieldset>
            </div>
          ))}
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
              ? '(nieużywany — Tavily jest wyłączone)'
              : cfg.has_tavily_api_key
                ? '(zostaw puste, by nie zmieniać)'
                : '* (wymagany tylko przy silniku Tavily)'}
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
            Max produktów w kolejce enrichmentu (min. 1)
            <input
              type="number"
              min={1}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.enrichment_batch_limit ?? 5}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  enrichment_batch_limit: clampEnrichmentBatchLimit(Number(e.target.value) || 5),
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
              To jest limit równoległych zapytań do modelu (llama-swap), nie liczba workerów
              wyszukiwarki. Ustaw 16, jeśli model ma 16 slotów — przy 1–2 w panelu llama-swap
              pokaże 1–2 in-flight niezależnie od 16 workerów.
            </span>
          </label>
          <label className="block text-xs">
            Karty produktów dla modelu (wyszukiwarka AI)
            <select
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.product_search_card_detail || 'long'}
              onChange={(e) =>
                setCfg({
                  ...cfg,
                  product_search_card_detail: e.target.value === 'short' ? 'short' : 'long',
                })
              }
            >
              <option value="long">Długi opis → model (obecny, dokładniejszy)</option>
              <option value="short">Krótki opis → model (szybszy)</option>
            </select>
            <span className="mt-1 block text-[11px] text-slate-500">
              {cfg.product_search_card_detail === 'short'
                ? 'Model dostaje SKU, nazwę, normy, °C i 1–2 specy. RAG bez zmian — porównaj jakość z długim.'
                : 'Model dostaje pełny opis, cechy i zastosowania (wolniej, lepiej łapie szczegóły z opisu).'}
            </span>
          </label>
          <p className="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
            Słownik żargonu SIWZ jest w{' '}
            <Link to="/admin/zargon" className="font-semibold text-blue-700 underline">
              Administracja → Żargon SIWZ
            </Link>
            .
          </p>
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
            Dostawca embeddingów
            <select
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.embedding_provider ?? 'local'}
              onChange={(e) =>
                setCfg({ ...cfg, embedding_provider: e.target.value as EmbeddingProvider })
              }
            >
              <option value="local">Serwer lokalny (OpenAI-compatible)</option>
              <option value="openrouter">OpenRouter (chmura)</option>
              <option value="openai">OpenAI (chmura)</option>
            </select>
            <span className="mt-1 block text-[11px] text-slate-500">
              Każdy dostawca ma własną kolekcję w Qdrant (inny wymiar wektora). Aktywna:{' '}
              <code>{cfg.embedding_collection}</code>. Po przełączeniu zrób{' '}
              <code>php artisan products:reindex-embeddings --force</code>.
            </span>
          </label>

          {cfg.embedding_provider !== 'local' ? (
            <>
              <label className="block text-xs">
                Model embeddings ({isOpenRouter ? 'OpenRouter' : 'OpenAI'})
                <input
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                  value={cfg.embedding_cloud_model ?? ''}
                  onChange={(e) =>
                    setCfg({ ...cfg, embedding_cloud_model: e.target.value || null })
                  }
                  placeholder={
                    isOpenRouter ? 'openai/text-embedding-3-small' : 'text-embedding-3-small'
                  }
                  list="ai-cloud-embedding-models"
                />
                <datalist id="ai-cloud-embedding-models">
                  {(isOpenRouter ? OPENROUTER_EMBEDDING_MODELS : OPENAI_EMBEDDING_MODELS).map(
                    (m) => (
                      <option key={m.id} value={m.id}>
                        {m.hint}
                      </option>
                    )
                  )}
                </datalist>
                {isOpenRouter ? (
                  <span className="mt-1 block text-[11px] text-slate-500">
                    Do polskich opisów najlepiej <code>baai/bge-m3</code> albo{' '}
                    <code>qwen/qwen3-embedding-8b</code> — ok. $0,01 za milion tokenów. Pełna lista:{' '}
                    <code>https://openrouter.ai/api/v1/embeddings/models</code>.
                  </span>
                ) : null}
              </label>
              <label className="block text-xs">
                Klucz {isOpenRouter ? 'OpenRouter' : 'OpenAI'} (embeddingi){' '}
                {cfg.has_embedding_cloud_api_key ? '(zostaw puste, by nie zmieniać)' : ''}
                <input
                  type="password"
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
                  value={cloudEmbeddingKey}
                  onChange={(e) => setCloudEmbeddingKey(e.target.value)}
                  placeholder={
                    cfg.has_embedding_cloud_api_key ? '••••••••' : isOpenRouter ? 'sk-or-v1-…' : 'sk-…'
                  }
                  autoComplete="new-password"
                />
                <span className="mt-1 block text-[11px] text-slate-500">
                  Adres stały:{' '}
                  <code>
                    {isOpenRouter ? 'https://openrouter.ai/api/v1' : 'https://api.openai.com/v1'}
                  </code>
                  .{' '}
                  {isOpenRouter
                    ? 'Puste pole = klucz czatu, jeśli czat też chodzi po OpenRouter.'
                    : 'Klucz z platform.openai.com, klucz OpenRoutera tu nie zadziała.'}
                </span>
              </label>
            </>
          ) : (
            <>
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
                <span className="mt-1 block text-[11px] text-slate-500">
                  llama-server musi być uruchomiony z <code>--embeddings</code>, inaczej zwraca HTTP
                  501.
                </span>
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
            </>
          )}
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
