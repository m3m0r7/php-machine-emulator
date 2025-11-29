<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\DOSInterrupt;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Dos implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return []; // invoked via INT dispatcher, not by opcode table
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $ax->asHighBit();
        $al = $ax->asLowBit();

        switch ($ah) {
            case 0x02: // display output character in DL
                $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
                $runtime->option()->IO()->output()->write(chr($dl));
                break;
            case 0x09: // display string at DS:DX terminated by '$'
                $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte();
                $addr = $this->segmentOffsetAddress($runtime, RegisterType::DS, $dx);
                $buf = '';
                while (true) {
                    $ch = $this->readMemory8($runtime, $addr++);
                    if ($ch === 0x24) { // '$'
                        break;
                    }
                    $buf .= chr($ch);
                }
                $runtime->option()->IO()->output()->write($buf);
                break;
            case 0x01: // input character with echo
                $byte = $runtime->option()->IO()->input()->byte();
                $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $byte);
                $runtime->option()->IO()->output()->write(chr($byte));
                break;
            case 0x4C: // terminate process with return code AL
                $runtime->frame()->append(new \PHPMachineEmulator\Frame\FrameSet($runtime, $this, $runtime->memory()->offset(), value: $al));
                return ExecutionStatus::EXIT;
            default:
                // Unimplemented DOS function; no-op but clear carry to indicate "success"
                $runtime->memoryAccessor()->setCarryFlag(false);
        }

        return ExecutionStatus::SUCCESS;
    }
}
