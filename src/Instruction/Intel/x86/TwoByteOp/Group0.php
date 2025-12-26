<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Group 0 (0x0F 0x00)
 * SLDT, STR, LLDT, LTR
 */
class Group0 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x00]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

        return match ($modrm->registerOrOPCode()) {
            0b000 => $this->sldt($runtime, $memory, $modrm),
            0b001 => $this->str($runtime, $memory, $modrm),
            0b010 => $this->lldt($runtime, $memory, $modrm),
            0b011 => $this->ltr($runtime, $memory, $modrm),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function sldt(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $runtime->context()->cpu()->ldtr()['selector'] ?? 0;
        $this->writeRm16($runtime, $memory, $modrm, $selector);
        return ExecutionStatus::SUCCESS;
    }

    private function str(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $runtime->context()->cpu()->taskRegister()['selector'] ?? 0;
        $this->writeRm16($runtime, $memory, $modrm, $selector);
        return ExecutionStatus::SUCCESS;
    }

    private function lldt(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $this->readRm16($runtime, $memory, $modrm);

        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return ExecutionStatus::SUCCESS;
        }

        if (($selector & 0x4) !== 0) {
            throw new FaultException(0x0D, $selector, sprintf('LLDT selector 0x%04X must reference GDT (TI=0)', $selector));
        }

        if (($selector & 0xFFFC) === 0) {
            $runtime->context()->cpu()->setLdtr(0, 0, 0);
            return ExecutionStatus::SUCCESS;
        }

        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid LDT selector 0x%04X', $selector));
        }
        if (!$descriptor['present']) {
            throw new FaultException(0x0B, $selector, sprintf('LDT selector 0x%04X not present', $selector));
        }
        if (($descriptor['type'] ?? 0) !== 0x2) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not an LDT descriptor', $selector));
        }

        $runtime->context()->cpu()->setLdtr($selector, $descriptor['base'], $descriptor['limit']);
        return ExecutionStatus::SUCCESS;
    }

    private function ltr(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $this->readRm16($runtime, $memory, $modrm);

        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return ExecutionStatus::SUCCESS;
        }

        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid TSS selector 0x%04X', $selector));
        }
        if (!$descriptor['present']) {
            throw new FaultException(0x0B, $selector, sprintf('TSS selector 0x%04X not present', $selector));
        }

        $type = $descriptor['type'] ?? 0;
        $validTypes = [0x1, 0x3, 0x9, 0xB];
        if (!in_array($type, $validTypes, true)) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a TSS', $selector));
        }

        $runtime->context()->cpu()->setTaskRegister($selector, $descriptor['base'], $descriptor['limit']);

        // Mark TSS busy when loading if descriptor is "available"
        if ($type === 0x1 || $type === 0x9) {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($selector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;

            $access = $this->readMemory8($runtime, $accessAddr);
            $access |= 0x02; // busy bit

            $phys = $this->translateLinearWithMmio($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $this->writeMemory8($runtime, $phys, $access & 0xFF);
        }

        return ExecutionStatus::SUCCESS;
    }
}
