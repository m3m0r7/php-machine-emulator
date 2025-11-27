<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Minimal stubs for common FPU instructions used by OS probing.
 */
class FpuStub implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // WAIT/FWAIT, and ESC opcodes for FNINIT/FNSAVE/FRSTOR/FNSTSW/FLDCW/FNSTCW
        return [0x9B, 0xD9, 0xDB, 0xDD, 0xDF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $this->assertFpuAvailable($runtime);
        $reader = new EnhanceStreamReader($runtime->streamReader());

        return match ($opcode) {
            0x9B => ExecutionStatus::SUCCESS, // FWAIT
            0xD9 => $this->handleD9($runtime, $reader),
            0xDB => $this->handleDB($runtime, $reader),
            0xDD => $this->handleDD($runtime, $reader),
            0xDF => $this->handleDF($runtime, $reader),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function handleD9(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $next = $reader->streamReader()->byte();
        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;
        $rm = $next & 0x7;

        if ($mod === 0b11) {
            return ExecutionStatus::SUCCESS; // register-only forms ignored
        }

        if ($reg === 5) {
            // FLDCW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $reader, $next);
            $this->readMemory16($runtime, $addr); // consume
        } elseif ($reg === 7) {
            // FNSTCW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $reader, $next);
            $this->writeMemory16($runtime, $addr, 0x037F); // default control word
        } else {
            $this->consumeRmBytes($reader, $mod, $rm);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function handleDB(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $next = $reader->streamReader()->byte();
        if ($next === 0xE3) {
            // FNINIT
            $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte());
            return ExecutionStatus::SUCCESS;
        }

        $mod = ($next >> 6) & 0x3;
        $rm = $next & 0x7;
        $this->consumeRmBytes($reader, $mod, $rm);
        return ExecutionStatus::SUCCESS;
    }

    private function handleDD(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $next = $reader->streamReader()->byte();
        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;
        $rm = $next & 0x7;

        if ($mod === 0b11) {
            return ExecutionStatus::SUCCESS; // ignore register forms
        }

        $addr = $this->rmLinearAddressFromModrm($runtime, $reader, $next);
        $saveSize = $runtime->runtimeOption()->context()->operandSize() === 32 ? 108 : 94;

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
            $this->consumeRmBytes($reader, $mod, $rm);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function handleDF(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $next = $reader->streamReader()->byte();
        if ($next === 0xE0) {
            // FNSTSW AX
            $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, 0);
            return ExecutionStatus::SUCCESS;
        }

        $mod = ($next >> 6) & 0x3;
        $reg = ($next >> 3) & 0x7;
        $rm = $next & 0x7;

        if ($mod !== 0b11 && $reg === 4) {
            // FNSTSW m2byte
            $addr = $this->rmLinearAddressFromModrm($runtime, $reader, $next);
            $this->writeMemory16($runtime, $addr, 0);
            return ExecutionStatus::SUCCESS;
        }

        $this->consumeRmBytes($reader, $mod, $rm);
        return ExecutionStatus::SUCCESS;
    }

    private function rmLinearAddressFromModrm(RuntimeInterface $runtime, EnhanceStreamReader $reader, int $modrmByte): int
    {
        $modrm = $reader->modRegRM($modrmByte);
        return $this->rmLinearAddress($runtime, $reader, $modrm);
    }

    private function consumeRmBytes(EnhanceStreamReader $reader, int $mod, int $rm): void
    {
        // Best-effort discard of displacement.
        if ($mod === 0b01) {
            $reader->streamReader()->byte();
        } elseif ($mod === 0b10 || ($mod === 0b00 && $rm === 0b110)) {
            $reader->word();
        }
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
