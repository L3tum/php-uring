<?php

namespace Uring\States;

use Uring\Exceptions\UringException;
use Uring\Internals\PooledObjectInterface;

abstract class UringState extends PooledObjectInterface
{
    protected EventType $eventType;
    public UringException $exception;

    public function reset(): void
    {
        unset($this->exception);
    }

    public static function extractEventType(int $id): EventType
    {
        return EventType::from(abs(($id & (0b11111111 << 50)) >> 50));
    }

    public static function extractId(int $id): int
    {
        return $id & ~(0b11111111 << 50);
    }

    public function packIdAndEventType(): int
    {
        return ($this->eventType->value << 50) | $this->getId();
    }

    public function getEventTypeValue(): int
    {
        return $this->eventType->value;
    }
}
