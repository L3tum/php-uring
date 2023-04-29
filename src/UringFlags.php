<?php

namespace Uring;

/**
 * @internal
 */
class UringFlags
{
    public const IORING_CQE_F_MORE = 1 << 1;
    /* attach to existing wq */
    public const IORING_SETUP_ATTACH_WQ = (1 << 5);
    public const IORING_TIMEOUT_ETIME_SUCCESS = (1 << 5);
    /* links next sqe */
    public const IOSQE_IO_LINK = (0b1 << 2);
    /* don't post CQE if request succeeded */
    public const IOSQE_CQE_SKIP_SUCCESS = (1 << 6);
    public const IORING_SETUP_SINGLE_ISSUER = (1 << 12);

    public const IORING_ASYNC_CANCEL_ALL = (1 << 0);
    public const IORING_ASYNC_CANCEL_FD = (1 << 1);
    public const IORING_ASYNC_CANCEL_ANY = (1 << 2);
    public const IORING_ASYNC_CANCEL_FD_FIXED = (1 << 3);
    public readonly bool $supportsMultishotAccept;
    public readonly bool $supportsSubmitAll;
    public readonly bool $supportsCoopTaskRun;
    public readonly bool $supportsSingleIssuer;
    public readonly bool $supportsDeferTaskRun;
    public readonly bool $supportsAttachWq;
    public readonly bool $supportsTimeout;
    public readonly bool $supportsCancelAll;
    public readonly bool $supportsCancelFd;
    public readonly bool $supportsShutdown;
    public readonly bool $supportsCreateSocket;

    public function __construct()
    {
        $this->supportsTimeout = $this->minVersionRequired(5.4);
        $this->supportsAttachWq = $this->minVersionRequired(5.6);
        $this->supportsShutdown = $this->minVersionRequired(5.11);
        $this->supportsSubmitAll = $this->minVersionRequired(5.18);
        $this->supportsCancelAll = $this->minVersionRequired(5.19);
        $this->supportsCancelFd = $this->minVersionRequired(5.19);
        $this->supportsMultishotAccept = $this->minVersionRequired(5.19);
        $this->supportsCoopTaskRun = $this->minVersionRequired(5.19);
        $this->supportsCreateSocket = $this->minVersionRequired(5.19);
        $this->supportsSingleIssuer = $this->minVersionRequired(6.0);
        $this->supportsDeferTaskRun = $this->minVersionRequired(6.1);
    }

    private function minVersionRequired(float $requiredVersion): bool
    {
        $kernelVersion = ((float)posix_uname()['release']);
        $kernelVersionMajor = $this->getKernelVersionMajor($kernelVersion);
        $kernelVersionMinor = $this->getKernelVersionMinor($kernelVersion);

        $requiredVersionMajor = $this->getKernelVersionMajor($requiredVersion);
        $requiredVersionMinor = $this->getKernelVersionMinor($requiredVersion);

        return $kernelVersionMajor > $requiredVersionMajor
            || (
                $kernelVersionMajor === $requiredVersionMajor
                && $kernelVersionMinor >= $requiredVersionMinor
            );
    }

    private function getKernelVersionMajor(float $kernelVersion): int
    {
        return floor($kernelVersion);
    }

    private function getKernelVersionMinor(float $kernelVersion): int
    {
        $kernelVersionMinor = $kernelVersion - floor($kernelVersion);

        // Number of digits after the dot (i.e. minus two) in e.g. 5.4 it's 1, in 5.15 it's 2 and so on.
        $multiplier = strlen((string)$kernelVersionMinor) - 2;
        return floor($kernelVersionMinor * (10 ** $multiplier));
    }
}
