<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
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
        $reader = $runtime->memory();
        $cpu = $runtime->context()->cpu();

        // Handle prefix bytes that may appear between REP and the string instruction
        // 66 = Operand size override, 67 = Address size override
        // Prefixes can appear in any order, so loop until we find a non-prefix opcode
        $nextOpcode = $reader->byte();

        while ($nextOpcode === 0x66 || $nextOpcode === 0x67) {
            if ($nextOpcode === 0x66) {
                $cpu->setOperandSizeOverride(true);
            } elseif ($nextOpcode === 0x67) {
                $cpu->setAddressSizeOverride(true);
            }
            $nextOpcode = $reader->byte();
        }

        // Handle two-byte opcodes (0x0F prefix)
        $opcodes = [$nextOpcode];
        if ($nextOpcode === 0x0F) {
            $secondByte = $reader->byte();
            $opcodes = [$nextOpcode, $secondByte];
        }

        // String instructions that don't check ZF (MOVS, STOS, LODS, INS, OUTS)
        $noZfCheck = in_array($nextOpcode, [0xA4, 0xA5, 0xAA, 0xAB, 0xAC, 0xAD, 0x6C, 0x6D, 0x6E, 0x6F], true);

        // REP count uses CX/ECX depending on address-size
        $counter = $this->readIndex($runtime, RegisterType::ECX);

        // Debug: log REP STOSD
        $edi = $this->readIndex($runtime, RegisterType::EDI);
        if ($nextOpcode === 0xAB) {
            $eax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'REP STOSD start: ECX=%d (0x%08X) EDI=0x%08X EAX=0x%08X endAddr=0x%08X',
                $counter, $counter, $edi, $eax, $edi + ($counter * 4)
            ));
        }

        if ($counter === 0) {
            return ExecutionStatus::SUCCESS;
        }

        [$instruction, $opcodeKey] = $this->instructionList->findInstruction($opcodes);

        while ($counter > 0) {
            $result = $instruction->process($runtime, $opcodeKey);
            $counter--;
            $this->writeIndex($runtime, RegisterType::ECX, $counter);

            if ($result !== ExecutionStatus::SUCCESS) {
                return $result;
            }

            // REPNE/REPE termination for CMPS/SCAS based on ZF
            if ($noZfCheck) {
                continue;
            }

            $zf = $runtime->memoryAccessor()->shouldZeroFlag();
            if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE: stop when ZF=1
                break;
            }
            if ($opcode === 0xF3 && !$zf) { // REPE/REPZ: stop when ZF=0
                break;
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
