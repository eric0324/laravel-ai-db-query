<?php

namespace Eric0324\AIDBQuery\Tests\Feature;

use Eric0324\AIDBQuery\Facades\AskDB;
use Eric0324\AIDBQuery\Schema\SchemaManager;
use Eric0324\AIDBQuery\SmartQuery;
use Eric0324\AIDBQuery\Tests\TestCase;

class SmartQueryTest extends TestCase
{
    public function test_smart_query_is_bound_in_container(): void
    {
        $this->assertInstanceOf(SmartQuery::class, app('smart-query'));
    }

    public function test_facade_resolves_to_smart_query(): void
    {
        $this->assertInstanceOf(SmartQuery::class, AskDB::getFacadeRoot());
    }

    public function test_schema_manager_is_singleton(): void
    {
        $manager1 = app(SchemaManager::class);
        $manager2 = app(SchemaManager::class);

        $this->assertSame($manager1, $manager2);
    }

    public function test_config_is_loaded(): void
    {
        $this->assertNotNull(config('smart-query'));
        $this->assertNotNull(config('smart-query.llm'));
        $this->assertNotNull(config('smart-query.security'));
    }

    public function test_default_llm_driver_is_openai(): void
    {
        $this->assertEquals('openai', config('smart-query.llm.default'));
    }

    public function test_select_only_is_enabled_by_default(): void
    {
        $this->assertTrue(config('smart-query.security.select_only'));
    }

    public function test_forbidden_tables_are_configured(): void
    {
        $forbidden = config('smart-query.security.forbidden_tables');

        $this->assertIsArray($forbidden);
        $this->assertContains('migrations', $forbidden);
        $this->assertContains('password_resets', $forbidden);
    }

    public function test_tables_method_returns_new_instance(): void
    {
        $original = app('smart-query');
        $withTables = AskDB::tables(['users']);

        $this->assertNotSame($original, $withTables);
    }

    public function test_connection_method_returns_new_instance(): void
    {
        $original = app('smart-query');
        $withConnection = AskDB::connection('testing');

        $this->assertNotSame($original, $withConnection);
    }

    public function test_using_method_returns_new_instance(): void
    {
        $original = app('smart-query');
        $withDriver = AskDB::using('openai');

        $this->assertNotSame($original, $withDriver);
    }

    public function test_methods_can_be_chained(): void
    {
        $query = AskDB::tables(['users', 'orders'])
            ->connection('testing')
            ->using('openai');

        $this->assertInstanceOf(SmartQuery::class, $query);
    }
}
