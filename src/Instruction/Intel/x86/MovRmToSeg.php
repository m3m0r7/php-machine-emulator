<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRmToSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x8E]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $seg = $this->segmentFromDigit($modRegRM->registerOrOPCode());
        $value = $this->readRm16($runtime, $memory, $modRegRM);

        // In protected mode, cache the segment descriptor for Big Real Mode support
        if ($runtime->context()->cpu()->isProtectedMode() && $value !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $value);
            if ($descriptor !== null && $descriptor['present']) {
                $runtime->context()->cpu()->cacheSegmentDescriptor($seg, $descriptor);
                $runtime->option()->logger()->debug(sprintf(
                    'Cached segment descriptor for %s: base=0x%08X limit=0x%08X',
                    $seg->name,
                    $descriptor['base'],
                    $descriptor['limit']
                ));
            }
        }

        $runtime->memoryAccessor()->write16Bit($seg, $value);

        if ($seg === RegisterType::SS) {
            $esp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(
                $runtime->context()->cpu()->operandSize()
            );
        }

        return ExecutionStatus::SUCCESS;
    }

    private function segmentFromDigit(int $digit): RegisterType
    {
        return match ($digit & 0b111) {
            0b000 => RegisterType::ES,
            0b001 => RegisterType::CS,
            0b010 => RegisterType::SS,
            0b011 => RegisterType::DS,
            0b100 => RegisterType::FS,
            0b101 => RegisterType::GS,
            default => throw new ExecutionException('Invalid segment register encoding'),
        };
    }
}
