<?php

namespace Eric0324\AIDBQuery\Tests\Unit;

use Eric0324\AIDBQuery\Schema\SchemaManager;
use Eric0324\AIDBQuery\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SchemaManagerTest extends TestCase
{
    protected SchemaManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new SchemaManager([
            'tables' => [],
            'exclude' => [],
            'descriptions' => [
                'users' => 'User accounts',
            ],
            'cache_ttl' => 0,
        ]);

        // Create test tables
        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, created_at TEXT)');
        DB::statement('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total REAL, status TEXT)');
    }

    public function test_gets_tables(): void
    {
        $tables = $this->manager->getTables();

        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    public function test_gets_table_columns(): void
    {
        $columns = $this->manager->getTableColumns('users');

        $this->assertCount(4, $columns);

        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);
    }

    public function test_generates_compact_schema(): void
    {
        $schema = $this->manager->getCompactSchema(['users']);

        $this->assertStringContainsString('users:', $schema);
        $this->assertStringContainsString('id(', $schema);
        $this->assertStringContainsString('name(', $schema);
        $this->assertStringContainsString('-- User accounts', $schema);
    }

    public function test_excludes_tables(): void
    {
        $manager = new SchemaManager([
            'tables' => [],
            'exclude' => ['orders'],
            'cache_ttl' => 0,
        ]);

        $tables = $manager->getTables();

        $this->assertContains('users', $tables);
        $this->assertNotContains('orders', $tables);
    }

    public function test_includes_only_specified_tables(): void
    {
        $manager = new SchemaManager([
            'tables' => ['users'],
            'exclude' => [],
            'cache_ttl' => 0,
        ]);

        $tables = $manager->getTables();

        $this->assertContains('users', $tables);
        $this->assertNotContains('orders', $tables);
    }

    public function test_gets_full_schema(): void
    {
        $schema = $this->manager->getFullSchema(['users']);

        $this->assertArrayHasKey('users', $schema);
        $this->assertArrayHasKey('columns', $schema['users']);
        $this->assertArrayHasKey('description', $schema['users']);
    }
}
