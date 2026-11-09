<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant API (Phase 8)
    |--------------------------------------------------------------------------
    */

    'assistant' => [
        'max_message_chars' => (int) env('AI_ASSISTANT_MAX_MESSAGE_CHARS', 4000),
        'rate_limit_per_minute' => (int) env('AI_ASSISTANT_RATE_LIMIT', 60),
        /** Seconds for Cache::lock around one Idempotency-Key turn. */
        'idempotency_lock_seconds' => (int) env('AI_ASSISTANT_IDEMPOTENCY_LOCK_SECONDS', 120),
        /*
        |--------------------------------------------------------------------------
        | Ollama async assistant (Phase 16.6)
        |--------------------------------------------------------------------------
        | job_timeout_seconds should be less than REDIS_QUEUE_RETRY_AFTER (see config/queue.php).
        */
        'async' => [
            'queue_connection' => env('AI_ASSISTANT_QUEUE_CONNECTION', 'redis'),
            'queue' => env('AI_ASSISTANT_QUEUE', 'default'),
            /** Stale threshold must exceed job timeout (worker may still be processing). Default job+180s. */
            'stale_processing_seconds' => (int) env('AI_ASSISTANT_RUN_STALE_SECONDS', 1080),
            /** Default 900s — Redis retry_after must exceed worker/job timeout (see config/queue.php). */
            'job_timeout_seconds' => (int) env('AI_ASSISTANT_JOB_TIMEOUT', 900),
            /** Minimum age before a queued run may be considered for bounded Redis re-dispatch. */
            'queued_run_grace_seconds' => (int) env('AI_ASSISTANT_QUEUED_GRACE_SECONDS', 15),
            /** Do not re-dispatch within this window after a successful enqueue (lease). */
            'queue_dispatch_lease_seconds' => (int) env('AI_ASSISTANT_QUEUE_DISPATCH_LEASE_SECONDS', 120),
            'max_queue_dispatch_attempts' => (int) env('AI_ASSISTANT_MAX_QUEUE_DISPATCH_ATTEMPTS', 5),
            /** After this age, still-queued runs with exhausted dispatch attempts are marked failed. */
            'queued_run_abandon_seconds' => (int) env('AI_ASSISTANT_QUEUED_ABANDON_SECONDS', 3600),
        ],
        /** Experimental: shorter role-specific system text (default off). */
        'compact_system_instructions' => filter_var(
            env('AI_ASSISTANT_COMPACT_SYSTEM_INSTRUCTIONS', env('AI_ASSISTANT_COMPACT_INSTRUCTIONS', false)),
            FILTER_VALIDATE_BOOLEAN
        ),
        /** Experimental: shorter tool descriptions in schemas (default off). */
        'compact_tool_descriptions' => filter_var(env('AI_ASSISTANT_COMPACT_TOOL_DESCRIPTIONS', false), FILTER_VALIDATE_BOOLEAN),
        /**
         * When true, warehouse stock-count questions must call checkStock and the user-facing
         * count must match ERP slabs_on_hand (deterministic lookup overrides model text).
         */
        'enforce_stock_grounding' => filter_var(env('AI_ASSISTANT_ENFORCE_STOCK_GROUNDING', true), FILTER_VALIDATE_BOOLEAN),
        /**
         * When true, "variety across all warehouses" stock questions resolve via ERP searchStockLevels
         * (every thickness and warehouse) without relying on the model to pass all_warehouses.
         */
        'enforce_stock_across_warehouses_disclosure' => filter_var(
            env('AI_ASSISTANT_ENFORCE_STOCK_ACROSS_WAREHOUSES_DISCLOSURE', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /**
         * When true, Sales users asking for supplier bank / wire details get a deterministic
         * role refusal without calling the AI provider.
         */
        'enforce_sales_bank_refusal' => filter_var(env('AI_ASSISTANT_ENFORCE_SALES_BANK_REFUSAL', true), FILTER_VALIDATE_BOOLEAN),
        /**
         * When true, Sales users asking to create or propose purchase orders get a deterministic
         * role refusal without calling the AI provider or creating proposals.
         */
        'enforce_sales_po_creation_refusal' => filter_var(
            env('AI_ASSISTANT_ENFORCE_SALES_PO_CREATION_REFUSAL', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /**
         * When true, draft PO requests missing a warehouse use persisted clarification state
         * and deterministic resume (Purchasing only).
         */
        'enforce_procurement_warehouse_clarification' => filter_var(
            env('AI_ASSISTANT_ENFORCE_PROCUREMENT_WAREHOUSE_CLARIFICATION', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /**
         * When true, Purchasing bank-detail questions with a supplier name resolve via ERP
         * without relying on the model (when a name hint is present).
         */
        'enforce_purchasing_bank_disclosure' => filter_var(env('AI_ASSISTANT_ENFORCE_PURCHASING_BANK_DISCLOSURE', true), FILTER_VALIDATE_BOOLEAN),
        'enforce_purchasing_landed_cost_disclosure' => filter_var(
            env('AI_ASSISTANT_ENFORCE_PURCHASING_LANDED_COST_DISCLOSURE', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /**
         * When true, purchase order list/search questions resolve via ERP search without requiring
         * the model to call searchPurchaseOrders (Sales and Purchasing; no unit costs in reply).
         */
        'enforce_purchase_order_search_disclosure' => filter_var(
            env('AI_ASSISTANT_ENFORCE_PURCHASE_ORDER_SEARCH_DISCLOSURE', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        'enforce_overdue_purchase_order_disclosure' => filter_var(
            env('AI_ASSISTANT_ENFORCE_OVERDUE_PURCHASE_ORDER_DISCLOSURE', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        'enforce_sales_supplier_overdue_po_analysis_refusal' => filter_var(
            env('AI_ASSISTANT_ENFORCE_SALES_SUPPLIER_OVERDUE_PO_ANALYSIS_REFUSAL', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Supported: openai, claude, ollama. Future: bedrock, gemini, groq (not implemented).
    |
    */

    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        // Credentials merged from config/openai.php in AiServiceProvider::boot (load order:
        // ai.php is loaded before openai.php, so config('openai.*') is not available here).
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'request_timeout' => (int) env('ANTHROPIC_REQUEST_TIMEOUT', 30),
        'max_output_tokens' => (int) env('ANTHROPIC_MAX_OUTPUT_TOKENS', 1024),
    ],

    'ollama' => [
        'base_url' => env('AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('AI_OLLAMA_MODEL', 'llama3.1:8b'),
        'request_timeout' => (int) env('AI_OLLAMA_TIMEOUT', 120),
        'connect_timeout' => (int) env('AI_OLLAMA_CONNECT_TIMEOUT', 10),
        'keep_alive' => env('AI_OLLAMA_KEEP_ALIVE', '5m'),
        /** Chat API options (e.g. lower temperature for steadier tool JSON). Not read from .env by default. */
        'chat_options' => [
            'temperature' => 0.1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool-calling loop limits (Phase 6.3)
    |--------------------------------------------------------------------------
    */

    'tool_loop' => [
        'max_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 8),
        'max_tool_calls' => (int) env('AI_MAX_TOOL_CALLS', 32),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations & hybrid context (Phase 7)
    |--------------------------------------------------------------------------
    */

    'conversation' => [
        'context' => [
            'max_messages' => (int) env('AI_CONTEXT_MAX_MESSAGES', 24),
            'max_chars' => (int) env('AI_CONTEXT_MAX_CHARS', 12000),
        ],
        'follow_up' => [
            'use_ai_when_ambiguous' => filter_var(env('AI_FOLLOW_UP_USE_AI', true), FILTER_VALIDATE_BOOLEAN),
            'long_question_min_chars' => (int) env('AI_FOLLOW_UP_LONG_MIN_CHARS', 90),
        ],
        'summary' => [
            'min_messages_for_summary' => (int) env('AI_SUMMARY_MIN_MESSAGES', 4),
            'refresh_message_interval' => (int) env('AI_SUMMARY_REFRESH_INTERVAL', 10),
        ],
    ],

];
