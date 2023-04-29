<?php

namespace Uring\States;

class StatesState
{
    public const STATES = [
        AcceptState::class,
        ReadState::class,
        WriteState::class,
        CancelState::class,
        CloseState::class,
        ShutdownState::class,
        TimeoutState::class,
        NopState::class,
    ];
}
