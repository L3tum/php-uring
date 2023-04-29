<?php

namespace Uring\States;

abstract class InputOutputState extends UringState
{
    public int $fileDescriptor;

    public function reset(): void
    {
        parent::reset();
        $this->fileDescriptor = 0;
    }

    public function setFileDescriptor(int $fileDescriptor): void
    {
        $this->fileDescriptor = $fileDescriptor;
    }

    public function getFileDescriptor(): int
    {
        return $this->fileDescriptor;
    }
}
