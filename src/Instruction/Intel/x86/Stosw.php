<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Stosw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAB];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $value = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->translateLinear($runtime, $this->segmentOffsetAddress($runtime, RegisterType::ES, $di), true);

        // Debug: trace STOSD writes to detect stack corruption
        if ($address >= 0x7B00 && $address <= 0x7C00) {
            $es = $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte();
            $runtime->option()->logger()->debug(sprintf(
                'STOSD: writing to stack area! address=0x%08X ES=0x%04X EDI=0x%08X value=0x%08X opSize=%d',
                $address, $es, $di, $value, $opSize
            ));
        }

        $runtime->memoryAccessor()->allocate($address, safe: false);
        $runtime->memoryAccessor()->writeBySize($address, $value, $opSize);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
