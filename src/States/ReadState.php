<?php

namespace Uring\States;

use FFI;
use FFI\CData;

class ReadState extends InputOutputState
{
    public ?CData $buffer = null;
    public int $bufferLength;
    public int $bufferWritten;

    public function __construct(?int $bufferLength = null)
    {
        $this->eventType = EventType::READ;
        $this->bufferLength = -1;
        $this->bufferWritten = -1;
        $this->reset();

        if ($bufferLength !== null) {
            $this->allocateBuffer($bufferLength);
        }
    }

    public function __destruct()
    {
        $this->resetBuffer();
    }

    public function reset(): void
    {
        parent::reset();
        $this->resetBuffer();
    }

    public function getBuffer(): ?CData
    {
        return $this->buffer;
    }

    public function getBufferAddress(): CData
    {
        return FFI::addr($this->buffer);
    }

    public function resetBuffer(): void
    {
        if ($this->buffer !== null && !FFI::isNull(FFI::addr($this->buffer))) {
            FFI::free(FFI::addr($this->buffer));
        }

        $this->buffer = null;
        $this->bufferLength = -1;
        $this->bufferWritten = -1;
    }

    public function allocateBuffer(int $length, bool $zeroOut = true): void
    {
        if ($this->getBufferLength() !== $length) {
            $this->resetBuffer();
            $this->buffer = FFI::new("char[$length]", false);
            $this->setBufferLength($length);
        }
        if ($zeroOut) {
            FFI::memset($this->buffer, 0, $length);
        }
    }

    private function setBufferLength(int $bufLen): void
    {
        $this->bufferLength = $bufLen;
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
