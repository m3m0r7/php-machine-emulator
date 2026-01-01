<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\FaultException;

class Bound implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x62]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRM = $memory->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        // Get register value to check
        $regValue = $this->readRegisterBySize($runtime, $modRM->source(), $opSize);

        // Get memory address of bounds array
        $address = $this->rmLinearAddress($runtime, $memory, $modRM);

        // Read lower and upper bounds from memory
        if ($opSize === 32) {
            $lowerBound = $this->readMemory32($runtime, $address);
            $upperBound = $this->readMemory32($runtime, $address + 4);
            // Sign extend
            if ($lowerBound & 0x80000000) {
                $lowerBound = $lowerBound - 0x100000000;
            }
            if ($upperBound & 0x80000000) {
                $upperBound = $upperBound - 0x100000000;
            }
            if ($regValue & 0x80000000) {
                $regValue = $regValue - 0x100000000;
            }
        } else {
            $lowerBound = $this->readMemory16($runtime, $address);
            $upperBound = $this->readMemory16($runtime, $address + 2);
            // Sign extend
            if ($lowerBound & 0x8000) {
                $lowerBound = $lowerBound - 0x10000;
            }
            if ($upperBound & 0x8000) {
                $upperBound = $upperBound - 0x10000;
            }
            if ($regValue & 0x8000) {
                $regValue = $regValue - 0x10000;
            }
        }

        // Check bounds - raise #BR (Bound Range) exception if out of bounds
        if ($regValue < $lowerBound || $regValue > $upperBound) {
            throw new FaultException(0x05, 0, 'BOUND range exceeded');
        }

        return ExecutionStatus::SUCCESS;
    }
}
