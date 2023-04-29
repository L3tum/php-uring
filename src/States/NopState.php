<?php

namespace Uring\States;

class NopState extends InputOutputState
{
    public function __construct()
    {
        $this->eventType = EventType::NOP;
        $this->reset();
    }
}
