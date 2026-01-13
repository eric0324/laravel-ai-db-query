<?php

namespace Eric0324\AIDBQuery\Security;

use Eric0324\AIDBQuery\Exceptions\UnsafeQueryException;

class QueryGuard
{
    protected array $config;

    /**
     * Dangerous SQL patterns to detect.
     */
    protected array $dangerousPatterns = [
        '/;\s*(?:DROP|DELETE|UPDATE|INSERT|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)/i',
        '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        '/\bSLEEP\s*\(/i',
        '/\bWAITFOR\s+DELAY\b/i',
        '/--\s*$/m',  // SQL comment at end of line (potential injection)
        '/\/\*.*\*\//s',  // Block comments
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Validate a SQL query for safety.
     *
     * @throws UnsafeQueryException
     */
    public function validate(string $sql): void
    {
        // Remove leading/trailing whitespace and normalize
        $sql = trim($sql);

        // Remove markdown code blocks if present
        $sql = $this->cleanMarkdown($sql);

        // Check for SELECT only if enabled
        if ($this->config['select_only'] ?? true) {
            if (! $this->isSelectOnly($sql)) {
                throw UnsafeQueryException::nonSelectQuery($sql);
            }
        }

        // Check for forbidden tables
        if ($this->containsForbiddenTables($sql)) {
            $table = $this->findForbiddenTable($sql);
            throw UnsafeQueryException::forbiddenTable($sql, $table);
        }

        // Check for dangerous patterns
        foreach ($this->dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw UnsafeQueryException::dangerousPattern($sql, $pattern);
            }
        }
    }

    /**
     * Clean markdown formatting from SQL.
     */
    protected function cleanMarkdown(string $sql): string
    {
        // Remove ```sql ... ``` blocks
        $sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
        $sql = preg_replace('/\s*```$/', '', $sql);

        return trim($sql);
    }

    /**
     * Check if the query is SELECT only.
     */
    public function isSelectOnly(string $sql): bool
    {
        $sql = $this->cleanMarkdown($sql);

        // Check if starts with SELECT (case-insensitive)
        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            return false;
        }

        // Check for data modification keywords
        $modifyKeywords = [
            'INSERT',
            'UPDATE',
            'DELETE',
            'DROP',
            'ALTER',
            'CREATE',
            'TRUNCATE',
            'REPLACE',
            'MERGE',
            'GRANT',
            'REVOKE',
        ];

        foreach ($modifyKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $sql)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the query contains forbidden tables.
     */
    public function containsForbiddenTables(string $sql): bool
    {
        $forbiddenTables = $this->config['forbidden_tables'] ?? [];

        foreach ($forbiddenTables as $table) {
            // Match table name as a word boundary
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the first forbidden table in the query.
     */
    protected function findForbiddenTable(string $sql): string
    {
        $forbiddenTables = $this->config['forbidden_tables'] ?? [];

        foreach ($forbiddenTables as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                return $table;
            }
        }

        return 'unknown';
    }

    /**
     * Extract SQL from LLM response (handles markdown, comments, etc.).
     */
    public function extractSql(string $response): string
    {
        $response = trim($response);

        // Check for error response
        if (preg_match('/^--\s*ERROR:\s*(.+)$/m', $response, $matches)) {
            return $response;
        }

        // Remove markdown code blocks
        $response = $this->cleanMarkdown($response);

        // Remove leading explanatory text before SELECT
        if (preg_match('/SELECT\b.+$/is', $response, $matches)) {
            $response = $matches[0];
        }

        // Remove trailing semicolon if present
        $response = rtrim($response, ';');

        return trim($response);
    }

    /**
     * Check if the response indicates an error from the LLM.
     */
    public function isErrorResponse(string $sql): bool
    {
        return str_starts_with(trim($sql), '-- ERROR:');
    }

    /**
     * Extract error message from error response.
     */
    public function getErrorMessage(string $sql): string
    {
        if (preg_match('/^--\s*ERROR:\s*(.+)$/m', $sql, $matches)) {
            return trim($matches[1]);
        }

        return 'Unknown error';
    }
}
