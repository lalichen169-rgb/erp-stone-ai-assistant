<?php

namespace Tests\Feature\AI\Context;

use App\Services\AI\Context\ConversationThreadClassifier;
use App\Services\AI\Context\FollowUpThreadType;
use Database\Seeders\ProductSeeder;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ConversationThreadClassifierTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    private ConversationThreadClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->classifier = app(ConversationThreadClassifier::class);
    }

    public function test_classifies_statuario_stock_without_calacatta_regex(): void
    {
        $type = $this->classifier->classify(
            'Show me Statuario stock in Houston.',
            'No matching products found.',
        );

        $this->assertSame(FollowUpThreadType::Stock, $type);
    }

    public function test_classifies_generic_how_many_slabs(): void
    {
        $type = $this->classifier->classify(
            'How many slabs are in Dallas?',
            'There are 10 slabs.',
        );

        $this->assertSame(FollowUpThreadType::Stock, $type);
    }

    public function test_does_not_classify_unrelated_supplier_thread_as_stock(): void
    {
        $type = $this->classifier->classify(
            'What is the status of Supplier Alpine Stone?',
            'Supplier is active.',
        );

        $this->assertSame(FollowUpThreadType::SupplierOriented, $type);
    }
}
