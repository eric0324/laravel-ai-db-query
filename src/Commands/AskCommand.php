<?php

namespace Eric0324\AIDBQuery\Commands;

use Eric0324\AIDBQuery\Exceptions\SmartQueryException;
use Eric0324\AIDBQuery\SmartQuery;
use Illuminate\Console\Command;

class AskCommand extends Command
{
    protected $signature = 'smart-query:ask
                            {question? : The natural language question to ask}
                            {--connection= : Database connection to use}
                            {--driver= : LLM driver to use (openai, anthropic, ollama)}
                            {--tables= : Comma-separated list of tables to query}
                            {--sql : Only output the generated SQL}
                            {--json : Output results as JSON}';

    protected $description = 'Query the database using natural language';

    public function handle(SmartQuery $smartQuery): int
    {
        $question = $this->argument('question');

        // Interactive mode if no question provided
        if (! $question) {
            $question = $this->ask('Enter your question');

            if (! $question) {
                $this->error('No question provided.');

                return self::FAILURE;
            }
        }

        // Apply options
        if ($connection = $this->option('connection')) {
            $smartQuery = $smartQuery->connection($connection);
        }

        if ($driver = $this->option('driver')) {
            $smartQuery = $smartQuery->using($driver);
        }

        if ($tables = $this->option('tables')) {
            $tableArray = array_map('trim', explode(',', $tables));
            $smartQuery = $smartQuery->tables($tableArray);
        }

        try {
            // SQL only mode
            if ($this->option('sql')) {
                $sql = $smartQuery->toSql($question);
                $this->line($sql);

                return self::SUCCESS;
            }

            // Execute query
            $result = $smartQuery->raw($question);

            // JSON output mode
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            // Interactive output
            $this->info('Question: ' . $question);
            $this->newLine();
            $this->info('Generated SQL:');
            $this->line($result['sql']);
            $this->newLine();

            if (empty($result['data'])) {
                $this->warn('No results found.');
            } else {
                $this->info("Results ({$result['count']} rows):");
                $this->displayResults($result['data']);
            }

            $this->newLine();
            $this->comment(sprintf(
                'Executed in %.2fms using %s',
                $result['duration'] * 1000,
                $result['driver']
            ));

            return self::SUCCESS;
        } catch (SmartQueryException $e) {
            $this->error('Error: ' . $e->getMessage());

            if ($this->option('json')) {
                $this->line(json_encode([
                    'error' => true,
                    'message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            }

            return self::FAILURE;
        }
    }

    /**
     * Display results in a table format.
     */
    protected function displayResults(array $data): void
    {
        if (empty($data)) {
            return;
        }

        // Convert objects to arrays
        $rows = array_map(fn ($row) => (array) $row, $data);

        // Get headers from first row
        $headers = array_keys($rows[0]);

        // Limit display to first 50 rows
        if (count($rows) > 50) {
            $rows = array_slice($rows, 0, 50);
            $this->table($headers, $rows);
            $this->warn('Showing first 50 rows. Total rows: ' . count($data));
        } else {
            $this->table($headers, $rows);
        }
    }
}
