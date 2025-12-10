<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Stosw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xAB]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opSize = $runtime->context()->cpu()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $value = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->translateLinearWithMmio($runtime, $this->segmentOffsetAddress($runtime, RegisterType::ES, $di), true);

        $runtime->memoryAccessor()->allocate($address, safe: false);
        $runtime->memoryAccessor()->writeBySize($address, $value, $opSize);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
