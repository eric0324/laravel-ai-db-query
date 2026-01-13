<?php

namespace Eric0324\AIDBQuery\Schema;

use Eric0324\AIDBQuery\Exceptions\SchemaException;
use Eric0324\AIDBQuery\LLM\EmbeddingService;
use PDO;
use PDOException;

/**
 * Schema Indexer for smart mode using SQLite + vector search.
 * Stores table metadata and embeddings for semantic search.
 */
class SchemaIndexer
{
    protected array $config;

    protected ?string $indexPath;

    protected ?PDO $db = null;

    protected ?EmbeddingService $embeddingService = null;

    protected ?SchemaManager $schemaManager = null;

    protected int $dimension;

    protected bool $useVec0 = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->indexPath = $config['index_path'] ?? null;
        $this->dimension = $this->getDimensionForModel($config['embedding_model'] ?? 'text-embedding-3-small');
    }

    /**
     * Set the embedding service.
     */
    public function setEmbeddingService(EmbeddingService $service): self
    {
        $this->embeddingService = $service;
        $this->dimension = $service->getDimension();

        return $this;
    }

    /**
     * Set the schema manager.
     */
    public function setSchemaManager(SchemaManager $manager): self
    {
        $this->schemaManager = $manager;

        return $this;
    }

    /**
     * Get dimension for embedding model.
     */
    protected function getDimensionForModel(string $model): int
    {
        return match ($model) {
            'text-embedding-3-large' => 3072,
            'text-embedding-3-small', 'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }

    /**
     * Get or create the SQLite database connection.
     */
    protected function getDatabase(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        if (! $this->indexPath) {
            throw SchemaException::indexNotFound();
        }

        // Ensure directory exists
        $dir = dirname($this->indexPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $this->db = new PDO("sqlite:{$this->indexPath}");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Try to load sqlite-vec extension
            $this->useVec0 = $this->tryLoadVec0Extension();

            // Initialize schema
            $this->initializeSchema();

            return $this->db;
        } catch (PDOException $e) {
            throw SchemaException::connectionFailed("Failed to open index database: {$e->getMessage()}");
        }
    }

    /**
     * Try to load sqlite-vec extension.
     */
    protected function tryLoadVec0Extension(): bool
    {
        // Common paths for sqlite-vec extension
        $extensionPaths = [
            'vec0',
            '/usr/local/lib/vec0',
            '/usr/lib/sqlite3/vec0',
            getenv('HOME') . '/.local/lib/vec0',
        ];

        foreach ($extensionPaths as $path) {
            try {
                $this->db->exec("SELECT load_extension('{$path}')");

                return true;
            } catch (PDOException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Initialize the database schema.
     */
    protected function initializeSchema(): void
    {
        $db = $this->db;

        // Create metadata table
        $db->exec('
            CREATE TABLE IF NOT EXISTS table_metadata (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT UNIQUE NOT NULL,
                compact_schema TEXT NOT NULL,
                description TEXT,
                schema_hash TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create embeddings table (stores as JSON blob for compatibility)
        $db->exec('
            CREATE TABLE IF NOT EXISTS table_embeddings (
                id INTEGER PRIMARY KEY,
                table_name TEXT UNIQUE NOT NULL,
                embedding BLOB NOT NULL,
                FOREIGN KEY (id) REFERENCES table_metadata(id)
            )
        ');

        // Create index status table
        $db->exec('
            CREATE TABLE IF NOT EXISTS index_status (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                tables_count INTEGER DEFAULT 0,
                dimension INTEGER,
                model TEXT,
                last_updated DATETIME
            )
        ');

        // If vec0 is available, create virtual table
        if ($this->useVec0) {
            try {
                $db->exec("
                    CREATE VIRTUAL TABLE IF NOT EXISTS vec_embeddings USING vec0(
                        embedding float[{$this->dimension}]
                    )
                ");
            } catch (PDOException) {
                $this->useVec0 = false;
            }
        }
    }

    /**
     * Build the schema index.
     *
     * @param  array|null  $tables  Tables to index (null = all tables)
     * @param  bool  $force  Force rebuild even if index exists
     * @return array Index status
     */
    public function index(?array $tables = null, bool $force = false): array
    {
        if (! $this->embeddingService) {
            throw SchemaException::connectionFailed('Embedding service not configured');
        }

        if (! $this->schemaManager) {
            throw SchemaException::connectionFailed('Schema manager not configured');
        }

        $db = $this->getDatabase();

        // Get tables to index
        $tablesToIndex = $tables ?? $this->schemaManager->getTables();

        if (empty($tablesToIndex)) {
            return [
                'status' => 'success',
                'tables_count' => 0,
                'message' => 'No tables to index',
            ];
        }

        $indexed = 0;
        $skipped = 0;
        $errors = [];

        // Prepare statements
        $selectStmt = $db->prepare('SELECT schema_hash FROM table_metadata WHERE table_name = ?');
        $insertMetaStmt = $db->prepare('
            INSERT OR REPLACE INTO table_metadata (table_name, compact_schema, description, schema_hash, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        $insertEmbedStmt = $db->prepare('
            INSERT OR REPLACE INTO table_embeddings (id, table_name, embedding)
            SELECT id, ?, ? FROM table_metadata WHERE table_name = ?
        ');

        // Process tables in batches for embedding API
        $batchSize = 20;
        $batches = array_chunk($tablesToIndex, $batchSize);

        foreach ($batches as $batch) {
            $textsToEmbed = [];
            $tableData = [];

            foreach ($batch as $table) {
                $columns = $this->schemaManager->getTableColumns($table);
                $compactSchema = $this->formatCompactSchema($table, $columns);
                $description = $this->config['descriptions'][$table] ?? null;
                $schemaHash = md5($compactSchema);

                // Check if needs update
                if (! $force) {
                    $selectStmt->execute([$table]);
                    $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && $existing['schema_hash'] === $schemaHash) {
                        $skipped++;

                        continue;
                    }
                }

                // Build text for embedding
                $embeddingText = $this->buildEmbeddingText($table, $compactSchema, $description);
                $textsToEmbed[] = $embeddingText;
                $tableData[] = [
                    'table' => $table,
                    'compact_schema' => $compactSchema,
                    'description' => $description,
                    'schema_hash' => $schemaHash,
                ];
            }

            if (empty($textsToEmbed)) {
                continue;
            }

            // Generate embeddings
            try {
                $embeddings = $this->embeddingService->embed($textsToEmbed);

                // Store in database
                $db->beginTransaction();

                foreach ($tableData as $i => $data) {
                    $insertMetaStmt->execute([
                        $data['table'],
                        $data['compact_schema'],
                        $data['description'],
                        $data['schema_hash'],
                    ]);

                    $embeddingBlob = pack('f*', ...$embeddings[$i]);
                    $insertEmbedStmt->execute([
                        $data['table'],
                        $embeddingBlob,
                        $data['table'],
                    ]);

                    // If vec0 is available, also insert into virtual table
                    if ($this->useVec0) {
                        $this->insertVec0Embedding($data['table'], $embeddings[$i]);
                    }

                    $indexed++;
                }

                $db->commit();
            } catch (\Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = "Batch error: {$e->getMessage()}";
            }
        }

        // Update index status
        $this->updateIndexStatus($indexed + $skipped);

        return [
            'status' => 'success',
            'tables_count' => $indexed + $skipped,
            'indexed' => $indexed,
            'skipped' => $skipped,
            'errors' => $errors,
            'using_vec0' => $this->useVec0,
        ];
    }

    /**
     * Format compact schema for a table.
     */
    protected function formatCompactSchema(string $table, array $columns): string
    {
        $columnDefs = array_map(
            fn ($col) => "{$col['name']}({$col['type']})",
            $columns
        );

        return "{$table}: " . implode(', ', $columnDefs);
    }

    /**
     * Build text for embedding generation.
     */
    protected function buildEmbeddingText(string $table, string $compactSchema, ?string $description): string
    {
        $text = "Table: {$table}\n";
        $text .= "Schema: {$compactSchema}\n";

        if ($description) {
            $text .= "Description: {$description}";
        }

        return $text;
    }

    /**
     * Insert embedding into vec0 virtual table.
     */
    protected function insertVec0Embedding(string $table, array $embedding): void
    {
        $db = $this->db;

        // Get the rowid from table_metadata
        $stmt = $db->prepare('SELECT id FROM table_metadata WHERE table_name = ?');
        $stmt->execute([$table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $vecString = '[' . implode(',', $embedding) . ']';
            $db->exec("INSERT OR REPLACE INTO vec_embeddings (rowid, embedding) VALUES ({$row['id']}, '{$vecString}')");
        }
    }

    /**
     * Update the index status record.
     */
    protected function updateIndexStatus(int $tablesCount): void
    {
        $db = $this->db;
        $model = $this->config['embedding_model'] ?? 'text-embedding-3-small';

        $db->exec("
            INSERT OR REPLACE INTO index_status (id, tables_count, dimension, model, last_updated)
            VALUES (1, {$tablesCount}, {$this->dimension}, '{$model}', CURRENT_TIMESTAMP)
        ");
    }

    /**
     * Get the indexing status.
     */
    public function getStatus(): array
    {
        if (! $this->hasIndex()) {
            return [
                'indexed' => false,
                'tables_count' => 0,
                'last_updated' => null,
                'model' => null,
                'using_vec0' => false,
            ];
        }

        try {
            $db = $this->getDatabase();
            $stmt = $db->query('SELECT * FROM index_status WHERE id = 1');
            $status = $stmt->fetch(PDO::FETCH_ASSOC);

            if (! $status) {
                return [
                    'indexed' => false,
                    'tables_count' => 0,
                    'last_updated' => null,
                    'model' => null,
                    'using_vec0' => $this->useVec0,
                ];
            }

            return [
                'indexed' => true,
                'tables_count' => (int) $status['tables_count'],
                'last_updated' => $status['last_updated'],
                'model' => $status['model'],
                'dimension' => (int) $status['dimension'],
                'using_vec0' => $this->useVec0,
            ];
        } catch (PDOException) {
            return [
                'indexed' => false,
                'tables_count' => 0,
                'last_updated' => null,
                'model' => null,
                'using_vec0' => false,
            ];
        }
    }

    /**
     * Find relevant tables for a question using vector search.
     *
     * @param  string  $question  Natural language question
     * @param  int  $topK  Number of results to return
     * @return array Relevant tables with scores
     */
    public function findRelevantTables(string $question, int $topK = 5): array
    {
        if (! $this->hasIndex() || ! $this->embeddingService) {
            return [];
        }

        try {
            // Generate embedding for the question
            $queryEmbedding = $this->embeddingService->embedSingle($question);

            if (empty($queryEmbedding)) {
                return [];
            }

            // Try vec0 search first, fallback to manual search
            if ($this->useVec0) {
                return $this->vec0Search($queryEmbedding, $topK);
            }

            return $this->manualSearch($queryEmbedding, $topK);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Search using sqlite-vec extension.
     */
    protected function vec0Search(array $queryEmbedding, int $topK): array
    {
        $db = $this->getDatabase();
        $vecString = '[' . implode(',', $queryEmbedding) . ']';

        $stmt = $db->prepare("
            SELECT
                m.table_name,
                m.compact_schema,
                m.description,
                v.distance
            FROM vec_embeddings v
            JOIN table_metadata m ON v.rowid = m.id
            WHERE v.embedding MATCH '{$vecString}'
            ORDER BY v.distance
            LIMIT ?
        ");

        $stmt->execute([$topK]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($row) => [
            'table_name' => $row['table_name'],
            'compact_schema' => $row['compact_schema'],
            'description' => $row['description'],
            'score' => 1 - (float) $row['distance'], // Convert distance to similarity
        ], $results);
    }

    /**
     * Manual cosine similarity search (fallback).
     */
    protected function manualSearch(array $queryEmbedding, int $topK): array
    {
        $db = $this->getDatabase();

        $stmt = $db->query('
            SELECT
                e.table_name,
                e.embedding,
                m.compact_schema,
                m.description
            FROM table_embeddings e
            JOIN table_metadata m ON e.table_name = m.table_name
        ');

        $results = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $embedding = unpack('f*', $row['embedding']);
            $embedding = array_values($embedding);

            $similarity = $this->cosineSimilarity($queryEmbedding, $embedding);

            $results[] = [
                'table_name' => $row['table_name'],
                'compact_schema' => $row['compact_schema'],
                'description' => $row['description'],
                'score' => $similarity,
            ];
        }

        // Sort by similarity descending
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $topK);
    }

    /**
     * Calculate cosine similarity between two vectors.
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Get all indexed tables.
     */
    public function getIndexedTables(): array
    {
        if (! $this->hasIndex()) {
            return [];
        }

        $db = $this->getDatabase();
        $stmt = $db->query('SELECT table_name, compact_schema, description FROM table_metadata ORDER BY table_name');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Clear the index.
     */
    public function clear(): void
    {
        if ($this->indexPath && file_exists($this->indexPath)) {
            unlink($this->indexPath);
        }

        $this->db = null;
    }

    /**
     * Check if index exists.
     */
    public function hasIndex(): bool
    {
        if (! $this->indexPath || ! file_exists($this->indexPath)) {
            return false;
        }

        try {
            $db = $this->getDatabase();
            $stmt = $db->query('SELECT COUNT(*) FROM table_metadata');

            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Get the index file path.
     */
    public function getIndexPath(): ?string
    {
        return $this->indexPath;
    }
}
