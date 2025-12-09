<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * FXSAVE/FXRSTOR/MFENCE/LFENCE/SFENCE/CLFLUSH (0x0F 0xAE)
 */
class Fxsave implements InstructionInterface
{
    use Instructable;

    private array $xmm = [];
    private int $mxcsr = 0x1F80;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xAE]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrmByte = $reader->streamReader()->byte();
        $modrm = $reader->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());
        $reg = $modrm->registerOrOPCode() & 0x7;

        // MFENCE/LFENCE/SFENCE encoded as 0F AE with mod == 11
        if ($mod === ModType::REGISTER_TO_REGISTER) {
            // fence instructions - no-op
            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modrm);

        if ($reg === 0) { // FXSAVE
            return $this->fxsave($runtime, $address);
        } elseif ($reg === 1) { // FXRSTOR
            return $this->fxrstor($runtime, $address);
        } elseif ($reg === 7) { // CLFLUSH
            // Consume address and treat as no-op
            return ExecutionStatus::SUCCESS;
        }

        return ExecutionStatus::SUCCESS;
    }

    private function fxsave(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
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
        $this->writeMemory32($runtime, $address + 24, $this->mxcsr);
        $this->writeMemory32($runtime, $address + 28, 0xFFFF);

        // Save first 8 XMM registers
        $this->initXmm();
        $base = $address + 160;
        for ($i = 0; $i < 8; $i++) {
            $regVals = $this->xmm[$i] ?? [0, 0, 0, 0];
            $this->writeMemory32($runtime, $base + ($i * 16) + 0, $regVals[0]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 4, $regVals[1]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 8, $regVals[2]);
            $this->writeMemory32($runtime, $base + ($i * 16) + 12, $regVals[3]);
        }

        // Zero the rest
        for ($i = 48; $i < 160; $i++) {
            $this->writeMemory8($runtime, ($address + $i) & 0xFFFFFFFF, 0);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function fxrstor(RuntimeInterface $runtime, int $address): ExecutionStatus
    {
        $this->translateLinear($runtime, $address, false);
        $this->mxcsr = $this->readMemory32($runtime, $address + 24);
        $this->initXmm();

        $base = $address + 160;
        for ($i = 0; $i < 8; $i++) {
            $d0 = $this->readMemory32($runtime, $base + ($i * 16));
            $d1 = $this->readMemory32($runtime, $base + ($i * 16) + 4);
            $d2 = $this->readMemory32($runtime, $base + ($i * 16) + 8);
            $d3 = $this->readMemory32($runtime, $base + ($i * 16) + 12);
            $this->xmm[$i] = [
                $d0 & 0xFFFFFFFF,
                $d1 & 0xFFFFFFFF,
                $d2 & 0xFFFFFFFF,
                $d3 & 0xFFFFFFFF,
            ];
        }

        return ExecutionStatus::SUCCESS;
    }

    private function initXmm(): void
    {
        if (!empty($this->xmm)) {
            return;
        }
        $this->xmm = array_fill(0, 8, [0, 0, 0, 0]);
    }
}
