<?php

namespace Uring;

use FFI\CData;
use RuntimeException;
use Uring\Internals\FFI;

class SocketAddress
{
    /**
     * @internal
     */
    public readonly CData $srvAddress;

    public function __construct(
        public readonly string $address,
        public readonly int    $port,
        int                    $family
    )
    {
        $this->srvAddress = $srvAddress = FFI::unsafeNew(FFI::libc(), FFI::types('sockaddr_in'));
        \FFI::memset($srvAddress, 0, \FFI::sizeof($srvAddress));
        $srvAddress->sin_family = $family;
        $srvAddress->sin_port = FFI::libc()->htons($port);
        if (FFI::libc()->inet_aton($address, \FFI::addr($srvAddress->sin_addr)) === 0) {
            throw new RuntimeException("invalid address");
        }
    }

    public static function fromCData(CData $addr): self
    {
        return new self(\FFI::string(FFI::libc()->inet_ntoa($addr->sin_addr)), FFI::libc()->ntohs($addr->sin_port), $addr->sin_family);
    }
}
