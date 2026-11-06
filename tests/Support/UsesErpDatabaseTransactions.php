<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Refresh schema on the dedicated test database (stone_erp_ai_test via phpunit.xml).
 * Does not target the development database when phpunit.xml is configured correctly.
 */
trait UsesErpDatabaseTransactions
{
    use RefreshDatabase;
}
