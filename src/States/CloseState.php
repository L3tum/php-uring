<?php

namespace Uring\States;

class CloseState extends InputOutputState
{
    public function __construct()
    {
        $this->eventType = EventType::CLOSE;
        $this->reset();
    }
}
