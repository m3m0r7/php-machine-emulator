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
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $value = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $linear = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        // Linear framebuffer writes (e.g. VBE LFB at 0xE0000000) must go through MMIO handling.
        if ($linear >= 0xE0000000 && $linear < 0xE1000000) {
            match ($opSize) {
                16 => $this->writeMemory16($runtime, $linear, $value),
                32 => $this->writeMemory32($runtime, $linear, $value),
                64 => $this->writeMemory64($runtime, $linear, $value),
                default => $this->writeMemory16($runtime, $linear, $value),
            };
        } else {
            match ($opSize) {
                16 => $this->writeMemory16($runtime, $linear, $value),
                32 => $this->writeMemory32($runtime, $linear, $value),
                64 => $this->writeMemory64($runtime, $linear, $value),
                default => $this->writeMemory16($runtime, $linear, $value),
            };
        }

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
