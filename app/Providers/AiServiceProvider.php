<?php

namespace App\Providers;

use App\Services\AI\AiAgentService;
use App\Services\AI\AiProviderFactory;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\AssistantSystemInstructionBuilder;
use App\Services\AI\AiToolCallingService;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Conversations\ConversationSummaryService;
use App\Services\AI\Conversations\ToolResultRedactor;
use App\Services\AI\Context\ConversationContextManager;
use App\Services\AI\Context\ConversationThreadAnchorResolver;
use App\Services\AI\Context\ConversationThreadClassifier;
use App\Services\AI\Context\ConversationTurnPairCollector;
use App\Services\AI\Context\DeterministicFollowUpResolver;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\ToolCalling\AiToolCallingDriverFactory;
use App\Services\AI\ToolCalling\ClaudeToolCallingDriver;
use App\Services\AI\ToolCalling\OllamaToolCallingDriver;
use App\Services\AI\ToolCalling\OpenAiToolCallingDriver;
use App\Services\AI\Tools\Definitions\CheckStockTool;
use App\Services\AI\Tools\Definitions\FindOverduePurchaseOrdersTool;
use App\Services\AI\Tools\Definitions\GetLandedCostTool;
use App\Services\AI\Tools\Definitions\GetSupplierBankDetailsTool;
use App\Services\AI\Tools\Definitions\CalculateReplenishmentQuantityTool;
use App\Services\AI\Tools\Definitions\ProposeDraftPurchaseOrderTool;
use App\Services\AI\Tools\Definitions\ResolveProductsForPurchaseTool;
use App\Services\AI\Tools\Definitions\ResolveWarehouseForPurchaseTool;
use App\Services\AI\Tools\Definitions\SearchWarehousesTool;
use App\Services\AI\Tools\Definitions\SearchProductsTool;
use App\Services\AI\Tools\Definitions\ValidateSupplierProductOfferingTool;
use App\Services\AI\Tools\Definitions\SearchPurchaseOrdersTool;
use App\Services\AI\Tools\Definitions\SearchSuppliersTool;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProviderFactory::class);
        $this->app->singleton(AiAgentService::class);
        $this->app->singleton(OpenAiToolCallingDriver::class);
        $this->app->singleton(ClaudeToolCallingDriver::class);
        $this->app->singleton(OllamaToolCallingDriver::class);
        $this->app->singleton(AiToolCallingDriverFactory::class);
        $this->app->singleton(ToolResultRedactor::class);
        $this->app->singleton(ConversationService::class);
        $this->app->singleton(ConversationSummaryService::class);
        $this->app->singleton(ConversationContextManager::class);
        $this->app->singleton(ConversationTurnPairCollector::class);
        $this->app->singleton(ConversationThreadClassifier::class);
        $this->app->singleton(DeterministicFollowUpResolver::class);
        $this->app->singleton(ConversationThreadAnchorResolver::class);
        $this->app->singleton(\App\Services\AI\Grounding\ErpQuestionStandaloneHeuristic::class);
        $this->app->singleton(\App\Services\AI\Grounding\ErpDeterministicPreflight::class);
        $this->app->singleton(FollowUpQuestionResolver::class);
        $this->app->singleton(AiToolCallingService::class);
        $this->app->singleton(AssistantSystemInstructionBuilder::class);
        $this->app->singleton(AssistantRequestService::class);

        $this->app->singleton(ToolRegistry::class, static function ($app): ToolRegistry {
            return new ToolRegistry([
                $app->make(CheckStockTool::class),
                $app->make(SearchProductsTool::class),
                $app->make(SearchPurchaseOrdersTool::class),
                $app->make(FindOverduePurchaseOrdersTool::class),
                $app->make(GetLandedCostTool::class),
                $app->make(GetSupplierBankDetailsTool::class),
                $app->make(SearchSuppliersTool::class),
                $app->make(ResolveProductsForPurchaseTool::class),
                $app->make(SearchWarehousesTool::class),
                $app->make(ResolveWarehouseForPurchaseTool::class),
                $app->make(ValidateSupplierProductOfferingTool::class),
                $app->make(CalculateReplenishmentQuantityTool::class),
                $app->make(ProposeDraftPurchaseOrderTool::class),
            ]);
        });

        $this->app->singleton(ToolExecutor::class);
    }

    public function boot(): void
    {
        config([
            'ai.openai.api_key' => config('openai.api_key'),
            'ai.openai.organization' => config('openai.organization'),
            'ai.openai.project' => config('openai.project'),
            'ai.openai.request_timeout' => (int) config('openai.request_timeout', 30),
        ]);
    }
}
