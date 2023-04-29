<?php

namespace Uring\States;

use FFI\CData;
use Uring\Internals\FFI;

class AcceptState extends InputOutputState
{
    private static ?CData $clientAddressLength = null;
    public CData $clientAddress;
    public int $serverSocket;

    public bool $multishotRunning = false;

    public function __construct()
    {
        $this->eventType = EventType::ACCEPT;

        if (self::$clientAddressLength === null) {
            self::$clientAddressLength = \FFI::addr(FFI::unsafeNew(FFI::uring(), 'uint32_t', true, true));
            self::$clientAddressLength[0] = \FFI::sizeof(FFI::types('sockaddr_in')) * 2;
        }

        $this->clientAddress = \FFI::addr(FFI::unsafeNew(FFI::uring(), FFI::types('sockaddr_in'), true, true));
        $this->reset();
    }

    public function reset(): void
    {
        parent::reset();
        $this->serverSocket = 0;
    }

    public function getClientAddress(): CData
    {
        return $this->clientAddress;
    }

    public function getClientAddressLength(): CData
    {
        return self::$clientAddressLength;
    }

    public function getServerSocket(): int
    {
        return $this->serverSocket;
    }

    public function setServerSocket(int $serverSocket): void
    {
        $this->serverSocket = $serverSocket;
    }

    public function getPort(): int
    {
        return FFI::libc()->ntohs($this->clientAddress->sin_port);
    }

    public function getAddress(): string
    {
        return \FFI::string(FFI::libc()->inet_ntoa($this->clientAddress->sin_addr));
    }
}
