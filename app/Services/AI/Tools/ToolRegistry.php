<?php

namespace App\Services\AI\Tools;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\AiProviderProfile;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\Contracts\OllamaExclusiveTool;
use InvalidArgumentException;

final class ToolRegistry
{
    /**
     * @var array<string, AiToolInterface>
     */
    private array $tools = [];

    /**
     * @param  iterable<AiToolInterface>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (isset($this->tools[$name])) {
                throw new InvalidArgumentException("Duplicate AI tool registration: {$name}");
            }
            $this->tools[$name] = $tool;
        }
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function registeredNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * @return list<AiToolDefinition>
     */
    public function definitionsFor(User $user, ?string $provider = null): array
    {
        $defs = [];
        $legacy = AiProviderProfile::usesLegacyOpenAiClaudePresentation($provider);

        foreach ($this->tools as $tool) {
            if (! $tool->allowedFor($user)) {
                continue;
            }

            if ($legacy && $tool instanceof OllamaExclusiveTool) {
                continue;
            }

            $defs[] = $legacy ? $tool->legacyDefinition() : $tool->definition();
        }

        return $defs;
    }

    /**
     * Whether this tool may be executed server-side for the given provider (not only offered to the model).
     */
    public function isExecutableForProvider(string $name, User $user, ?string $provider = null): bool
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return false;
        }

        if (! $tool->allowedFor($user)) {
            return false;
        }

        if (AiProviderProfile::usesLegacyOpenAiClaudePresentation($provider) && $tool instanceof OllamaExclusiveTool) {
            return false;
        }

        return true;
    }
}
