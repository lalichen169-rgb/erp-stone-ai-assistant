<?php

namespace App\Services\AI\Tools;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Contracts\AiToolInterface;
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
    public function definitionsFor(User $user): array
    {
        $defs = [];

        foreach ($this->tools as $tool) {
            if ($tool->allowedFor($user)) {
                $defs[] = $tool->definition();
            }
        }

        return $defs;
    }
}
