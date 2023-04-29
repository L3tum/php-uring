<?php

namespace Uring;

use RuntimeException;
use Uring\Internals\FFI;

class SocketFFI
{
    public static function createSocket(int $domain): int
    {
        if (($socketFd = FFI::libc()->socket($domain, SOCK_STREAM, 0)) === -1) {
            throw new RuntimeException("socket()");
        }

        return $socketFd;
    }

    public static function enableReusableAddress(int $socketFd): void
    {
        $enable = \FFI::new('int');
        $enable->cdata = 1;
        if (FFI::libc()->setsockopt($socketFd, SOL_SOCKET, SO_REUSEADDR, \FFI::addr($enable), \FFI::sizeof($enable)) < 0) {
            throw new RuntimeException("setsockopt(SO_REUSEADDR)");
        }
    }

    public static function createSocketAddress(string $address, int $port, int $domain): SocketAddress
    {
        return new SocketAddress($address, $port, match ($domain) {
            STREAM_PF_INET => AF_INET,
            STREAM_PF_INET6 => AF_INET6,
            default => throw new RuntimeException("Invalid domain $domain")
        });
    }

    public static function bindSocketAddressToSocket(int $socketFd, SocketAddress $socketAddress): void
    {
        // Bind socket to server address
        // For some reason $size needs to be twice the actual size of the struct
        // If the struct is artificially inflated (like by using uint64_t instead), then the number returned by sizeof
        // still doesn't fit whatever bind wants.
        $srvAddress = $socketAddress->srvAddress;
        if (FFI::libc()->bind($socketFd, \FFI::addr($srvAddress), \FFI::sizeof($srvAddress) * 2) < 0) {
            $errno = FFI::libc()->errno;
            throw new RuntimeException("bind() => $errno " . FFI::explain()->explain_errno_bind($errno, $socketFd, \FFI::addr($srvAddress), \FFI::sizeof($srvAddress)));
        }
    }

    public static function testListen(int $socketFd): void
    {
        // Check if we can listen on this socket
        if (FFI::libc()->listen($socketFd, 10) < 0) {
            throw new RuntimeException("listen()");
        }
    }

    /**
     * @internal
     */
    public static function setupListeningSocket(string $address, int $port): Socket
    {
        // Create socket
        if (($socketFd = FFI::libc()->socket(STREAM_PF_INET, SOCK_STREAM, 0)) === -1) {
            throw new RuntimeException("socket()");
        }

        // Enable reusable address
        $enable = \FFI::new('int');
        $enable->cdata = 1;
        if (FFI::libc()->setsockopt($socketFd, SOL_SOCKET, SO_REUSEADDR, \FFI::addr($enable), \FFI::sizeof($enable)) < 0) {
            throw new RuntimeException("setsockopt(SO_REUSEADDR)");
        }

        // Allocate server address
        $srvAddress = FFI::unsafeNew(FFI::libc(), FFI::types('sockaddr_in'));
        \FFI::memset($srvAddress, 0, \FFI::sizeof($srvAddress));
        $srvAddress->sin_family = AF_INET;
        $srvAddress->sin_port = FFI::libc()->htons($port);
        if (FFI::libc()->inet_aton($address, \FFI::addr($srvAddress->sin_addr)) === 0) {
            throw new RuntimeException("invalid address");
        }

        // Bind socket to server address
        // For some reason $size needs to be twice the actual size of the struct
        // If the struct is artificially inflated (like by using uint64_t instead), then the number returned by sizeof
        // still doesn't fit whatever bind wants.
        if (FFI::libc()->bind($socketFd, \FFI::addr($srvAddress), \FFI::sizeof($srvAddress) * 2) < 0) {
            $errno = FFI::libc()->errno;
            throw new RuntimeException("bind() => $errno " . FFI::explain()->explain_errno_bind($errno, $socketFd, \FFI::addr($srvAddress), \FFI::sizeof($srvAddress)));
        }

        // Check if we can listen on this socket
        if (FFI::libc()->listen($socketFd, 10) < 0) {
            throw new RuntimeException("listen()");
        }

        return new Socket($socketFd, FFI::libc()->ntohs($srvAddress->sin_port), \FFI::string(FFI::libc()->inet_ntoa($srvAddress->sin_addr)));
    }
}
