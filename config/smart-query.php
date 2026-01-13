<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for queries. Set to null to use the
    | default connection, or specify a read-only connection for safety.
    |
    */
    'connection' => env('SMART_QUERY_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the LLM driver and credentials for each provider.
    |
    */
    'llm' => [
        'default' => env('SMART_QUERY_LLM_DRIVER', 'openai'),

        'drivers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('SMART_QUERY_OPENAI_MODEL', 'gpt-4o-mini'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout' => 30,
            ],

            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('SMART_QUERY_ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
                'timeout' => 30,
            ],

            'ollama' => [
                'model' => env('SMART_QUERY_OLLAMA_MODEL', 'llama3.2'),
                'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
                'timeout' => 120,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how database schema is retrieved and processed.
    |
    */
    'schema' => [
        // Tables to include (empty array = all tables)
        'tables' => [],

        // Tables to exclude from schema
        'exclude' => [],

        // Custom table descriptions for better context
        'descriptions' => [
            // 'users' => 'Stores user account information including authentication data',
            // 'orders' => 'Contains customer orders with status and payment information',
        ],

        // Embedding model for smart indexing (Phase 2)
        'embedding_model' => env('SMART_QUERY_EMBEDDING_MODEL', 'text-embedding-3-small'),

        // Schema cache TTL in seconds (0 = no cache)
        'cache_ttl' => env('SMART_QUERY_SCHEMA_CACHE_TTL', 3600),

        // SQLite index file path for smart mode
        'index_path' => env('SMART_QUERY_INDEX_PATH', storage_path('smart-query/schema.sqlite')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings to prevent dangerous queries.
    |
    */
    'security' => [
        // Only allow SELECT queries
        'select_only' => true,

        // Tables that should never be queried
        'forbidden_tables' => [
            'migrations',
            'failed_jobs',
            'password_resets',
            'password_reset_tokens',
            'personal_access_tokens',
            'jobs',
            'job_batches',
            'cache',
            'cache_locks',
            'sessions',
        ],

        // Enable query logging
        'logging' => env('SMART_QUERY_LOGGING', true),

        // Maximum query execution time in seconds
        'max_execution_time' => env('SMART_QUERY_MAX_EXECUTION_TIME', 30),

        // Maximum number of results to return
        'max_results' => env('SMART_QUERY_MAX_RESULTS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Templates
    |--------------------------------------------------------------------------
    |
    | Customize the prompts sent to the LLM for SQL generation.
    |
    */
    'prompts' => [
        'system' => <<<'PROMPT'
You are a SQL expert. Your task is to convert natural language questions into SQL queries.

Rules:
1. Only generate SELECT statements
2. Never use DELETE, UPDATE, INSERT, DROP, ALTER, or any DDL/DML statements
3. Always use proper table and column names from the provided schema
4. Return ONLY the SQL query, no explanations or markdown
5. If the question cannot be answered with the given schema, respond with: -- ERROR: [reason]

Database Schema:
{schema}
PROMPT,

        'user' => <<<'PROMPT'
Convert this question to SQL: {question}
PROMPT,
    ],
];
