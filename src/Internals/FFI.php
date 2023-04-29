<?php

namespace Uring\Internals;

use FFI\CData;
use FFI\CType;

class FFI
{
    private static ?\FFI $libc = null;
    private static ?\FFI $explain = null;
    private static ?\FFI $uring = null;
    private static array $pointerTypes;
    private static array $types;

    public static function libc(): \FFI
    {
        self::init();
        return self::$libc;
    }

    private static function init()
    {
        if (self::$libc !== null) {
            return;
        }

        self::$libc = \FFI::cdef(file_get_contents(__DIR__ . '/Definitions/libc.h'), "libc.so.6");

        // TODO: Make optional with some kinda debug check or so, w/e
        if (false) {
            self::$explain = \FFI::cdef(file_get_contents(__DIR__ . '/Definitions/libexplain.h'), "libexplain.so.51");
        }

        if (file_exists('/usr/lib/liburing-ffi.so.2')) {
            $libUring = '/usr/lib/liburing-ffi.so.2';
        } else {
            $libUring = dirname(__DIR__, 2) . '/sys/liburing-ffi.so.2';
        }
        self::$uring = \FFI::cdef(file_get_contents(__DIR__ . '/Definitions/liburing.h'), $libUring);

        self::$types = [
            'sockaddr_in' => self::$libc->type('sockaddr_in'),
            'io_uring' => self::$uring->type('io_uring'),
            'io_uring_params' => self::$uring->type('io_uring_params'),
            'io_uring_cqe' => self::$uring->type('io_uring_cqe'),
            'kernel_timespec' => self::$uring->type('kernel_timespec'),
        ];
        self::$pointerTypes = [
            'sockaddr_in' => self::$libc->type('sockaddr_in*'),
        ];
    }

    public static function explain(): \FFI
    {
        self::init();
        return self::$explain;
    }

    public static function uring(): \FFI
    {
        self::init();
        return self::$uring;
    }

    public static function types(string $type): CType
    {
        self::init();
        return self::$types[$type];
    }

    public static function pTypes(string $type): CType
    {
        self::init();
        return self::$pointerTypes[$type];
    }

    public static function unsafeNew(\FFI $ffi, string|CType $type, bool $owned = true, bool $persistent = false): CData
    {
        $data = $ffi->new($type, $owned, $persistent);
        assert($data !== null);
        return $data;
    }
}
