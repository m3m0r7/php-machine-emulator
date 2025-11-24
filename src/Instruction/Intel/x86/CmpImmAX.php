<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CmpImmAX implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x3C,
            0x3D,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $operand = $opcode === 0x3D
            ? $enhancedStreamReader->short()
            : $enhancedStreamReader->streamReader()->byte();

        $fetchResult = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX);

        $leftHand = match ($opcode) {
            0x3C => $fetchResult->asLowBit(),
            0x3D => $fetchResult->asByte() & 0b11111111_11111111,
        };

        $runtime
            ->memoryAccessor()
            ->updateFlags($leftHand - ($opcode === 0x3D ? ($operand & 0b11111111_11111111) : $operand), $opcode === 0x3D ? 16 : 8)
            ->setCarryFlag($leftHand < ($opcode === 0x3D ? ($operand & 0b11111111_11111111) : $operand));


        return ExecutionStatus::SUCCESS;
    }
}
