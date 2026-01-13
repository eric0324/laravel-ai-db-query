<?php

namespace Eric0324\AIDBQuery\Tests\Unit;

use Eric0324\AIDBQuery\Exceptions\UnsafeQueryException;
use Eric0324\AIDBQuery\Security\QueryGuard;
use Eric0324\AIDBQuery\Tests\TestCase;

class QueryGuardTest extends TestCase
{
    protected QueryGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new QueryGuard([
            'select_only' => true,
            'forbidden_tables' => ['migrations', 'sessions', 'password_resets'],
        ]);
    }

    public function test_allows_select_queries(): void
    {
        $this->guard->validate('SELECT * FROM users');
        $this->guard->validate('SELECT id, name FROM users WHERE active = 1');
        $this->guard->validate('SELECT COUNT(*) FROM orders');

        $this->assertTrue(true); // No exception thrown
    }

    public function test_blocks_delete_queries(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('DELETE FROM users WHERE id = 1');
    }

    public function test_blocks_update_queries(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('UPDATE users SET name = "test"');
    }

    public function test_blocks_insert_queries(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('INSERT INTO users (name) VALUES ("test")');
    }

    public function test_blocks_drop_queries(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('DROP TABLE users');
    }

    public function test_blocks_forbidden_tables(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('SELECT * FROM migrations');
    }

    public function test_blocks_forbidden_tables_in_join(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('SELECT * FROM users JOIN sessions ON users.id = sessions.user_id');
    }

    public function test_extracts_sql_from_markdown(): void
    {
        $response = "```sql\nSELECT * FROM users\n```";
        $sql = $this->guard->extractSql($response);

        $this->assertEquals('SELECT * FROM users', $sql);
    }

    public function test_detects_error_response(): void
    {
        $response = '-- ERROR: Cannot answer this question';

        $this->assertTrue($this->guard->isErrorResponse($response));
        $this->assertEquals('Cannot answer this question', $this->guard->getErrorMessage($response));
    }

    public function test_is_select_only(): void
    {
        $this->assertTrue($this->guard->isSelectOnly('SELECT * FROM users'));
        $this->assertTrue($this->guard->isSelectOnly('  SELECT id FROM orders  '));
        $this->assertFalse($this->guard->isSelectOnly('DELETE FROM users'));
        $this->assertFalse($this->guard->isSelectOnly('UPDATE users SET x = 1'));
    }
}
