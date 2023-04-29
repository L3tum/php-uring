<?php

namespace Uring\Internals;

use Closure;

/**
 * @template T
 * @template-extends PooledObjectInterface
 */
class ObjectPool
{
    /**
     * @var array<int, T>
     */
    private array $borrowedObjects = [];

    /**
     * @var array<T>
     */
    private array $unusedObjects = [];

    private int $nextId = 0;

    public function __construct(
        private readonly Closure $creator,
        private readonly int     $maxKeptObjects
    )
    {
    }

    /**
     * @return T
     */
    public function borrowObject(): PooledObjectInterface
    {
        if (count($this->unusedObjects) === 0) {
            // Fill it up some more
            $this->initializeWithCount(max(count($this->borrowedObjects) / 2, 1));
            return $this->borrowObject();
        }

        $usedObject = array_pop($this->unusedObjects);
        $this->borrowedObjects[$usedObject->getId()] = $usedObject;

        return $usedObject;
    }

    /**
     * @return T
     */
    public function getBorrowedObjectById(int $id): PooledObjectInterface
    {
        return $this->borrowedObjects[$id];
    }

    /**
     * @param T $usedObject
     * @return void
     * @noinspection PhpDocSignatureInspection
     */
    public function returnObject(PooledObjectInterface $usedObject): void
    {
        if (count($this->unusedObjects) < $this->maxKeptObjects) {
            $usedObject->reset();
            $this->unusedObjects[] = $usedObject;
        }
        unset($this->borrowedObjects[$usedObject->getId()]);
    }

    public function initializeWithCount(int $count): void
    {
        $creator = &$this->creator;
        for ($i = 0; $i < $count; $i++) {
            /** @var PooledObjectInterface $usedObject */
            $usedObject = $creator();
            $usedObject->setId($this->nextId++);
            $this->unusedObjects[] = $usedObject;
        }
    }
}
