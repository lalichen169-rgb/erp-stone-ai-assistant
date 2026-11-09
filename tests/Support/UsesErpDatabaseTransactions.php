<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Per-test rollback on the dedicated test database (stone_erp_ai_test via phpunit.xml).
 * Run {@see scripts/test-db-prep.ps1} once (or after schema changes) to migrate the test DB;
 * the suite does not call migrate:fresh during phpunit.
 */
trait UsesErpDatabaseTransactions
{
    use DatabaseTransactions;
}
