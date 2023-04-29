<?php

namespace Uring\States;

enum EventType: int
{
    case ACCEPT = 1;
    case READ = 2;
    case WRITE = 3;
    case CANCEL = 4;
    case CLOSE = 5;
    case TIMEOUT = 6;
    case SHUTDOWN = 7;
    case NOP = 8;
    case CREATE_SOCKET = 9;
}
