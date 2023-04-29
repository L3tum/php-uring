<?php

namespace Uring\States;

class CreateSocketState extends InputOutputState
{
    public int $createdFileDescriptor;

    public function __construct()
    {
        $this->eventType = EventType::CREATE_SOCKET;
        $this->reset();
    }
}
