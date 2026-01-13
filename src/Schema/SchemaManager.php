<?php

namespace Eric0324\AIDBQuery\Schema;

use Eric0324\AIDBQuery\Exceptions\SchemaException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SchemaManager
{
    protected ?Connection $connection = null;

    protected array $config;

    protected ?SchemaIndexer $indexer = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Set the database connection to use.
     */
    public function setConnection(?string $connection): self
    {
        $this->connection = $connection ? DB::connection($connection) : null;

        return $this;
    }

    /**
     * Get the database connection.
     */
    protected function getConnection(): Connection
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Set the schema indexer for smart mode.
     */
    public function setIndexer(?SchemaIndexer $indexer): self
    {
        $this->indexer = $indexer;

        return $this;
    }

    /**
     * Get schema for a natural language question.
     * Uses smart indexing if available, otherwise falls back to compact format.
     */
    public function getSchemaForQuestion(string $question, ?array $tables = null): string
    {
        // If specific tables are provided, use them directly
        if (! empty($tables)) {
            return $this->getCompactSchema($tables);
        }

        // Try smart mode if indexer is available and has index
        if ($this->indexer && $this->indexer->hasIndex()) {
            $relevantTables = $this->indexer->findRelevantTables($question);

            if (! empty($relevantTables)) {
                $tableNames = array_column($relevantTables, 'table_name');

                return $this->getCompactSchema($tableNames);
            }
        }

        // Fallback to compact format with all allowed tables
        return $this->getCompactSchema($this->getTables());
    }

    /**
     * Get compact schema format for the given tables.
     * Format: table_name: col1(type), col2(type), ...
     */
    public function getCompactSchema(array $tables): string
    {
        $schemas = [];

        foreach ($tables as $table) {
            $columns = $this->getTableColumns($table);

            if (empty($columns)) {
                continue;
            }

            $columnDefs = [];
            foreach ($columns as $column) {
                $columnDefs[] = "{$column['name']}({$column['type']})";
            }

            $description = $this->config['descriptions'][$table] ?? null;
            $tableSchema = "{$table}: " . implode(', ', $columnDefs);

            if ($description) {
                $tableSchema .= " -- {$description}";
            }

            $schemas[] = $tableSchema;
        }

        return implode("\n", $schemas);
    }

    /**
     * Get all available table names from the database.
     */
    public function getTables(): array
    {
        $cacheKey = 'smart-query:tables:' . $this->getConnection()->getName();
        $cacheTtl = $this->config['cache_ttl'] ?? 3600;

        if ($cacheTtl > 0) {
            return Cache::remember($cacheKey, $cacheTtl, fn () => $this->fetchTables());
        }

        return $this->fetchTables();
    }

    /**
     * Fetch tables from the database.
     */
    protected function fetchTables(): array
    {
        $tables = $this->getTablesFromInformationSchema();

        // Apply include filter
        $includeTables = $this->config['tables'] ?? [];
        if (! empty($includeTables)) {
            $tables = array_intersect($tables, $includeTables);
        }

        // Apply exclude filter
        $excludeTables = $this->config['exclude'] ?? [];
        $tables = array_diff($tables, $excludeTables);

        return array_values($tables);
    }

    /**
     * Get tables from information schema as fallback.
     */
    protected function getTablesFromInformationSchema(): array
    {
        $connection = $this->getConnection();
        $driverName = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        $tables = match ($driverName) {
            'mysql', 'mariadb' => $connection->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE']
            ),
            'pgsql' => $connection->select(
                "SELECT tablename AS table_name FROM pg_catalog.pg_tables WHERE schemaname = 'public'"
            ),
            'sqlite' => $connection->select(
                "SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            ),
            'sqlsrv' => $connection->select(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = ?',
                ['BASE TABLE']
            ),
            default => throw SchemaException::connectionFailed("Unsupported database driver: {$driverName}"),
        };

        return array_map(fn ($row) => $row->table_name ?? $row->TABLE_NAME ?? $row->tablename ?? $row->name, $tables);
    }

    /**
     * Get column information for a specific table.
     */
    public function getTableColumns(string $table): array
    {
        $cacheKey = "smart-query:columns:{$this->getConnection()->getName()}:{$table}";
        $cacheTtl = $this->config['cache_ttl'] ?? 3600;

        if ($cacheTtl > 0) {
            return Cache::remember($cacheKey, $cacheTtl, fn () => $this->fetchTableColumns($table));
        }

        return $this->fetchTableColumns($table);
    }

    /**
     * Fetch column information from the database.
     */
    protected function fetchTableColumns(string $table): array
    {
        $connection = $this->getConnection();
        $driverName = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        $columns = match ($driverName) {
            'mysql', 'mariadb' => $connection->select(
                'SELECT COLUMN_NAME as name, DATA_TYPE as type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$database, $table]
            ),
            'pgsql' => $connection->select(
                'SELECT column_name as name, data_type as type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                ['public', $table]
            ),
            'sqlite' => $this->getSqliteColumns($table),
            'sqlsrv' => $connection->select(
                'SELECT COLUMN_NAME as name, DATA_TYPE as type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            ),
            default => [],
        };

        return array_map(fn ($col) => [
            'name' => $col->name ?? $col->COLUMN_NAME ?? $col->column_name,
            'type' => $col->type ?? $col->DATA_TYPE ?? $col->data_type,
        ], $columns);
    }

    /**
     * Get columns for SQLite database.
     */
    protected function getSqliteColumns(string $table): array
    {
        $columns = $this->getConnection()->select("PRAGMA table_info({$table})");

        return array_map(fn ($col) => (object) [
            'name' => $col->name,
            'type' => strtolower($col->type),
        ], $columns);
    }

    /**
     * Clear all schema caches.
     */
    public function clearCache(): void
    {
        $connectionName = $this->getConnection()->getName();

        // Clear tables cache
        Cache::forget("smart-query:tables:{$connectionName}");

        // Clear column caches for all tables
        foreach ($this->fetchTables() as $table) {
            Cache::forget("smart-query:columns:{$connectionName}:{$table}");
        }
    }

    /**
     * Get full schema with detailed information.
     */
    public function getFullSchema(?array $tables = null): array
    {
        $tables = $tables ?? $this->getTables();
        $schema = [];

        foreach ($tables as $table) {
            $schema[$table] = [
                'columns' => $this->getTableColumns($table),
                'description' => $this->config['descriptions'][$table] ?? null,
            ];
        }

        return $schema;
    }

    /**
     * Get the schema indexer instance.
     */
    public function getIndexer(): ?SchemaIndexer
    {
        return $this->indexer;
    }

    /**
     * Check if smart mode is available (index exists and can be used).
     */
    public function isSmartModeAvailable(): bool
    {
        return $this->indexer !== null && $this->indexer->hasIndex();
    }

    /**
     * Get the mode being used (smart or compact).
     */
    public function getMode(): string
    {
        return $this->isSmartModeAvailable() ? 'smart' : 'compact';
    }
}
