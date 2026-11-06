<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->app->environment('testing')) {
            return;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($database !== 'stone_erp_ai_test') {
            $this->fail(
                'Refusing to run tests against "'.$database.'". '
                .'PHPUnit must use the dedicated test database stone_erp_ai_test (see phpunit.xml and .env.testing.example).'
            );
        }
    }
}
