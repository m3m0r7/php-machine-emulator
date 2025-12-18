<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * FXSAVE/FXRSTOR/MFENCE/LFENCE/SFENCE/CLFLUSH (0x0F 0xAE)
 */
class Fxsave implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xAE]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrmByte = $memory->byte();
        $modrm = $memory->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());
        $reg = $modrm->registerOrOPCode() & 0x7;

        // MFENCE/LFENCE/SFENCE encoded as 0F AE with mod == 11
        if ($mod === ModType::REGISTER_TO_REGISTER) {
            // fence instructions - no-op
            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modrm);

        return match ($reg) {
            0 => $this->fxsave($runtime, $address),   // FXSAVE
            1 => $this->fxrstor($runtime, $address),  // FXRSTOR
            2 => $this->ldmxcsr($runtime, $address),  // LDMXCSR
            3 => $this->stmxcsr($runtime, $address),  // STMXCSR
            7 => ExecutionStatus::SUCCESS,            // CLFLUSH (no-op)
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function fxsave(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $xmmCount = ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) ? 16 : 8;

        // Initialize the whole 512-byte region to zero for deterministic reserved fields.
        for ($i = 0; $i < 512; $i += 4) {
            $this->writeMemory32($runtime, $address + $i, 0);
        }

        // FCW/FSW/FTW/Opcode/IP/DP placeholders
        $this->writeMemory16($runtime, $address + 0, 0x037F);
        $this->writeMemory16($runtime, $address + 2, 0);
        $this->writeMemory16($runtime, $address + 4, 0);
        $this->writeMemory16($runtime, $address + 6, 0);
        $this->writeMemory32($runtime, $address + 8, 0);
        $this->writeMemory32($runtime, $address + 12, 0);
        $this->writeMemory32($runtime, $address + 16, 0);
        $this->writeMemory32($runtime, $address + 20, 0);
        // MXCSR and mask
        $this->writeMemory32($runtime, $address + 24, $cpu->mxcsr());
        $this->writeMemory32($runtime, $address + 28, 0xFFFF);

        $base = $address + 160;
        for ($i = 0; $i < $xmmCount; $i++) {
            $regVals = $cpu->getXmm($i);
            $this->writeMemory32($runtime, $base + ($i * 16) + 0, $regVals[0]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 4, $regVals[1]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 8, $regVals[2]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 12, $regVals[3]);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function fxrstor(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $xmmCount = ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) ? 16 : 8;

        $this->translateLinearWithMmio($runtime, $address, false);
        $cpu->setMxcsr($this->readMemory32($runtime, $address + 24));

        $base = $address + 160;
        for ($i = 0; $i < $xmmCount; $i++) {
            $d0 = $this->readMemory32($runtime, $base + ($i * 16));
            $d1 = $this->readMemory32($runtime, $base + ($i * 16) + 4);
            $d2 = $this->readMemory32($runtime, $base + ($i * 16) + 8);
            $d3 = $this->readMemory32($runtime, $base + ($i * 16) + 12);
            $cpu->setXmm($i, [$d0, $d1, $d2, $d3]);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function ldmxcsr(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $this->translateLinearWithMmio($runtime, $address, false);
        $cpu->setMxcsr($this->readMemory32($runtime, $address));
        return ExecutionStatus::SUCCESS;
    }

    private function stmxcsr(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $this->translateLinearWithMmio($runtime, $address, true);
        $this->writeMemory32($runtime, $address, $cpu->mxcsr());
        return ExecutionStatus::SUCCESS;
    }
}
