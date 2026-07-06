<?php

declare(strict_types=1);

namespace TwigStan\Fixtures;

use ArrayAccess;
use InvalidArgumentException;

/**
 * @implements ArrayAccess<string, null|string>
 */
final class Context implements ArrayAccess
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new InvalidArgumentException('Appending to the context is not supported.');
        }

        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
