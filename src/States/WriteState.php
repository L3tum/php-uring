<?php

namespace Uring\States;

class WriteState extends InputOutputState
{
    public string $buffer;
    public int $bufferLength;
    public int $bufferWritten;

    public function __construct()
    {
        $this->eventType = EventType::WRITE;
        $this->reset();
    }

    public function reset(): void
    {
        parent::reset();
        $this->resetBuffer();
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function resetBuffer(): void
    {
        unset($this->buffer);
        $this->bufferLength = -1;
        $this->bufferWritten = -1;
    }

    public function setBuffer(string &$buffer): void
    {
        $this->buffer =& $buffer;
        $this->bufferLength = strlen($buffer);
    }

    public function getBufferLength(): int
    {
        return $this->bufferLength;
    }

    /**
     * @internal
     */
    public function setBufferWritten(int $length): void
    {
        $this->bufferWritten = $length;
    }

    public function getBufferWritten(): int
    {
        return $this->bufferWritten;
    }
}
