<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Group 6 (0x0F 0x01)
 * SGDT, SIDT, LGDT, LIDT, LMSW, INVLPG
 */
class Group6 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x01]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();

        return match ($modrm->registerOrOPCode()) {
            0b000 => $this->sgdt($runtime, $reader, $modrm),
            0b001 => $this->sidt($runtime, $reader, $modrm),
            0b010 => $this->lgdt($runtime, $reader, $modrm),
            0b011 => $this->lidt($runtime, $reader, $modrm),
            0b101 => $this->lmsw($runtime, $reader, $modrm),
            0b111 => $this->invlpg($runtime, $reader, $modrm),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function sgdt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $gdtr = $runtime->context()->cpu()->gdtr();
        $this->writeMemory16($runtime, $address, $gdtr['limit'] ?? 0);
        $base = $gdtr['base'] ?? 0;
        $this->writeMemory16($runtime, $address + 2, $base & 0xFFFF);
        $this->writeMemory16($runtime, $address + 4, ($base >> 16) & 0xFFFF);
        return ExecutionStatus::SUCCESS;
    }

    private function sidt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $idtr = $runtime->context()->cpu()->idtr();
        $this->writeMemory16($runtime, $address, $idtr['limit'] ?? 0);
        $base = $idtr['base'] ?? 0;
        $this->writeMemory16($runtime, $address + 2, $base & 0xFFFF);
        $this->writeMemory16($runtime, $address + 4, ($base >> 16) & 0xFFFF);
        return ExecutionStatus::SUCCESS;
    }

    private function lgdt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->context()->cpu()->setGdtr($base, $limit);

        $runtime->option()->logger()->debug(sprintf(
            'LGDT: address=0x%05X base=0x%08X limit=0x%04X',
            $address,
            $base,
            $limit
        ));

        // Dump first 5 GDT entries for debugging
        for ($i = 0; $i < 5 && ($i * 8) <= $limit; $i++) {
            $descAddr = $base + ($i * 8);
            $bytes = [];
            for ($j = 0; $j < 8; $j++) {
                $bytes[] = sprintf('%02X', $this->readMemory8($runtime, $descAddr + $j));
            }
            $runtime->option()->logger()->debug(sprintf(
                'GDT[%d] at 0x%08X: %s',
                $i,
                $descAddr,
                implode(' ', $bytes)
            ));
        }

        return ExecutionStatus::SUCCESS;
    }

    private function lidt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->context()->cpu()->setIdtr($base, $limit);
        return ExecutionStatus::SUCCESS;
    }

    private function lmsw(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $value = $this->readRm16($runtime, $reader, $modrm);
        // LMSW only affects lower 4 bits of CR0: PE, MP, EM, TS
        $cr0 = $runtime->memoryAccessor()->readControlRegister(0);
        $cr0 = ($cr0 & 0xFFFFFFF0) | ($value & 0xF);
        $runtime->option()->logger()->debug(sprintf(
            'LMSW: value=0x%04X -> CR0=0x%08X',
            $value & 0xFFFF,
            $cr0 | 0x22
        ));
        $runtime->memoryAccessor()->writeControlRegister(0, $cr0);
        $runtime->context()->cpu()->setProtectedMode((bool) ($cr0 & 0x1));
        $runtime->context()->cpu()->setPagingEnabled((bool) ($cr0 & 0x80000000));
        return ExecutionStatus::SUCCESS;
    }

    private function invlpg(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        // Just consume the memory operand; no TLB modeled
        $this->rmLinearAddress($runtime, $reader, $modrm);
        return ExecutionStatus::SUCCESS;
    }
}
