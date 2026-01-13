# Laravel AI DB Query

A Laravel package for querying databases using natural language. Converts natural language questions into SQL using LLM, with intelligent schema handling to solve large database token overflow problems.

## Features

- Natural language to SQL conversion
- Multi-LLM support (OpenAI, Anthropic Claude, Ollama)
- **Smart Mode**: Vector-based schema indexing for large databases
- **Compact Mode**: Fallback for small databases without indexing
- Security mechanisms (SELECT-only, table blacklist, query logging)
- Artisan commands for CLI interaction

## Requirements

- PHP 8.2+
- Laravel 10.0+

## Installation

```bash
composer require eric0324/laravel-ai-db-query
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=smart-query-config
```

## Configuration

Add your LLM API keys to `.env`:

```env
# OpenAI (default) - Required for embeddings and queries
OPENAI_API_KEY=your-openai-api-key

# Anthropic (optional)
ANTHROPIC_API_KEY=your-anthropic-api-key

# Ollama (optional, local)
OLLAMA_BASE_URL=http://localhost:11434
```

## Usage

### Basic Usage

```php
use Eric0324\AIDBQuery\Facades\AskDB;

// Ask a question and get results
$result = AskDB::ask('How many users registered in the last 7 days?');

// Get only the SQL without executing
$sql = AskDB::toSql('List the top 10 users by order amount');

// Get raw result with metadata
$raw = AskDB::raw('What are the most popular products?');
```

### Specify Tables

```php
$result = AskDB::tables(['users', 'orders'])
    ->ask('Show users who made orders over $100');
```

### Use Different Connection

```php
$result = AskDB::connection('readonly')
    ->ask('Count all products');
```

### Use Different LLM Driver

```php
// Use Anthropic Claude
$result = AskDB::using('anthropic')
    ->ask('What is the average order value?');

// Use Ollama (local)
$result = AskDB::using('ollama')
    ->ask('List all categories');
```

### Chain Methods

```php
$result = AskDB::connection('analytics')
    ->using('openai')
    ->tables(['events', 'users'])
    ->ask('How many unique users visited yesterday?');
```

## Smart Mode (Schema Indexing)

For databases with many tables, Smart Mode uses vector embeddings to find only the relevant tables for each query. This reduces token usage and improves accuracy.

### Building the Index

```bash
# Build index for all tables
php artisan smart-query:index

# Build index for specific tables
php artisan smart-query:index --tables=users,orders,products

# Force rebuild (ignore cache)
php artisan smart-query:index --force

# Show index status
php artisan smart-query:index --status

# Test search relevance
php artisan smart-query:index --test="How many users registered?"

# Clear the index
php artisan smart-query:index --clear
```

### How Smart Mode Works

1. **Index Building**: Each table's schema is converted to a text description and embedded using OpenAI's `text-embedding-3-small` model.

2. **Query Processing**: When you ask a question, it's also embedded and compared against the stored table embeddings using cosine similarity.

3. **Relevant Tables**: Only the top matching tables (default: 5) are included in the LLM context, dramatically reducing token usage.

4. **Fallback**: If no index exists, the system automatically uses Compact Mode with all tables.

### Smart Mode vs Compact Mode

| Feature | Smart Mode | Compact Mode |
|---------|-----------|--------------|
| Token Usage | Low (only relevant tables) | High (all tables) |
| Setup Required | Index building | None |
| Best For | Large databases (50+ tables) | Small databases |
| API Calls | Extra embedding call per query | None |

### sqlite-vec Support

The package can optionally use the [sqlite-vec](https://github.com/asg017/sqlite-vec) extension for faster vector searches. If not available, it falls back to pure PHP cosine similarity calculation.

To install sqlite-vec:

```bash
# macOS
brew install asg017/sqlite-vec/sqlite-vec

# Linux (build from source)
git clone https://github.com/asg017/sqlite-vec
cd sqlite-vec && make
```

## Artisan Commands

### Ask Questions

```bash
# Interactive mode
php artisan smart-query:ask

# Direct question
php artisan smart-query:ask "How many users are there?"

# Get SQL only
php artisan smart-query:ask "List all products" --sql

# JSON output
php artisan smart-query:ask "Count orders by status" --json

# Specify driver
php artisan smart-query:ask "Show revenue" --driver=anthropic

# Specify tables
php artisan smart-query:ask "Show user orders" --tables=users,orders
```

### Schema Index Management

```bash
# Build/update index
php artisan smart-query:index

# Show index status
php artisan smart-query:index --status

# Index specific tables
php artisan smart-query:index --tables=users,orders

# Force rebuild
php artisan smart-query:index --force

# Test search
php artisan smart-query:index --test="Find orders by user"

# Clear index
php artisan smart-query:index --clear
```

## Security

The package includes several security features:

- **SELECT-only queries**: Only SELECT statements are allowed by default
- **Table blacklist**: Sensitive tables are blocked (migrations, sessions, etc.)
- **Query logging**: All queries are logged for auditing
- **Max execution time**: Queries timeout after 30 seconds
- **Max results**: Results are limited to 1000 rows

Configure security settings in `config/smart-query.php`:

```php
'security' => [
    'select_only' => true,
    'forbidden_tables' => [
        'migrations',
        'password_resets',
        // ... add your sensitive tables
    ],
    'logging' => true,
    'max_execution_time' => 30,
    'max_results' => 1000,
],
```

## Custom Table Descriptions

Improve query accuracy by adding table descriptions:

```php
'schema' => [
    'descriptions' => [
        'users' => 'User accounts with authentication data',
        'orders' => 'Customer orders with status and payment info',
        'products' => 'Product catalog with pricing',
    ],
],
```

## Configuration Reference

### LLM Drivers

```php
'llm' => [
    'default' => 'openai',

    'drivers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => 'gpt-4o-mini',
            'base_url' => 'https://api.openai.com/v1',
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-sonnet-4-20250514',
        ],

        'ollama' => [
            'model' => 'llama3.2',
            'base_url' => 'http://localhost:11434',
        ],
    ],
],
```

### Schema Settings

```php
'schema' => [
    // Tables to include (empty = all)
    'tables' => [],

    // Tables to exclude
    'exclude' => [],

    // Custom descriptions for better context
    'descriptions' => [],

    // Embedding model for Smart Mode
    'embedding_model' => 'text-embedding-3-small',

    // Schema cache TTL (seconds)
    'cache_ttl' => 3600,

    // Index file path
    'index_path' => storage_path('smart-query/schema.sqlite'),
],
```

## Error Handling

```php
use Eric0324\AIDBQuery\Exceptions\SmartQueryException;
use Eric0324\AIDBQuery\Exceptions\UnsafeQueryException;
use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\Exceptions\SchemaException;

try {
    $result = AskDB::ask('Delete all users'); // Will fail
} catch (UnsafeQueryException $e) {
    // Non-SELECT query attempted
    echo "Security violation: " . $e->getMessage();
    echo "SQL: " . $e->getSql();
} catch (LLMException $e) {
    // LLM connection or response error
    echo "LLM error: " . $e->getMessage();
} catch (SchemaException $e) {
    // Schema or index error
    echo "Schema error: " . $e->getMessage();
} catch (SmartQueryException $e) {
    // General error
    echo "Error: " . $e->getMessage();
}
```

## Programmatic Access

```php
use Eric0324\AIDBQuery\Facades\AskDB;

// Check if Smart Mode is available
$schemaManager = AskDB::getSchemaManager();
if ($schemaManager->isSmartModeAvailable()) {
    echo "Using Smart Mode";
} else {
    echo "Using Compact Mode";
}

// Get current mode
echo $schemaManager->getMode(); // 'smart' or 'compact'

// Access the indexer
$indexer = $schemaManager->getIndexer();
$status = $indexer->getStatus();
```

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
