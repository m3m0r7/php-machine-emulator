<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovMoffset implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xA0, 0xA1, 0xA2, 0xA3]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $addrSize = $runtime->context()->cpu()->addressSize();
        if ($addrSize === 64) {
            $offset = $memory->qword();
        } elseif ($addrSize === 32) {
            $offset = $memory->dword();
        } else {
            $offset = $memory->short();
        }
        $opSize = $runtime->context()->cpu()->operandSize();
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;
        $linearOffset = $this->segmentOffsetAddress($runtime, $segment, $offset);

        $ip = $runtime->memory()->offset();

        switch ($opcode) {
            case 0xA0: // AL <- moffs8
                $value = $this->readMemory8($runtime, $linearOffset);
                $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);
                break;
            case 0xA1: // AX <- moffs16
                $value = $opSize === 32
                ? $this->readMemory32($runtime, $linearOffset)
                : $this->readMemory16($runtime, $linearOffset);
                $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $value, $opSize);
                break;
            case 0xA2: // moffs8 <- AL
                $value8 = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
                $this->writeMemory8($runtime, $linearOffset, $value8);
                break;
            case 0xA3: // moffs16 <- AX
                $valueToWrite = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);
                // Write using appropriate size
                if ($opSize === 32) {
                    $this->writeMemory32($runtime, $linearOffset, $valueToWrite);
                } else {
                    $this->writeMemory16($runtime, $linearOffset, $valueToWrite);
                }
                break;
        }

        return ExecutionStatus::SUCCESS;
    }
}
