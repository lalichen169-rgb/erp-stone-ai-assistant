<?php

namespace App\Services\AI\Tools\Contracts;

/**
 * Tools omitted from the model tool list on OpenAI and Claude ({@see \App\Services\AI\AiProviderProfile::usesLegacyOpenAiClaudePresentation}).
 *
 * Server-side execution is also blocked for those providers in {@see \App\Services\AI\Tools\ToolRegistry::isExecutableForProvider}.
 * Do not mark role-critical ERP tools (e.g. searchSuppliers) as Ollama-exclusive — only optional Ollama discovery aids belong here.
 */
interface OllamaExclusiveTool {}
