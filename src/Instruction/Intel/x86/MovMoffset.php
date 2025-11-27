<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovMoffset implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA0, 0xA1, 0xA2, 0xA3];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $offset = $runtime->runtimeOption()->context()->addressSize() === 32
            ? $enhancedStreamReader->dword()
            : $enhancedStreamReader->short();
        $opSize = $runtime->runtimeOption()->context()->operandSize();
        $segment = $runtime->segmentOverride() ?? RegisterType::DS;
        $linearOffset = $this->segmentOffsetAddress($runtime, $segment, $offset);

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
            $phys = $this->translateLinear($runtime, $linearOffset);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize(
                $phys,
                $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit(),
                8,
            );
            break;
        case 0xA3: // moffs16 <- AX
            $phys = $this->translateLinear($runtime, $linearOffset);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize(
                $phys,
                $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize),
                $opSize,
            );
            break;
        }

        return ExecutionStatus::SUCCESS;
    }
}
