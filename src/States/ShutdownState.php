<?php

namespace Uring\States;

class ShutdownState extends InputOutputState
{
    public int $how;

    public function __construct()
    {
        $this->eventType = EventType::SHUTDOWN;
        $this->reset();
    }
}
