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

        $normalizedOperand = $opcode === 0x3D ? ($operand & 0xFFFF) : $operand;
        $newCF = $leftHand < $normalizedOperand;

        // Debug FAT chain reading
        if ($opcode === 0x3D && $normalizedOperand === 0x0FF8) {
            $runtime->option()->logger()->debug(sprintf(
                'CMP AX, 0xFF8: AX=0x%04X < 0xFF8 ? CF=%s',
                $leftHand,
                $newCF ? 'true' : 'false'
            ));
        }

        $runtime
            ->memoryAccessor()
            ->updateFlags($leftHand - $normalizedOperand, $opcode === 0x3D ? 16 : 8)
            ->setCarryFlag($newCF);


        return ExecutionStatus::SUCCESS;
    }
}
