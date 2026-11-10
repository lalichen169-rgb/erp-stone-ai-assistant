<?php

namespace App\DTOs\AI;

use InvalidArgumentException;

final readonly class AiToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {
        if (trim($this->id) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Tool call id and name are required.');
        }
    }
}
