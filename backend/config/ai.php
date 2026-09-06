<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai_compatible'),
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    // Opisy produktów / filtr chrome — pusty = ten sam co model główny
    'enrichment_model' => env('AI_ENRICHMENT_MODEL'),
    // true = wyszukiwanie i opis tylko modelem głównym (AI web search, bez Tavily)
    'enrichment_use_large_model' => (bool) env('AI_ENRICHMENT_USE_LARGE_MODEL', false),
    'timeout_seconds' => (int) env('AI_TIMEOUT', 240),
    'temperature' => (float) env('AI_TEMPERATURE', 0.1),
    // auto | off | none | low | medium | xhigh — auto = low tylko dla Qwen 3.8
    'reasoning_effort' => env('AI_REASONING_EFFORT', 'auto'),
    // Domyślnie WYŁĄCZONE — drogie. Główne źródło: Tavily.
    'web_search_enabled' => (bool) env('AI_WEB_SEARCH_ENABLED', false),
    'tavily_api_key' => env('AI_TAVILY_API_KEY'),
    // tavily | duckduckgo | searxng (własna instancja SearXNG z formatem json)
    'search_engine' => env('AI_SEARCH_ENGINE', 'tavily'),
    'searxng_url' => env('AI_SEARXNG_URL'),
    'search_fallback' => env('AI_SEARCH_FALLBACK', 'none'),
    // eco | balanced | full — zużycie kredytów Tavily przy opisach produktów
    'tavily_search_mode' => env('AI_TAVILY_SEARCH_MODE', 'balanced'),
    // Max produktów do kolejki enrichmentu na jedno kliknięcie (cenniki / lista); min. 1, bez górnego limitu
    'enrichment_batch_limit' => (int) env('AI_ENRICHMENT_BATCH_LIMIT', 5),
    // Ile zapytań AI naraz (1–100): SIWZ i Pobierz z cennika. Zależy od FPM i modelu.
    'match_concurrency' => (int) env('AI_MATCH_CONCURRENCY', 4),
    'catalog_search_limit' => (int) env('AI_CATALOG_SEARCH_LIMIT', 40),
    // Progi dopasowania SIWZ (1-99). Do przestrojenia w panelu: Strojenie AI.
    'match_apply_score' => (int) env('AI_MATCH_APPLY_SCORE', 40),
    'match_substitute_score' => (int) env('AI_MATCH_SUBSTITUTE_SCORE', 55),
    'match_min_score' => (int) env('AI_MATCH_MIN_SCORE', 65),
    // true = wolno wstawić do oferty kartę z zapasowej listy katalogowej (bez oceny modelu)
    'match_allow_catalog_rows' => (bool) env('AI_MATCH_ALLOW_CATALOG_ROWS', false),
    // Telemetria wyszukiwania (tabela search_events) — źródło golden setu i metryk jakości.
    'search_events_enabled' => (bool) env('AI_SEARCH_EVENTS_ENABLED', true),
    // long | short — ile tekstu z karty idzie do modelu w wyszukiwarce AI
    'product_search_card_detail' => env('AI_PRODUCT_SEARCH_CARD_DETAIL', 'long'),
    // Ile sekund worker czeka na wolny slot enrichmentu, zanim odda produkt kolejce.
    'enrichment_slot_wait_seconds' => (int) env('AI_ENRICHMENT_SLOT_WAIT', 5),

    // RAG / Qdrant
    'vector_enabled' => (bool) env('AI_VECTOR_ENABLED', false),
    'qdrant_url' => env('AI_QDRANT_URL', 'http://127.0.0.1:6333'),
    'qdrant_api_key' => env('AI_QDRANT_API_KEY'),
    'qdrant_collection' => env('AI_QDRANT_COLLECTION', 'products'),
    'embedding_model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'embedding_base_url' => env('AI_EMBEDDING_BASE_URL'),
    'embedding_api_key' => env('AI_EMBEDDING_API_KEY'),
    'embedding_provider' => env('AI_EMBEDDING_PROVIDER', 'local'),
    'embedding_cloud_model' => env('AI_EMBEDDING_CLOUD_MODEL'),
    'embedding_cloud_api_key' => env('AI_EMBEDDING_CLOUD_API_KEY'),

    // Żargon SIWZ → frazy z cennika. Edycja w Ustawieniach AI zapisuje kopię do bazy.
    'catalog_slang' => require __DIR__.'/catalog_slang.php',
];
