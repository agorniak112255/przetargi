import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type AiSettings = {
  enabled: boolean
  provider: string
  base_url: string
  model: string
  enrichment_model: string | null
  timeout_seconds: number
  temperature: number
  web_search_enabled: boolean
  search_fallback: string
  has_api_key: boolean
  has_tavily_api_key: boolean
  source: string
  api_key_masked: string | null
  tavily_api_key_masked: string | null
}

export function AiSettingsPage() {
  const [cfg, setCfg] = useState<AiSettings | null>(null)
  const [apiKey, setApiKey] = useState('')
  const [tavilyKey, setTavilyKey] = useState('')
  const [showApiKey, setShowApiKey] = useState(false)
  const [showTavilyKey, setShowTavilyKey] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')

  async function load() {
    setCfg(await api<AiSettings>('/ai-settings'))
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
        timeout_seconds: cfg.timeout_seconds,
        temperature: cfg.temperature,
        web_search_enabled: cfg.web_search_enabled,
        search_fallback: cfg.search_fallback,
      }
      if (apiKey.trim() !== '') {
        body.api_key = apiKey.trim()
      }
      if (tavilyKey.trim() !== '') {
        body.tavily_api_key = tavilyKey.trim()
      }
      const saved = await api<AiSettings>('/ai-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setCfg(saved)
      setApiKey('')
      setTavilyKey('')
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

  if (!cfg) {
    return <p className="text-sm text-slate-500">Ładowanie ustawień AI…</p>
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
            autoComplete="off"
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
          <p className="text-xs font-semibold text-slate-700">Wyszukiwanie opisów produktów (tanio)</p>
          <p className="text-[11px] text-slate-500">
            Domyślnie: Tavily → filtr/opis tanim modelem → cache po SKU. Puste pole = model główny z
            góry. Drogi AI web search tylko jako awaria.
          </p>
          <label className="block text-xs">
            Tani model (opisy produktów)
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={cfg.enrichment_model ?? ''}
              onChange={(e) => setCfg({ ...cfg, enrichment_model: e.target.value || null })}
              placeholder={cfg.model || 'np. openai/gpt-4o-mini'}
              list="ai-enrichment-models"
            />
            <datalist id="ai-enrichment-models">
              <option value="openai/gpt-4o-mini" />
              <option value="google/gemini-2.0-flash-001" />
              <option value="meta-llama/llama-3.3-70b-instruct" />
            </datalist>
          </label>
          <label className="block text-xs">
            Klucz Tavily *{' '}
            {cfg.has_tavily_api_key ? '(zostaw puste, by nie zmieniać)' : '(wymagany do pobierania)'}
            <input
              type={showTavilyKey ? 'text' : 'password'}
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono"
              value={tavilyKey}
              onChange={(e) => setTavilyKey(e.target.value)}
              placeholder={cfg.has_tavily_api_key ? '••••••••' : 'tvly-…'}
              autoComplete="off"
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
          <label className="flex items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={cfg.web_search_enabled ?? false}
              onChange={(e) => setCfg({ ...cfg, web_search_enabled: e.target.checked })}
            />
            Drogi fallback: AI web search (OpenRouter Responses) — tylko gdy Tavily zawiedzie
          </label>
        </div>

        <div className="flex gap-2 pt-1">
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
        </div>
      </form>
    </div>
  )
}
