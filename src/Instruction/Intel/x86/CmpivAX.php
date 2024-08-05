<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CmpivAX implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x3C,
            0x3D,
        ];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $fetchResult = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX);

        $runtime->memoryAccessor()
            ->updateFlags(match ($opcode) {
                0x3C => $fetchResult->asByte() === $operand & 0b11111111,

                // TODO: You should implement 16bit and 32bit
                0x3D => $fetchResult->asByte() === $operand & 0b11111111_11111111,
            });

        return ExecutionStatus::SUCCESS;
    }
}
