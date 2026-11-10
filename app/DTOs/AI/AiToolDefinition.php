<?php

namespace App\DTOs\AI;

use InvalidArgumentException;

final readonly class AiToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema object for tool parameters
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = ['type' => 'object', 'properties' => []],
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Tool name is required.');
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException('Tool description is required.');
        }
    }
}
