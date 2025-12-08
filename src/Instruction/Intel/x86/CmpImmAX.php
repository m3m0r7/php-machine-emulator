<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * CMP AL/AX/EAX, imm8/imm16/imm32
 *
 * 0x3C: CMP AL, imm8
 * 0x3D: CMP AX, imm16 (or CMP EAX, imm32 with 0x66 prefix in 16-bit mode)
 */
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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $cpu = $runtime->context()->cpu();

        if ($opcode === 0x3C) {
            // CMP AL, imm8
            $operand = $enhancedStreamReader->streamReader()->byte();
            $leftHand = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $bitSize = 8;
            $mask = 0xFF;
        } else {
            // 0x3D: CMP AX/EAX, imm16/imm32
            $use32 = $cpu->shouldUse32bit();
            if ($use32) {
                $operand = $enhancedStreamReader->dword();
                $leftHand = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32);
                $bitSize = 32;
                $mask = 0xFFFFFFFF;
            } else {
                $operand = $enhancedStreamReader->short();
                $leftHand = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(16);
                $bitSize = 16;
                $mask = 0xFFFF;
            }
        }

        $normalizedOperand = $operand & $mask;
        $newCF = $leftHand < $normalizedOperand;

        $runtime
            ->memoryAccessor()
            ->updateFlags($leftHand - $normalizedOperand, $bitSize)
            ->setCarryFlag($newCF);

        return ExecutionStatus::SUCCESS;
    }
}
