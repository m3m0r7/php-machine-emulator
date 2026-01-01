<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Minimal stubs for common FPU instructions used by OS probing.
 */
class FpuStub implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // WAIT/FWAIT, and ESC opcodes for FPU instructions
        // 0x9B: FWAIT
        // 0xD8-0xDF: FPU escape opcodes (x87 coprocessor)
        return $this->applyPrefixes([0x9B, 0xD8, 0xD9, 0xDA, 0xDB, 0xDC, 0xDD, 0xDE, 0xDF]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $this->assertFpuAvailable($runtime);
        $memory = $runtime->memory();

        return match ($opcode) {
            0x9B => ExecutionStatus::SUCCESS, // FWAIT
            0xD8 => $this->handleGenericFpu($runtime, $memory), // FADD/FMUL/FCOM/etc.
            0xD9 => $this->handleD9($runtime, $memory),
            0xDA => $this->handleGenericFpu($runtime, $memory), // FIADD/FIMUL/etc.
            0xDB => $this->handleDB($runtime, $memory),
            0xDC => $this->handleGenericFpu($runtime, $memory), // FADD/FMUL/etc. (double)
            0xDD => $this->handleDD($runtime, $memory),
            0xDE => $this->handleGenericFpu($runtime, $memory), // FIADD/FIMUL/etc. (word)
            0xDF => $this->handleDF($runtime, $memory),
            default => ExecutionStatus::SUCCESS,
        };
    }

    /**
     * Handle generic FPU opcodes (0xD8, 0xDA, 0xDC, 0xDE) by consuming the ModR/M byte
     * and any displacement bytes, then returning SUCCESS.
     */
    private function handleGenericFpu(RuntimeInterface $runtime, MemoryStreamInterface $memory): ExecutionStatus
    {
        $modrm = $memory->byteAsModRegRM();
        if (ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER) {
            // Register-to-register form, no additional bytes
            return ExecutionStatus::SUCCESS;
        }

        // Memory operand form - consume SIB/displacement bytes per address size
        $this->rmLinearAddress($runtime, $memory, $modrm);

        return ExecutionStatus::SUCCESS;
    }

    private function handleD9(RuntimeInterface $runtime, MemoryStreamInterface $memory): ExecutionStatus
    {
        $next = $memory->byte();
        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;

        if ($mod === 0b11) {
            return ExecutionStatus::SUCCESS; // register-only forms ignored
        }

        if ($reg === 5) {
            // FLDCW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $memory, $next);
            $this->readMemory16($runtime, $addr); // consume
        } elseif ($reg === 7) {
            // FNSTCW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $memory, $next);
            $this->writeMemory16($runtime, $addr, 0x037F); // default control word
        } else {
            $this->rmLinearAddressFromModrm($runtime, $memory, $next);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function handleDB(RuntimeInterface $runtime, MemoryStreamInterface $memory): ExecutionStatus
    {
        $next = $memory->byte();
        if ($next === 0xE3) {
            // FNINIT
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte());
            return ExecutionStatus::SUCCESS;
        }

        $mod = ($next >> 6) & 0x3;
        if ($mod !== 0b11) {
            $this->rmLinearAddressFromModrm($runtime, $memory, $next);
        }
        return ExecutionStatus::SUCCESS;
    }

    private function handleDD(RuntimeInterface $runtime, MemoryStreamInterface $memory): ExecutionStatus
    {
        $next = $memory->byte();
        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;

        if ($mod === 0b11) {
            return ExecutionStatus::SUCCESS; // ignore register forms
        }

        $addr = $this->rmLinearAddressFromModrm($runtime, $memory, $next);
        $saveSize = $runtime->context()->cpu()->operandSize() === 32 ? 108 : 94;

        if ($reg === 6) { // FNSAVE m94/108byte
            for ($i = 0; $i < $saveSize; $i++) {
                $this->writeMemory8($runtime, ($addr + $i) & 0xFFFFFFFF, 0);
            }
        } elseif ($reg === 4) { // FRSTOR
            // Read/ignore saved state.
            for ($i = 0; $i < $saveSize; $i++) {
                $this->readMemory8($runtime, ($addr + $i) & 0xFFFFFFFF);
            }
        } else {
            $this->rmLinearAddressFromModrm($runtime, $memory, $next);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function handleDF(RuntimeInterface $runtime, MemoryStreamInterface $memory): ExecutionStatus
    {
        $next = $memory->byte();
        if ($next === 0xE0) {
            // FNSTSW AX
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, 0);
            return ExecutionStatus::SUCCESS;
        }

        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;

        if ($mod !== 0b11 && $reg === 4) {
            // FNSTSW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $memory, $next);
            $this->writeMemory16($runtime, $addr, 0);
            return ExecutionStatus::SUCCESS;
        }

        if ($mod !== 0b11) {
            $this->rmLinearAddressFromModrm($runtime, $memory, $next);
        }
        return ExecutionStatus::SUCCESS;
    }

    private function rmLinearAddressFromModrm(RuntimeInterface $runtime, MemoryStreamInterface $memory, int $modrmByte): int
    {
        $modrm = $memory->modRegRM($modrmByte);
        return $this->rmLinearAddress($runtime, $memory, $modrm);
    }

    private function assertFpuAvailable(RuntimeInterface $runtime): void
    {
        $cr0 = $runtime->memoryAccessor()->readControlRegister(0);
        $ts = ($cr0 & (1 << 3)) !== 0;
        $em = ($cr0 & (1 << 2)) !== 0;
        if ($ts || $em) {
            throw new FaultException(0x07, 0, 'Device not available');
        }
    }
}
