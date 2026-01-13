<?php

namespace Eric0324\AIDBQuery\Tests;

use Eric0324\AIDBQuery\SmartQueryServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SmartQueryServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'AskDB' => \Eric0324\AIDBQuery\Facades\AskDB::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
