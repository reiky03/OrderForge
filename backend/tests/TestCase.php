<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        if (getenv('ORDERFORGE_TEST_DATABASE') !== 'pgsql') {
            foreach ([
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ] as $name => $value) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        return parent::createApplication();
    }
}
