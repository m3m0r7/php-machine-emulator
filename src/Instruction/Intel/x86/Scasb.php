<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Scasb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xAE]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $value = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
        );
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();

        $calc = $al - $value;
        $result = $calc & 0xFF;
        // OF for CMP/SCAS: set if signs of operands differ and result sign equals subtrahend sign
        $signA = ($al >> 7) & 1;
        $signB = ($value >> 7) & 1;
        $signR = ($result >> 7) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $runtime->memoryAccessor()
            ->updateFlags($result, 8)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
