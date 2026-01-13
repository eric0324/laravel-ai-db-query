<?php

namespace Eric0324\AIDBQuery\Tests\Unit;

use Eric0324\AIDBQuery\LLM\EmbeddingService;
use Eric0324\AIDBQuery\Schema\SchemaIndexer;
use Eric0324\AIDBQuery\Schema\SchemaManager;
use Eric0324\AIDBQuery\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Mockery;

class SchemaIndexerTest extends TestCase
{
    protected SchemaIndexer $indexer;

    protected SchemaManager $schemaManager;

    protected string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPath = sys_get_temp_dir() . '/smart-query-test-' . uniqid() . '.sqlite';

        $this->indexer = new SchemaIndexer([
            'index_path' => $this->indexPath,
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $this->schemaManager = new SchemaManager([
            'tables' => [],
            'exclude' => [],
            'cache_ttl' => 0,
        ]);

        // Create test tables
        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        DB::statement('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total REAL)');
        DB::statement('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->indexPath)) {
            unlink($this->indexPath);
        }

        parent::tearDown();
    }

    public function test_has_no_index_initially(): void
    {
        $this->assertFalse($this->indexer->hasIndex());
    }

    public function test_get_status_without_index(): void
    {
        $status = $this->indexer->getStatus();

        $this->assertFalse($status['indexed']);
        $this->assertEquals(0, $status['tables_count']);
        $this->assertNull($status['last_updated']);
    }

    public function test_clear_index(): void
    {
        // Create a fake index file
        file_put_contents($this->indexPath, 'test');

        $this->assertTrue(file_exists($this->indexPath));

        $this->indexer->clear();

        $this->assertFalse(file_exists($this->indexPath));
    }

    public function test_index_requires_embedding_service(): void
    {
        $this->expectException(\Eric0324\AIDBQuery\Exceptions\SchemaException::class);

        $this->indexer->setSchemaManager($this->schemaManager);
        $this->indexer->index();
    }

    public function test_index_requires_schema_manager(): void
    {
        $this->expectException(\Eric0324\AIDBQuery\Exceptions\SchemaException::class);

        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->index();
    }

    public function test_index_tables_with_mock_embedding(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);
        $mockEmbedding->shouldReceive('embed')
            ->andReturnUsing(function ($texts) {
                // Return fake embeddings for each text
                return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
            });

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);

        $result = $this->indexer->index();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(3, $result['tables_count']);
        $this->assertTrue($this->indexer->hasIndex());
    }

    public function test_get_indexed_tables(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);
        $mockEmbedding->shouldReceive('embed')
            ->andReturnUsing(function ($texts) {
                return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
            });

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);
        $this->indexer->index();

        $tables = $this->indexer->getIndexedTables();

        $this->assertCount(3, $tables);

        $tableNames = array_column($tables, 'table_name');
        $this->assertContains('users', $tableNames);
        $this->assertContains('orders', $tableNames);
        $this->assertContains('products', $tableNames);
    }

    public function test_find_relevant_tables(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);

        // Different embeddings for each table
        $mockEmbedding->shouldReceive('embed')
            ->andReturnUsing(function ($texts) {
                $embeddings = [];
                foreach ($texts as $i => $text) {
                    // Create distinct embeddings based on table name
                    if (str_contains($text, 'users')) {
                        $embeddings[] = array_fill(0, 1536, 0.9);
                    } elseif (str_contains($text, 'orders')) {
                        $embeddings[] = array_fill(0, 1536, 0.5);
                    } else {
                        $embeddings[] = array_fill(0, 1536, 0.1);
                    }
                }

                return $embeddings;
            });

        // Query embedding for "users"
        $mockEmbedding->shouldReceive('embedSingle')
            ->andReturn(array_fill(0, 1536, 0.9));

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);
        $this->indexer->index();

        $results = $this->indexer->findRelevantTables('How many users registered?', 2);

        $this->assertNotEmpty($results);
        $this->assertEquals('users', $results[0]['table_name']); // Most relevant
    }

    public function test_skips_unchanged_tables_on_reindex(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);
        $mockEmbedding->shouldReceive('embed')
            ->twice() // Should only be called once per index
            ->andReturnUsing(function ($texts) {
                return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
            });

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);

        // First index
        $result1 = $this->indexer->index();
        $this->assertEquals(3, $result1['indexed']);

        // Second index (should skip all)
        $result2 = $this->indexer->index();
        $this->assertEquals(0, $result2['indexed']);
        $this->assertEquals(3, $result2['skipped']);
    }

    public function test_force_reindex(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);
        $mockEmbedding->shouldReceive('embed')
            ->times(2) // Called for both indexes
            ->andReturnUsing(function ($texts) {
                return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
            });

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);

        // First index
        $result1 = $this->indexer->index();
        $this->assertEquals(3, $result1['indexed']);

        // Force reindex
        $result2 = $this->indexer->index(null, true);
        $this->assertEquals(3, $result2['indexed']);
        $this->assertEquals(0, $result2['skipped']);
    }

    public function test_index_specific_tables(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getDimension')->andReturn(1536);
        $mockEmbedding->shouldReceive('embed')
            ->andReturnUsing(function ($texts) {
                return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
            });

        $this->indexer->setEmbeddingService($mockEmbedding);
        $this->indexer->setSchemaManager($this->schemaManager);

        $result = $this->indexer->index(['users', 'orders']);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(2, $result['tables_count']);
    }
}
