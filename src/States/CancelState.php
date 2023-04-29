<?php

namespace Uring\States;

class CancelState extends InputOutputState
{
    public function __construct()
    {
        $this->eventType = EventType::CANCEL;
        $this->reset();
    }
}
