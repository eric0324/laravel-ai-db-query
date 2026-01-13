<?php

namespace Eric0324\AIDBQuery;

use Eric0324\AIDBQuery\Exceptions\SmartQueryException;
use Eric0324\AIDBQuery\Exceptions\UnsafeQueryException;
use Eric0324\AIDBQuery\LLM\LLMManager;
use Eric0324\AIDBQuery\Schema\SchemaManager;
use Eric0324\AIDBQuery\Security\QueryGuard;
use Eric0324\AIDBQuery\Security\QueryLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartQuery
{
    protected LLMManager $llmManager;

    protected SchemaManager $schemaManager;

    protected QueryGuard $queryGuard;

    protected QueryLogger $queryLogger;

    protected array $config;

    protected ?string $connection = null;

    protected ?string $driver = null;

    protected array $tables = [];

    public function __construct(
        LLMManager $llmManager,
        SchemaManager $schemaManager,
        QueryGuard $queryGuard,
        QueryLogger $queryLogger,
        array $config = []
    ) {
        $this->llmManager = $llmManager;
        $this->schemaManager = $schemaManager;
        $this->queryGuard = $queryGuard;
        $this->queryLogger = $queryLogger;
        $this->config = $config;
    }

    /**
     * Specify tables to use for the query.
     */
    public function tables(array $tables): self
    {
        $clone = clone $this;
        $clone->tables = $tables;

        return $clone;
    }

    /**
     * Specify the database connection to use.
     */
    public function connection(string $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    /**
     * Specify the LLM driver to use.
     */
    public function using(string $driver): self
    {
        $clone = clone $this;
        $clone->driver = $driver;

        return $clone;
    }

    /**
     * Execute a natural language query and return results as a Collection.
     *
     * @throws SmartQueryException
     */
    public function ask(string $question): Collection
    {
        $result = $this->raw($question);

        return collect($result['data']);
    }

    /**
     * Convert a natural language question to SQL without executing.
     *
     * @throws SmartQueryException
     */
    public function toSql(string $question): string
    {
        return $this->generateSql($question);
    }

    /**
     * Execute a natural language query and return raw result with metadata.
     *
     * @throws SmartQueryException
     */
    public function raw(string $question): array
    {
        $startTime = microtime(true);
        $driverName = $this->driver ?? $this->llmManager->getDefaultDriver();

        try {
            $sql = $this->generateSql($question);

            // Check for LLM error response
            if ($this->queryGuard->isErrorResponse($sql)) {
                throw new SmartQueryException($this->queryGuard->getErrorMessage($sql));
            }

            // Execute the query
            $data = $this->executeQuery($sql);
            $duration = microtime(true) - $startTime;

            // Log successful query
            $this->queryLogger->logQuery(
                $question,
                $sql,
                $driverName,
                $duration,
                count($data)
            );

            return [
                'question' => $question,
                'sql' => $sql,
                'data' => $data,
                'count' => count($data),
                'duration' => $duration,
                'driver' => $driverName,
            ];
        } catch (UnsafeQueryException $e) {
            $this->queryLogger->logSecurityViolation($question, $e->getSql(), $e->getViolation());

            throw $e;
        } catch (SmartQueryException $e) {
            $duration = microtime(true) - $startTime;
            $this->queryLogger->logQuery($question, '', $driverName, $duration, null, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate SQL from a natural language question.
     */
    protected function generateSql(string $question): string
    {
        // Set up schema manager with current connection
        $this->schemaManager->setConnection($this->connection);

        // Get schema for the question
        $schema = $this->schemaManager->getSchemaForQuestion(
            $question,
            ! empty($this->tables) ? $this->tables : null
        );

        // Build prompts
        $systemPrompt = str_replace(
            '{schema}',
            $schema,
            $this->config['prompts']['system'] ?? ''
        );

        $userPrompt = str_replace(
            '{question}',
            $question,
            $this->config['prompts']['user'] ?? 'Convert this question to SQL: {question}'
        );

        // Get LLM response
        $llm = $this->llmManager->driver($this->driver);
        $response = $llm->complete($systemPrompt, $userPrompt);

        // Extract and validate SQL
        $sql = $this->queryGuard->extractSql($response);
        $this->queryGuard->validate($sql);

        return $sql;
    }

    /**
     * Execute the SQL query and return results.
     */
    protected function executeQuery(string $sql): array
    {
        $connection = $this->connection
            ? DB::connection($this->connection)
            : DB::connection();

        // Apply max execution time
        $maxTime = $this->config['security']['max_execution_time'] ?? 30;

        // Apply max results limit
        $maxResults = $this->config['security']['max_results'] ?? 1000;

        // Add LIMIT if not present
        if (! preg_match('/\bLIMIT\b/i', $sql)) {
            $sql .= " LIMIT {$maxResults}";
        }

        // Execute with timeout (database-specific)
        return $connection->select($sql);
    }

    /**
     * Get the schema manager instance.
     */
    public function getSchemaManager(): SchemaManager
    {
        return $this->schemaManager;
    }

    /**
     * Get the LLM manager instance.
     */
    public function getLlmManager(): LLMManager
    {
        return $this->llmManager;
    }

    /**
     * Get the query guard instance.
     */
    public function getQueryGuard(): QueryGuard
    {
        return $this->queryGuard;
    }
}
