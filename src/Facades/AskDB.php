<?php

namespace Eric0324\AIDBQuery\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Eric0324\AIDBQuery\SmartQuery tables(array $tables)
 * @method static \Eric0324\AIDBQuery\SmartQuery connection(string $connection)
 * @method static \Eric0324\AIDBQuery\SmartQuery using(string $driver)
 * @method static \Illuminate\Support\Collection ask(string $question)
 * @method static string toSql(string $question)
 * @method static array raw(string $question)
 * @method static \Eric0324\AIDBQuery\Schema\SchemaManager getSchemaManager()
 * @method static \Eric0324\AIDBQuery\LLM\LLMManager getLlmManager()
 * @method static \Eric0324\AIDBQuery\Security\QueryGuard getQueryGuard()
 *
 * @see \Eric0324\AIDBQuery\SmartQuery
 */
class AskDB extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'smart-query';
    }
}
