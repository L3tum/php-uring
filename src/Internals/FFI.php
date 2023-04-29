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

        self::$libc = \FFI::cdef(
            <<<SOCKET
extern int errno;

uint32_t htonl(uint32_t hostlong);
uint16_t htons(uint16_t hostshort);
uint32_t ntohl(uint32_t netlong);
uint16_t ntohs(uint16_t netshort);

typedef uint16_t sa_family_t;
typedef uint16_t in_port_t;
typedef uint32_t socklen_t;

typedef uint32_t in_addr_t;

typedef struct _in_addr {
    in_addr_t s_addr;
} in_addr;

struct sockaddr {
    sa_family_t  sa_family;  /* Address family. */ 
    char         sa_data[];  /* Socket address (variable-length data). */ 
};

typedef struct _sockaddr_in {
   sa_family_t    sin_family; /* address family: AF_INET */
   in_port_t      sin_port;   /* port in network byte order */
   in_addr        sin_addr;   /* internet address */
} sockaddr_in;

int inet_aton(const char *cp, in_addr *inp);
char *inet_ntoa(in_addr in);

int socket(int domain, int type, int protocol);
int setsockopt(int sockfd, int level, int optname, const void *optval, socklen_t optlen);
int bind(int socket, const sockaddr_in *addr, socklen_t addrlen);
int listen(int socket, int backlog);
int close(int fd);

int get_nprocs();
SOCKET,
            "libc.so.6"
        );

        self::$explain = \FFI::cdef(
            <<<EXPLAIN
typedef void sockaddr_in;
const char *explain_errno_bind(int errnum, int socket, const sockaddr_in *sock_addr, int sock_addr_size);
const char *explain_errno_write(int errnum, int fildes, const void *data, long data_size);
EXPLAIN,
            "libexplain.so.51"
        );

        self::$uring = \FFI::cdef(
            <<<URING
typedef uint8_t __u8;
typedef	unsigned int		__u32;
typedef int __s32;
typedef	unsigned long long	__u64;
typedef uint32_t socklen_t;

// Defined in libc
typedef void sockaddr_in;

struct iovec {
    void *iov_base;
    size_t iov_len;
};

/*
 * IO submission data structure (Submission Queue Entry)
 */
typedef struct _io_uring_sqe {
	__u8	opcode;		/* type of operation for this sqe */
	__u8	flags;		/* IOSQE_ flags */
	// More
} io_uring_sqe;

typedef struct _io_uring_sq {
	unsigned *khead;
	unsigned *ktail;
	// Deprecated: use `ring_mask` instead of `*kring_mask`
	unsigned *kring_mask;
	// Deprecated: use `ring_entries` instead of `*kring_entries`
	unsigned *kring_entries;
	unsigned *kflags;
	unsigned *kdropped;
	unsigned *array;
	io_uring_sqe *sqes;

	unsigned sqe_head;
	unsigned sqe_tail;

	size_t ring_sz;
	void *ring_ptr;

	unsigned ring_mask;
	unsigned ring_entries;

	unsigned pad[2];
} io_uring_sq;

/*
 * IO completion data structure (Completion Queue Entry)
 */
typedef struct _io_uring_cqe {
	__u64	user_data;	/* sqe->data submission passed back */
	__s32	res;		/* result code for this event */
	__u32	flags;
} io_uring_cqe;

typedef struct _io_uring_cq {
	unsigned *khead;
	unsigned *ktail;
	// Deprecated: use `ring_mask` instead of `*kring_mask`
	unsigned *kring_mask;
	// Deprecated: use `ring_entries` instead of `*kring_entries`
	unsigned *kring_entries;
	unsigned *kflags;
	unsigned *koverflow;
	io_uring_cqe *cqes;

	size_t ring_sz;
	void *ring_ptr;

	unsigned ring_mask;
	unsigned ring_entries;

	unsigned pad[2];
} io_uring_cq;

typedef struct _io_uring {
	io_uring_sq sq;
	io_uring_cq cq;
	unsigned flags;
	int ring_fd;

	unsigned features;
	int enter_ring_fd;
	__u8 int_flags;
	__u8 pad[3];
	unsigned pad2;
} io_uring;

/*
 * Filled with the offset for mmap(2)
 */
struct io_sqring_offsets {
	__u32 head;
	__u32 tail;
	__u32 ring_mask;
	__u32 ring_entries;
	__u32 flags;
	__u32 dropped;
	__u32 array;
	__u32 resv1;
	__u64 resv2;
};
struct io_cqring_offsets {
	__u32 head;
	__u32 tail;
	__u32 ring_mask;
	__u32 ring_entries;
	__u32 overflow;
	__u32 cqes;
	__u32 flags;
	__u32 resv1;
	__u64 resv2;
};

/*
 * Passed in for io_uring_setup(2). Copied back with updated info on success
 */
typedef struct _io_uring_params {
	__u32 sq_entries;
	__u32 cq_entries;
	__u32 flags;
	__u32 sq_thread_cpu;
	__u32 sq_thread_idle;
	__u32 features;
	__u32 wq_fd;
	__u32 resv[3];
	struct io_sqring_offsets sq_off;
	struct io_cqring_offsets cq_off;
} io_uring_params;

int io_uring_queue_init(unsigned entries, io_uring *ring, unsigned flags);
int io_uring_queue_init_params(unsigned entries, io_uring *ring, io_uring_params *p);
void io_uring_queue_exit(io_uring *ring);
io_uring_sqe *io_uring_get_sqe(io_uring *ring);

void io_uring_prep_socket(io_uring_sqe *sqe, int domain, int type, int protocol, unsigned int flags);

void io_uring_prep_accept(io_uring_sqe *sqe, int fd, sockaddr_in *addr, socklen_t *addrlen, int flags);
void io_uring_prep_multishot_accept(io_uring_sqe *sqe, int fd, sockaddr_in *addr, socklen_t *addrlen, int flags);

void io_uring_prep_read(io_uring_sqe *sqe, int fd, void *buf, unsigned buflen, __u64 offset);
void io_uring_prep_write(io_uring_sqe *sqe, int fd, const char *buf, unsigned nbytes, __u64 offset);

void io_uring_prep_cancel_fd(io_uring_sqe *sqe, int fd, unsigned int flags);
void io_uring_prep_close(io_uring_sqe *sqe, int fd);
void io_uring_prep_shutdown(io_uring_sqe *sqe, int sockfd, int how);

void io_uring_prep_nop(io_uring_sqe *sqe);

void io_uring_prep_readv(io_uring_sqe *sqe, int fd, const struct iovec *iovecs, unsigned nr_vecs, __u64 offset);

typedef long long __kernel_time64_t;
typedef struct __kernel_timespec {
	__kernel_time64_t       tv_sec;                 /* seconds */
	long long               tv_nsec;                /* nanoseconds */
} kernel_timespec;
void io_uring_prep_timeout(io_uring_sqe *sqe, kernel_timespec *ts, unsigned count, unsigned flags);

int io_uring_submit(io_uring *ring);
int io_uring_submit_and_wait(io_uring *ring, unsigned wait_nr);
int io_uring_wait_cqe(io_uring *ring, io_uring_cqe **cqe_ptr);
int io_uring_peek_cqe(io_uring *ring, io_uring_cqe **cqe_ptr);
unsigned io_uring_peek_batch_cqe(io_uring *ring, io_uring_cqe **cqes[], unsigned count);

void io_uring_cqe_seen(io_uring *ring, io_uring_cqe *cqe);

// Must be called after peek_batch
void io_uring_cq_advance(io_uring *ring, unsigned nr);

void io_uring_sqe_set_data(io_uring_sqe *sqe, void *data);
void *io_uring_cqe_get_data(const io_uring_cqe *cqe);

void io_uring_sqe_set_data64(io_uring_sqe *sqe, __u64 data);
__u64 io_uring_cqe_get_data64(const io_uring_cqe *cqe);
URING,
            "liburing-ffi.so.2"
        );

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
