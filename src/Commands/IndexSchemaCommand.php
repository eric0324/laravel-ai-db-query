<?php

namespace Eric0324\AIDBQuery\Commands;

use Eric0324\AIDBQuery\Exceptions\SchemaException;
use Eric0324\AIDBQuery\LLM\EmbeddingService;
use Eric0324\AIDBQuery\Schema\SchemaIndexer;
use Eric0324\AIDBQuery\Schema\SchemaManager;
use Illuminate\Console\Command;

class IndexSchemaCommand extends Command
{
    protected $signature = 'smart-query:index
                            {--status : Show current index status}
                            {--tables= : Comma-separated list of tables to index}
                            {--force : Force rebuild of the index}
                            {--clear : Clear the existing index}
                            {--test= : Test search with a question}';

    protected $description = 'Build or manage the schema index for smart mode';

    public function handle(SchemaIndexer $indexer, SchemaManager $schemaManager): int
    {
        // Clear index
        if ($this->option('clear')) {
            $indexer->clear();
            $this->info('Schema index cleared.');

            return self::SUCCESS;
        }

        // Show status
        if ($this->option('status')) {
            return $this->showStatus($indexer, $schemaManager);
        }

        // Test search
        if ($question = $this->option('test')) {
            return $this->testSearch($indexer, $question);
        }

        // Build index
        return $this->buildIndex($indexer, $schemaManager);
    }

    /**
     * Show the current index status.
     */
    protected function showStatus(SchemaIndexer $indexer, SchemaManager $schemaManager): int
    {
        $status = $indexer->getStatus();

        $this->info('Schema Index Status');
        $this->newLine();

        if ($status['indexed']) {
            $this->line('Status: <fg=green>Indexed</>');
            $this->line("Tables indexed: {$status['tables_count']}");
            $this->line("Embedding model: {$status['model']}");
            $this->line("Vector dimension: {$status['dimension']}");
            $this->line("Last updated: {$status['last_updated']}");
            $this->line('Using sqlite-vec: ' . ($status['using_vec0'] ? '<fg=green>Yes</>' : '<fg=yellow>No (using fallback)</>'));

            $this->newLine();
            $this->info('Indexed Tables:');

            $indexedTables = $indexer->getIndexedTables();
            foreach ($indexedTables as $table) {
                $desc = $table['description'] ? " - {$table['description']}" : '';
                $this->line("  - {$table['table_name']}{$desc}");
            }
        } else {
            $this->line('Status: <fg=yellow>Not indexed</>');
            $this->newLine();
            $this->info('Available Tables:');

            $tables = $schemaManager->getTables();
            foreach ($tables as $table) {
                $columns = $schemaManager->getTableColumns($table);
                $this->line("  - {$table} (" . count($columns) . ' columns)');
            }

            $this->newLine();
            $this->comment('Run `php artisan smart-query:index` to build the index.');
        }

        return self::SUCCESS;
    }

    /**
     * Build the schema index.
     */
    protected function buildIndex(SchemaIndexer $indexer, SchemaManager $schemaManager): int
    {
        // Check if embedding service is configured
        $apiKey = config('smart-query.llm.drivers.openai.api_key');

        if (empty($apiKey)) {
            $this->error('OpenAI API key is required for embedding generation.');
            $this->comment('Set OPENAI_API_KEY in your .env file.');

            return self::FAILURE;
        }

        // Set up embedding service
        try {
            $embeddingService = new EmbeddingService([
                'api_key' => $apiKey,
                'model' => config('smart-query.schema.embedding_model', 'text-embedding-3-small'),
            ]);

            $indexer->setEmbeddingService($embeddingService);
            $indexer->setSchemaManager($schemaManager);
        } catch (\Exception $e) {
            $this->error("Failed to initialize embedding service: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tables = null;

        if ($tablesOption = $this->option('tables')) {
            $tables = array_map('trim', explode(',', $tablesOption));
        }

        $force = $this->option('force');

        $this->info('Building schema index...');
        $this->newLine();

        // Show tables to be indexed
        $tablesToIndex = $tables ?? $schemaManager->getTables();
        $this->line('Tables to index: ' . count($tablesToIndex));

        foreach ($tablesToIndex as $table) {
            $this->line("  - {$table}");
        }

        $this->newLine();

        // Create progress bar
        $progressBar = $this->output->createProgressBar(count($tablesToIndex));
        $progressBar->start();

        try {
            $result = $indexer->index($tables, $force);

            $progressBar->finish();
            $this->newLine(2);

            if ($result['status'] === 'success') {
                $this->info('Index built successfully!');
                $this->newLine();

                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total tables', $result['tables_count']],
                        ['Newly indexed', $result['indexed']],
                        ['Skipped (unchanged)', $result['skipped']],
                        ['Using sqlite-vec', $result['using_vec0'] ? 'Yes' : 'No (fallback)'],
                    ]
                );

                if (! empty($result['errors'])) {
                    $this->newLine();
                    $this->warn('Some errors occurred:');
                    foreach ($result['errors'] as $error) {
                        $this->line("  - {$error}");
                    }
                }

                $this->newLine();
                $this->comment('Index file: ' . $indexer->getIndexPath());

                return self::SUCCESS;
            }

            $this->error('Failed to build index.');

            return self::FAILURE;
        } catch (SchemaException $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Test search functionality.
     */
    protected function testSearch(SchemaIndexer $indexer, string $question): int
    {
        if (! $indexer->hasIndex()) {
            $this->error('Index not found. Run `php artisan smart-query:index` first.');

            return self::FAILURE;
        }

        // Set up embedding service
        $apiKey = config('smart-query.llm.drivers.openai.api_key');

        if (empty($apiKey)) {
            $this->error('OpenAI API key is required for search.');

            return self::FAILURE;
        }

        try {
            $embeddingService = new EmbeddingService([
                'api_key' => $apiKey,
                'model' => config('smart-query.schema.embedding_model', 'text-embedding-3-small'),
            ]);

            $indexer->setEmbeddingService($embeddingService);
        } catch (\Exception $e) {
            $this->error("Failed to initialize embedding service: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Searching for: \"{$question}\"");
        $this->newLine();

        $results = $indexer->findRelevantTables($question, 5);

        if (empty($results)) {
            $this->warn('No relevant tables found.');

            return self::SUCCESS;
        }

        $this->info('Relevant tables:');
        $this->newLine();

        $tableData = [];
        foreach ($results as $i => $result) {
            $score = number_format($result['score'] * 100, 1) . '%';
            $tableData[] = [
                $i + 1,
                $result['table_name'],
                $score,
                $result['description'] ?? '-',
            ];
        }

        $this->table(['#', 'Table', 'Relevance', 'Description'], $tableData);

        $this->newLine();
        $this->comment('These tables will be used for the query context.');

        return self::SUCCESS;
    }
}
