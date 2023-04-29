<?php

namespace Uring\States;

use FFI\CData;
use Uring\Internals\FFI;

class TimeoutState extends InputOutputState
{
    public const NANOSECONDS_TO_SECONDS = 1000 / 1000 / 1000;
    public const SECONDS_TO_NANOSECONDS = 1000 * 1000 * 1000;

    private CData $timeoutSpec;

    public function __construct()
    {
        $this->eventType = EventType::TIMEOUT;
        $this->timeoutSpec = FFI::unsafeNew(FFI::uring(), FFI::types('kernel_timespec'), false);
        $this->reset();
    }

    public function __destruct()
    {
        if (isset($this->timeoutSpec) && !\FFI::isNull(\FFI::addr($this->timeoutSpec))) {
            \FFI::free(\FFI::addr($this->timeoutSpec));
        }
    }

    public function getTimeoutInSeconds(): float
    {
        return $this->timeoutSpec->tv_sec + ($this->timeoutSpec->tv_nsec / self::NANOSECONDS_TO_SECONDS);
    }

    public function setTimeoutInSeconds(float $timeoutInSeconds): void
    {
        $this->timeoutSpec->tv_sec = $seconds = floor($timeoutInSeconds);
        $this->timeoutSpec->tv_nsec = ($timeoutInSeconds - $seconds) * self::SECONDS_TO_NANOSECONDS;
    }

    public function getAddressOfTimeoutSpec(): CData
    {
        return \FFI::addr($this->timeoutSpec);
    }
}
