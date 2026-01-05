<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\UEFI\UEFIAllocator;
use PHPMachineEmulator\UEFI\UEFIDispatcher;

interface UEFIEnvironmentInterface
{
    public function dispatcher(): UEFIDispatcher;

    public function imageHandle(): int;

    public function systemTable(): int;

    public function allocator(): UEFIAllocator;

    public function build(): int;

    public function allocateStack(int $size): int;

    public function maybeFastDecompressKernel(RuntimeInterface $runtime, int $ip, int $hitCount): bool;

    public function maybeRecoverKernelJump(RuntimeInterface $runtime, int $ip, int $vector): bool;
}
