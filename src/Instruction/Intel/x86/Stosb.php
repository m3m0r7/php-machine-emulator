<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Stosb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $byte = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asLowBit();

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->translateLinear($runtime, $this->segmentOffsetAddress($runtime, RegisterType::ES, $di), true);


        $runtime
            ->memoryAccessor()
            ->allocate($address, safe: false);

        $runtime
            ->memoryAccessor()
            ->writeRawByte($address, $byte);

        // Debug: log stosb operations
        $runtime->option()->logger()->debug(sprintf(
            'STOSB: ES:DI=0x%04X:0x%04X linear=0x%05X value=0x%02X (char=%s)',
            $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte(),
            $di,
            $address,
            $byte,
            $byte >= 0x20 && $byte < 0x7F ? chr($byte) : '.'
        ));

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
