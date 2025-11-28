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
        $offset = $runtime->context()->cpu()->addressSize() === 32
            ? $enhancedStreamReader->dword()
            : $enhancedStreamReader->short();
        $opSize = $runtime->context()->cpu()->operandSize();
        $segment = $runtime->segmentOverride() ?? RegisterType::DS;
        $linearOffset = $this->segmentOffsetAddress($runtime, $segment, $offset);

        $ip = $runtime->streamReader()->offset();
        if ($ip >= 0x8300 && $ip <= 0x8313) {
            $runtime->option()->logger()->debug(sprintf(
                'MovMoffset: IP=0x%05X, opcode=0x%02X, offset=0x%08X, linear=0x%08X, opSize=%d',
                $ip, $opcode, $offset, $linearOffset, $opSize
            ));
        }

        switch ($opcode) {
            case 0xA0: // AL <- moffs8
            $value = $this->readMemory8($runtime, $linearOffset);
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);
            break;
        case 0xA1: // AX <- moffs16
            $phys = $this->translateLinear($runtime, $linearOffset);
            $value = $opSize === 32
                ? $this->readMemory32($runtime, $linearOffset)
                : $this->readMemory16($runtime, $linearOffset);
            if ($linearOffset === 0x1FF0) {
                $runtime->option()->logger()->debug(sprintf(
                    'MovMoffset READ 0x1FF0: linear=0x%08X, phys=0x%08X, value=0x%08X',
                    $linearOffset, $phys, $value
                ));
            }
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
            $valueToWrite = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);
            if ($linearOffset === 0x1FF0) {
                $runtime->option()->logger()->debug(sprintf(
                    'MovMoffset WRITE 0x1FF0: linear=0x%08X, value=0x%08X, opSize=%d',
                    $linearOffset, $valueToWrite, $opSize
                ));
            }
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
