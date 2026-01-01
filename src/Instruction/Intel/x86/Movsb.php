<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xA4]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);

        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $value = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, $sourceSegment, $si),
        );

        $destLinear = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        // Linear framebuffer writes (e.g. VBE LFB at 0xE0000000) must go through MMIO handling.
        if ($destLinear >= 0xE0000000 && $destLinear < 0xE1000000) {
            $this->writeMemory8($runtime, $destLinear, $value);
        } else {
            $destAddress = $this->translateLinearWithMmio($runtime, $destLinear, true);
            $runtime->memoryAccessor()->allocate($destAddress, safe: false);
            $runtime->memoryAccessor()->writeRawByte($destAddress, $value);
        }

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
