<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * SYSCALL instruction for 64-bit mode.
 *
 * Opcode: 0F 05
 *
 * Fast system call mechanism in 64-bit mode.
 * - Saves RIP to RCX
 * - Saves RFLAGS to R11
 * - Loads CS and SS from IA32_STAR MSR
 * - Loads RIP from IA32_LSTAR MSR
 * - Masks RFLAGS with IA32_FMASK MSR
 *
 * System call number is passed in RAX.
 * Arguments are passed in RDI, RSI, RDX, R10, R8, R9.
 * Return value is in RAX.
 */
class Syscall implements InstructionInterface
{
    use Instructable64;

    /**
     * MSR addresses for SYSCALL/SYSRET.
     */
    private const IA32_STAR = 0xC0000081;
    private const IA32_LSTAR = 0xC0000082;
    private const IA32_CSTAR = 0xC0000083;  // For compatibility mode
    private const IA32_FMASK = 0xC0000084;

    public function opcodes(): array
    {
        // This is a 2-byte opcode (0F 05), handled via TwoBytePrefix
        // We return empty here as it's dispatched differently
        return [];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // SYSCALL is only valid in 64-bit mode
        if (!$cpu->isLongMode() || $cpu->isCompatibilityMode()) {
            throw new \RuntimeException('SYSCALL is only valid in 64-bit mode');
        }

        $memAccessor = $runtime->memoryAccessor();

        // Save RIP (next instruction address) to RCX
        $rip = $runtime->memory()->offset();
        $memAccessor->writeBySize(RegisterType::ECX, $rip, 64);

        // Save RFLAGS to R11
        $rflags = $this->readRflags($runtime);
        $memAccessor->writeBySize(RegisterType::R11, $rflags, 64);

        // Read MSRs (simplified - in real implementation, these would be stored in CPU context)
        $star = $this->readMsr($runtime, self::IA32_STAR);
        $lstar = $this->readMsr($runtime, self::IA32_LSTAR);
        $fmask = $this->readMsr($runtime, self::IA32_FMASK);

        // Load CS from STAR[47:32]
        $syscallCs = ($star >> 32) & 0xFFFF;
        // SS = CS + 8
        $syscallSs = $syscallCs + 8;

        // Update CS and SS
        $memAccessor->write16Bit(RegisterType::CS, $syscallCs);
        $memAccessor->write16Bit(RegisterType::SS, $syscallSs);

        // Mask RFLAGS with FMASK (clear bits that are set in FMASK)
        $newRflags = $rflags & ~$fmask;
        // Always clear IF, TF, AC, and RF
        $newRflags &= ~((1 << 9) | (1 << 8) | (1 << 18) | (1 << 16));
        $this->writeRflags($runtime, $newRflags);

        // Switch to ring 0
        $cpu->setCpl(0);

        // Load RIP from LSTAR
        $runtime->memory()->setOffset($lstar);

        // For emulation purposes, we'll handle the system call here
        // In a real emulator, this would jump to kernel code
        return $this->handleSystemCall($runtime);
    }

    /**
     * Handle system call (emulated).
     * This provides basic Linux x86-64 syscall emulation.
     */
    private function handleSystemCall(RuntimeInterface $runtime): ExecutionStatus
    {
        $memAccessor = $runtime->memoryAccessor();

        // Get syscall number from RAX
        $syscallNum = $memAccessor->fetch(RegisterType::EAX)->asBytesBySize(64);

        // Get arguments
        $arg1 = $memAccessor->fetch(RegisterType::EDI)->asBytesBySize(64); // RDI
        $arg2 = $memAccessor->fetch(RegisterType::ESI)->asBytesBySize(64); // RSI
        $arg3 = $memAccessor->fetch(RegisterType::EDX)->asBytesBySize(64); // RDX
        $arg4 = $memAccessor->fetch(RegisterType::R10)->asBytesBySize(64); // R10
        $arg5 = $memAccessor->fetch(RegisterType::R8)->asBytesBySize(64);  // R8
        $arg6 = $memAccessor->fetch(RegisterType::R9)->asBytesBySize(64);  // R9

        $result = match ($syscallNum) {
            // sys_write (1): write(fd, buf, count)
            1 => $this->sysWrite($runtime, $arg1, $arg2, $arg3),

            // sys_exit (60): exit(status)
            60 => $this->sysExit($runtime, $arg1),

            // sys_exit_group (231): exit_group(status)
            231 => $this->sysExit($runtime, $arg1),

            // Default: return -ENOSYS (not implemented)
            default => -38,
        };

        // Set return value in RAX (64-bit)
        $memAccessor->writeBySize(RegisterType::EAX, $result, 64);

        // For exit syscalls, return EXIT status
        if ($syscallNum === 60 || $syscallNum === 231) {
            return ExecutionStatus::EXIT;
        }

        // Return to user mode (simplified)
        return $this->sysret($runtime);
    }

    /**
     * sys_write syscall emulation.
     */
    private function sysWrite(RuntimeInterface $runtime, int $fd, int $buf, int $count): int
    {
        // Only support stdout (1) and stderr (2)
        if ($fd !== 1 && $fd !== 2) {
            return -9; // -EBADF
        }

        $output = '';
        for ($i = 0; $i < $count; $i++) {
            $byte = $this->readMemory8($runtime, $buf + $i);
            $output .= chr($byte);
        }

        $runtime->option()->IO()->output()->write($output);

        return $count;
    }

    /**
     * sys_exit syscall emulation.
     */
    private function sysExit(RuntimeInterface $runtime, int $status): int
    {
        // Push exit code to frame for ExitException
        $runtime->frame()->push($status & 0xFF);
        return $status;
    }

    /**
     * SYSRET - return from syscall.
     */
    private function sysret(RuntimeInterface $runtime): ExecutionStatus
    {
        $memAccessor = $runtime->memoryAccessor();
        $cpu = $runtime->context()->cpu();

        // Restore RIP from RCX
        $rip = $memAccessor->fetch(RegisterType::ECX)->asBytesBySize(64);

        // Restore RFLAGS from R11 (with some bits masked)
        $rflags = $memAccessor->fetch(RegisterType::R11)->asBytesBySize(64);
        // Set reserved bit 1, clear RF
        $rflags = ($rflags | 0x02) & ~(1 << 16);
        $this->writeRflags($runtime, $rflags);

        // Read STAR MSR for user CS/SS
        $star = $this->readMsr($runtime, self::IA32_STAR);
        $sysretCs = (($star >> 48) & 0xFFFF) + 16;  // 64-bit mode
        $sysretSs = $sysretCs + 8;

        // Update CS and SS
        $memAccessor->write16Bit(RegisterType::CS, $sysretCs);
        $memAccessor->write16Bit(RegisterType::SS, $sysretSs);

        // Return to ring 3
        $cpu->setCpl(3);

        // Set RIP
        $runtime->memory()->setOffset($rip);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Read RFLAGS register.
     */
    private function readRflags(RuntimeInterface $runtime): int
    {
        $memAccessor = $runtime->memoryAccessor();
        $flags = 0;

        // Build flags from individual flag states
        if ($memAccessor->shouldCarryFlag()) {
            $flags |= 1 << 0;  // CF
        }
        $flags |= 1 << 1;  // Reserved, always 1
        if ($memAccessor->shouldParityFlag()) {
            $flags |= 1 << 2;  // PF
        }
        if ($memAccessor->shouldAuxiliaryCarryFlag()) {
            $flags |= 1 << 4;  // AF
        }
        if ($memAccessor->shouldZeroFlag()) {
            $flags |= 1 << 6;  // ZF
        }
        if ($memAccessor->shouldSignFlag()) {
            $flags |= 1 << 7;  // SF
        }
        if ($memAccessor->shouldInterruptFlag()) {
            $flags |= 1 << 9;  // IF
        }
        if ($memAccessor->shouldDirectionFlag()) {
            $flags |= 1 << 10; // DF
        }
        if ($memAccessor->shouldOverflowFlag()) {
            $flags |= 1 << 11; // OF
        }

        return $flags;
    }

    /**
     * Write RFLAGS register.
     */
    private function writeRflags(RuntimeInterface $runtime, int $flags): void
    {
        $memAccessor = $runtime->memoryAccessor();

        $memAccessor->setCarryFlag(($flags & (1 << 0)) !== 0);
        $memAccessor->setParityFlag(($flags & (1 << 2)) !== 0);
        $memAccessor->setAuxiliaryCarryFlag(($flags & (1 << 4)) !== 0);
        $memAccessor->setZeroFlag(($flags & (1 << 6)) !== 0);
        $memAccessor->setSignFlag(($flags & (1 << 7)) !== 0);
        $memAccessor->setInterruptFlag(($flags & (1 << 9)) !== 0);
        $memAccessor->setDirectionFlag(($flags & (1 << 10)) !== 0);
        $memAccessor->setOverflowFlag(($flags & (1 << 11)) !== 0);
    }

    /**
     * Read MSR (simplified - returns default values).
     */
    private function readMsr(RuntimeInterface $runtime, int $msr): int
    {
        // In a full implementation, these would be stored in CPU context
        return match ($msr) {
            self::IA32_STAR => 0x0023001000000000,  // User CS=0x23, Kernel CS=0x10
            self::IA32_LSTAR => 0x0000000000000000, // Kernel entry point
            self::IA32_CSTAR => 0x0000000000000000,
            self::IA32_FMASK => 0x0000000000000000, // Don't mask any flags
            default => 0,
        };
    }
}
