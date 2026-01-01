<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
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
        return $this->applyPrefixes([0xAA]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $byte = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asLowBit();

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $linear = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        // Linear framebuffer writes (e.g. VBE LFB at 0xE0000000) must go through MMIO handling.
        if ($linear >= 0xE0000000 && $linear < 0xE1000000) {
            $this->writeMemory8($runtime, $linear, $byte);
        } else {
            $address = $this->translateLinearWithMmio($runtime, $linear, true);

            $runtime
                ->memoryAccessor()
                ->allocate($address, safe: false);

            $runtime
                ->memoryAccessor()
                ->writeRawByte($address, $byte);
        }

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
