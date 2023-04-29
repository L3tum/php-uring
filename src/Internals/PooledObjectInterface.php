<?php

namespace Uring\Internals;

abstract class PooledObjectInterface
{
    private int $id;

    abstract public function reset(): void;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
