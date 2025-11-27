<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class RepPrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF3, 0xF2];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = $runtime->streamReader();
        $nextOpcode = $reader->byte();

        $isCmpsOrScas = in_array($nextOpcode, [0xA6, 0xAE, 0xA7, 0xAF], true);
        $isMovsOrStos = in_array($nextOpcode, [0xA4, 0xA5, 0xAA, 0xAB], true);

        // REP count uses CX/ECX depending on address-size.
        $counter = $this->readIndex($runtime, RegisterType::ECX);

        // In this simplified emulator, apply REP to a subset of string ops
        while ($counter > 0) {
            $instruction = $this->instructionList->getInstructionByOperationCode($nextOpcode);
            $result = $instruction->process($runtime, $nextOpcode);
            $counter--;
            $this->writeIndex($runtime, RegisterType::ECX, $counter);

            if ($result !== ExecutionStatus::SUCCESS) {
                return $result;
            }

            // REPNE/REPE termination for CMPS/SCAS based on ZF
            if ($isCmpsOrScas) {
                $zf = $runtime->memoryAccessor()->shouldZeroFlag();
                if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE
                    break;
                }
                if ($opcode === 0xF3 && !$zf) { // REP/REPE
                    break;
                }
            }

            // For MOVS/STOS, REP/REPNZ behave the same (ignore ZF)
            if ($isMovsOrStos) {
                continue;
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
