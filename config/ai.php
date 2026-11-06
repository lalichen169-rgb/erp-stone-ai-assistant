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
    ],

    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Supported: openai, claude. Future: bedrock, gemini, groq (not implemented).
    |
    */

    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),
        'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 30),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'request_timeout' => (int) env('ANTHROPIC_REQUEST_TIMEOUT', 30),
        'max_output_tokens' => (int) env('ANTHROPIC_MAX_OUTPUT_TOKENS', 1024),
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
