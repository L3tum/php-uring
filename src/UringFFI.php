<?php

namespace Uring;

use FFI\CData;
use RuntimeException;
use Uring\Exceptions\BadFileDescriptorException;
use Uring\Exceptions\ConnectionResetException;
use Uring\Exceptions\InvalidArgumentException;
use Uring\Exceptions\OperationCancelledException;
use Uring\Exceptions\TimeoutException;
use Uring\Exceptions\TransportEndpointNotConnectedException;
use Uring\Exceptions\UringException;
use Uring\Internals\FFI;
use Uring\Internals\ObjectPool;
use Uring\States\AcceptState;
use Uring\States\CancelState;
use Uring\States\CloseState;
use Uring\States\CreateSocketState;
use Uring\States\EventType;
use Uring\States\InputOutputState;
use Uring\States\NopState;
use Uring\States\ReadState;
use Uring\States\ShutdownState;
use Uring\States\TimeoutState;
use Uring\States\UringState;
use Uring\States\WriteState;

class UringFFI
{
    private CData $ring;
    private bool $stopped = false;
    private int $outstandingSqes = 0;
    /**
     * @var ObjectPool<AcceptState>
     */
    private ObjectPool $acceptPool;
    /**
     * @var ObjectPool<ReadState>
     */
    private ObjectPool $readPool;
    /**
     * @var ObjectPool<WriteState>
     */
    private ObjectPool $writePool;
    /**
     * @var ObjectPool<CancelState>
     */
    private ObjectPool $cancelPool;
    /**
     * @var ObjectPool<CloseState>
     */
    private ObjectPool $closePool;
    /**
     * @var ObjectPool<TimeoutState>
     */
    private ObjectPool $timeoutPool;
    /**
     * @var ObjectPool<ShutdownState>
     */
    private ObjectPool $shutdownPool;
    /**
     * @var ObjectPool<NopState>
     */
    private ObjectPool $nopPool;
    /**
     * @var ObjectPool<CreateSocketState>
     */
    private ObjectPool $createSocketPool;

    /**
     * @var array<EventType, ObjectPool<UringState>>
     */
    private array $pools;

    private CData $cqePtrArray;
    public readonly UringFlags $flags;
    private bool $initialized;
    private CData $ringAddr;

    public function __construct(
        private readonly int $queueDepth = 1024,
        int                  $commonBufferLength = 8192, int $requestStatesUnused = 1024, int $maxRequestStatesUnused = 8192,
        private readonly int $cqeBatchSize = 512
    )
    {
        $this->flags = new UringFlags();

        if (!$this->flags->supportsTimeout) {
            throw new RuntimeException("Kernel version is below 5.4, which does not support certain features required to run this");
        }

        $this->acceptPool = new ObjectPool(static fn() => new AcceptState(), $maxRequestStatesUnused);
        $this->acceptPool->initializeWithCount($requestStatesUnused);

        $this->readPool = new ObjectPool(static fn() => new ReadState($commonBufferLength), $maxRequestStatesUnused);
        $this->readPool->initializeWithCount($requestStatesUnused);

        $this->writePool = new ObjectPool(static fn() => new WriteState(), $maxRequestStatesUnused);
        $this->writePool->initializeWithCount($requestStatesUnused);

        $this->cancelPool = new ObjectPool(static fn() => new CancelState(), $maxRequestStatesUnused);

        $this->closePool = new ObjectPool(static fn() => new CloseState(), $maxRequestStatesUnused);

        $this->timeoutPool = new ObjectPool(static fn() => new TimeoutState(), $maxRequestStatesUnused);
        $this->timeoutPool->initializeWithCount(1);

        $this->shutdownPool = new ObjectPool(static fn() => new ShutdownState(), $maxRequestStatesUnused);

        $this->nopPool = new ObjectPool(static fn() => new NopState(), $maxRequestStatesUnused);

        $this->createSocketPool = new ObjectPool(static fn() => new CreateSocketState(), $maxRequestStatesUnused);
        $this->createSocketPool->initializeWithCount(1);

        $this->ring = FFI::unsafeNew(FFI::uring(), FFI::types('io_uring'));
        $this->ringAddr = \FFI::addr($this->ring);

        $this->cqePtrArray = FFI::unsafeNew(FFI::uring(), "io_uring_cqe*[$cqeBatchSize]");
    }

    public function getRingFd(): int
    {
        return $this->ring->ring_fd;
    }

    // This is outside the constructor because we need to initialize the queue with special flags if we are running
    // with multiple processes.
    public function initializeQueue(?int $masterFd = null): void
    {
        $params = FFI::unsafeNew(FFI::uring(), FFI::types('io_uring_params'));

        if ($masterFd !== null && $this->flags->supportsAttachWq) {
            $params->wq_fd = $masterFd;
            $params->flags |= UringFlags::IORING_SETUP_ATTACH_WQ;
        }

        FFI::uring()->io_uring_queue_init_params($this->queueDepth, $this->ringAddr, \FFI::addr($params));
        $this->initialized = true;
    }

    private function acceptRequest(int $fileDescriptor): void
    {
//        echo "ADD ACCEPT" . PHP_EOL;
        $request = $this->acceptPool->borrowObject();
        $request->serverSocket = $fileDescriptor;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_accept($sqe, $fileDescriptor, $request->getClientAddress(), $request->getClientAddressLength(), 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED ACCEPT {$request->getId()}" . PHP_EOL;
    }

    private function acceptMultishotRequest(int $fileDescriptor): void
    {
        $request = $this->acceptPool->borrowObject();
        $request->serverSocket = $fileDescriptor;
        $request->multishotRunning = true;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_multishot_accept($sqe, $fileDescriptor, \FFI::addr($request->getClientAddress()), \FFI::addr($request->getClientAddressLength()), 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED MULTISHOT ACCEPT {$request->getId()}" . PHP_EOL;
    }

    public function acceptRequests(int $fileDescriptor): void
    {
        if ($this->flags->supportsMultishotAccept) {
            $this->acceptMultishotRequest($fileDescriptor);
        } else {
            $this->acceptRequest($fileDescriptor);
        }
    }

    public function readRequest(int $fileDescriptor, int $bufferLength): void
    {
        $request = $this->readPool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;
        $request->allocateBuffer($bufferLength);

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_read($sqe, $fileDescriptor, $request->getBuffer(), $bufferLength, 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED READ {$request->getId()}" . PHP_EOL;
    }

    public function writeRequest(int $fileDescriptor, string &$buffer): void
    {
        $request = $this->writePool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;
        $request->setBuffer($buffer);

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_write($sqe, $fileDescriptor, $buffer, strlen($buffer), 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED WRITE {$request->getId()}" . PHP_EOL;
    }

    public function nopRequest(int $fileDescriptor): void
    {
        $request = $this->nopPool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_nop($sqe);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED WRITE {$request->getId()}" . PHP_EOL;
    }

    public function cancelRequests(int $fileDescriptor): void
    {
        $request = $this->cancelPool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_cancel_fd($sqe, $fileDescriptor, 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED CANCEL {$request->getId()}" . PHP_EOL;
    }

    public function shutdownRequest(int $fileDescriptor, int $how): void
    {
        $request = $this->shutdownPool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;
        $request->how = $how;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_shutdown($sqe, $fileDescriptor, $request->how);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED SHUTDOWN {$request->getId()}" . PHP_EOL;
    }

    public function closeRequest(int $fileDescriptor): void
    {
        $request = $this->closePool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_close($sqe, $fileDescriptor);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
//        echo "ADDED CANCEL {$request->getId()}" . PHP_EOL;
    }

    public function timeoutRequest(int $fileDescriptor, float $timeoutInSeconds): void
    {
        $request = $this->timeoutPool->borrowObject();
        $request->fileDescriptor = $fileDescriptor;
        $request->setTimeoutInSeconds($timeoutInSeconds);

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        // NOTE: IO_URING_ETIME_SUCCESS immediately triggers the timeout (???)
        // so we just handle the -ETIME error in handleCqe()
        // NOTE: Setting "count" to 0 disables the count-based waiting
        FFI::uring()->io_uring_prep_timeout($sqe, $request->getAddressOfTimeoutSpec(), 0, 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;
    }

    public function createSocketRequest(int $domain, int $type, int $protocol): int
    {
        $request = $this->createSocketPool->borrowObject();
        $request->fileDescriptor = random_int(-1024, -2);

        $sqe = FFI::uring()->io_uring_get_sqe($this->ringAddr);
        FFI::uring()->io_uring_prep_socket($sqe, $domain, $type, $protocol, 0);
        FFI::uring()->io_uring_sqe_set_data64($sqe, $request->packIdAndEventType());
        $this->outstandingSqes++;

        return $request->fileDescriptor;
    }

    public function conditionalSubmit(): void
    {
        if ($this->outstandingSqes > 0) {
            $this->outstandingSqes = 0;
            FFI::uring()->io_uring_submit($this->ringAddr);
        }
    }

    public function submit(): void
    {
        $this->outstandingSqes = 0;
        FFI::uring()->io_uring_submit($this->ringAddr);
    }

    public function peek(): ?UringState
    {
        $state = null;
        $cqePtrArrayPtr = \FFI::addr($this->cqePtrArray);
        if (FFI::uring()->io_uring_peek_cqe($this->ringAddr, $cqePtrArrayPtr) === 0) {
            $state = $this->handleCqe($cqePtrArrayPtr[0][0]);
            // Mark this request as processed
            FFI::uring()->io_uring_cq_advance($this->ringAddr, 1);
        }

        return $state;
    }

    /**
     * @return UringState[]
     */
    public function peekBatch(int $batchSize = null, int $maxBatchSize = 2048): array
    {
        $batchSize ??= $this->cqeBatchSize;
        $states = [];
        $cqePtrArrayPtr = \FFI::addr($this->cqePtrArray);

        while (count($states) < $maxBatchSize) {
            if (($filled = FFI::uring()->io_uring_peek_batch_cqe($this->ringAddr, $cqePtrArrayPtr, $batchSize)) > 0) {
                for ($i = 0; $i < $filled; $i++) {
                    // NOTE: Manually inlined handleCqe(), keep in sync with it
                    $cqe = $cqePtrArrayPtr[0][$i];
                    $id = FFI::uring()->io_uring_cqe_get_data64($cqe);
                    $type = UringState::extractEventType($id);
                    $id = UringState::extractId($id);

                    $state = match ($type) {
                        EventType::ACCEPT => $this->acceptPool->getBorrowedObjectById($id),
                        EventType::READ => $this->readPool->getBorrowedObjectById($id),
                        EventType::WRITE => $this->writePool->getBorrowedObjectById($id),
                        EventType::CANCEL => $this->cancelPool->getBorrowedObjectById($id),
                        EventType::CLOSE => $this->closePool->getBorrowedObjectById($id),
                        EventType::TIMEOUT => $this->timeoutPool->getBorrowedObjectById($id),
                        EventType::SHUTDOWN => $this->shutdownPool->getBorrowedObjectById($id),
                        EventType::NOP => $this->nopPool->getBorrowedObjectById($id),
                        EventType::CREATE_SOCKET => $this->createSocketPool->getBorrowedObjectById($id)
                    };

                    if ($type === EventType::ACCEPT) {
                        // handle multishot request getting cancelled
                        if ($this->flags->supportsMultishotAccept) {
                            if (($cqe->flags & UringFlags::IORING_CQE_F_MORE) !== UringFlags::IORING_CQE_F_MORE) {
                                $this->acceptMultishotRequest($state->getServerSocket());
                            }
                        } else {
                            $this->acceptRequest($state->getServerSocket());
                        }
                    }


                    $res = $cqe->res;
                    match ($res < 0) {
                        true => $this->handleError(abs($res), $state),
                        false => match ($state::class) {
                            AcceptState::class => $state->fileDescriptor = $res,
                            ReadState::class, WriteState::class => $state->bufferWritten = $res,
                            CreateSocketState::class => $state->createdFileDescriptor = $res,
                            default => null
                        }
                    };
                    $states[] = $state;
                }

                FFI::uring()->io_uring_cq_advance($this->ringAddr, (int)$filled);

                // Do not go for a second round if we didn't fill up with CQEs.
                // That's unnecessary FFI traffic when we could do processing instead
                if ($filled < $batchSize && count($states) > 0) {
                    break;
                }
            } else {
                break;
            }
        }

        return $states;
    }

    public function wait(): UringState
    {
        $cqePtrArrayPtr = \FFI::addr($this->cqePtrArray);
        if (FFI::uring()->io_uring_wait_cqe($this->ringAddr, $cqePtrArrayPtr) < 0) {
            throw new RuntimeException("io_uring_wait_cqe()");
        }

        $state = $this->handleCqe($cqePtrArrayPtr[0][0]);

        // Mark this request as processed
        FFI::uring()->io_uring_cq_advance($this->ringAddr, 1);

        return $state;
    }

    /**
     * @return UringState[]
     */
    public function submitAndWait(): array
    {
        $this->outstandingSqes = 0;
        if (FFI::uring()->io_uring_submit_and_wait($this->ringAddr, 1) < 0) {
            throw new RuntimeException("io_uring_submit_and_wait");
        }

        return $this->peekBatch();
    }

    public function freeRequestState(UringState $state): void
    {
        match ($state::class) {
            AcceptState::class => match ($state->multishotRunning) {
                true => null,
                false => $this->acceptPool->returnObject($state),
            },
            ReadState::class => $this->readPool->returnObject($state),
            WriteState::class => $this->writePool->returnObject($state),
            CancelState::class => $this->cancelPool->returnObject($state),
            CloseState::class => $this->closePool->returnObject($state),
            TimeoutState::class => $this->timeoutPool->returnObject($state),
            ShutdownState::class => $this->shutdownPool->returnObject($state),
            NopState::class => $this->nopPool->returnObject($state),
            CreateSocketState::class => $this->createSocketPool->returnObject($state)
        };
    }

    /**
     * @param UringState[] $states
     */
    public function freeRequestStates(array $states): void
    {
        foreach ($states as $state) {
            match ($state::class) {
                AcceptState::class => match ($state->multishotRunning) {
                    true => null,
                    false => $this->acceptPool->returnObject($state),
                },
                ReadState::class => $this->readPool->returnObject($state),
                WriteState::class => $this->writePool->returnObject($state),
                CancelState::class => $this->cancelPool->returnObject($state),
                CloseState::class => $this->closePool->returnObject($state),
                TimeoutState::class => $this->timeoutPool->returnObject($state),
                ShutdownState::class => $this->shutdownPool->returnObject($state),
                NopState::class => $this->nopPool->returnObject($state),
                CreateSocketState::class => $this->createSocketPool->returnObject($state)
            };
        }
    }

    private function handleCqe(CData $cqe): ?UringState
    {
        $id = FFI::uring()->io_uring_cqe_get_data64($cqe);
        $type = UringState::extractEventType($id);
        $id = UringState::extractId($id);

        $state = match ($type) {
            EventType::ACCEPT => $this->acceptPool->getBorrowedObjectById($id),
            EventType::READ => $this->readPool->getBorrowedObjectById($id),
            EventType::WRITE => $this->writePool->getBorrowedObjectById($id),
            EventType::CANCEL => $this->cancelPool->getBorrowedObjectById($id),
            EventType::CLOSE => $this->closePool->getBorrowedObjectById($id),
            EventType::TIMEOUT => $this->timeoutPool->getBorrowedObjectById($id),
            EventType::SHUTDOWN => $this->shutdownPool->getBorrowedObjectById($id),
            EventType::NOP => $this->nopPool->getBorrowedObjectById($id),
            EventType::CREATE_SOCKET => $this->createSocketPool->getBorrowedObjectById($id)
        };

        if ($type === EventType::ACCEPT) {
            // handle multishot request getting cancelled
            if ($this->flags->supportsMultishotAccept) {
                if (($cqe->flags & UringFlags::IORING_CQE_F_MORE) !== UringFlags::IORING_CQE_F_MORE) {
                    $this->acceptMultishotRequest($state->getServerSocket());
                    $state->multishotRunning = false;
                }
            } else {
                $this->acceptRequest($state->getServerSocket());
            }
        }


        $res = $cqe->res;
        match ($res < 0) {
            true => $this->handleError(abs($res), $state),
            false => match ($state::class) {
                AcceptState::class => $state->fileDescriptor = $res,
                ReadState::class, WriteState::class => $state->bufferWritten = $res,
                default => null
            }
        };

        return $state;
    }

    private function handleError(int $errNo, InputOutputState $state): void
    {
        match ($state::class) {
            NopState::class => null,
            default => match ($errNo) {
                2, 9 => match ($state::class) {
                    CloseState::class, CancelState::class, ShutdownState::class => null,
                    default => $state->exception = new BadFileDescriptorException($errNo)
                },
                22 => $state->exception = new InvalidArgumentException(),
                62 => $state->exception = new TimeoutException(),
                104 => $state->exception = new ConnectionResetException(),
                107 => match ($state::class) {
                    CloseState::class, CancelState::class, ShutdownState::class => null,
                    default => $state->exception = new TransportEndpointNotConnectedException(),
                },
                125 => match ($state::class) {
                    CloseState::class, CancelState::class, ShutdownState::class => null,
                    default => $state->exception = new OperationCancelledException()
                },
                default => match ($state::class) {
                    // TODO
                    WriteState::class => $state->exception = new UringException(FFI::explain()->explain_errno_write(abs($errNo), $state->getFileDescriptor(), $state->getBuffer(), $state->getBufferLength())),
                    default => $state->exception = new UringException("General error $errNo")
                }
            }
        };

        // Handle error case and Uring not being able to submit all SQEs
        if (isset($state->exception) && !$this->flags->supportsSubmitAll) {
            $this->submit();
        }
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        if ($this->initialized && isset($this->ring)) {
            FFI::uring()->io_uring_queue_exit($this->ringAddr);
        }
    }
}
