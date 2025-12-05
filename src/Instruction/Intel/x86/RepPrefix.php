<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class RepPrefix implements InstructionInterface
{
    use Instructable;

    /**
     * String instructions that don't check ZF (MOVS, STOS, LODS, INS, OUTS).
     * These instructions continue iteration as long as ECX > 0.
     */
    private const NO_ZF_CHECK_INSTRUCTIONS = [
        Movsb::class,
        Movsw::class,
        Stosb::class,
        Stosw::class,
        Lodsb::class,
        Lodsw::class,
        Ins::class,
        Outs::class,
    ];

    /**
     * Instructions that should NOT be repeated even with REP prefix.
     * REP acts as a hint/padding for these instructions.
     * - REP RET (0xF3 0xC3): AMD branch prediction bug workaround
     * - REP NOP (0xF3 0x90): PAUSE instruction for spin-wait loops
     */
    private const NON_REPEATING_INSTRUCTIONS = [
        Ret::class,
        Nop::class,
    ];

    public function opcodes(): array
    {
        return [0xF3, 0xF2];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $iterationContext = $cpu->iteration();

        // Set up iteration handler: handles ECX decrement and loop control
        $iterationContext->setIterate(function (InstructionExecutorInterface $executor) use ($runtime, $opcode): ExecutionStatus {
            $lastResult = ExecutionStatus::SUCCESS;
            // This will be set to the string instruction's IP after first execute
            $stringInstructionIp = $executor->instructionPointer();
            $firstIteration = true;

            while (true) {
                // Check ECX before decrement
                $counter = $this->readIndex($runtime, RegisterType::ECX);
                if ($counter <= 0) {
                    break;
                }

                // Decrement ECX
                $counter--;
                $this->writeIndex($runtime, RegisterType::ECX, $counter);

                // Remember the IP before execute (this is where the string instruction starts)
                $ipBeforeExecute = $executor->instructionPointer();

                // Execute the instruction
                $result = $executor->execute();
                $lastResult = $result;

                // If result is CONTINUE (prefix), restore ECX and return to process more prefixes
                if ($result === ExecutionStatus::CONTINUE) {
                    $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                    return ExecutionStatus::CONTINUE;
                }

                $lastInstruction = $executor->lastInstruction();

                // Check if this is a non-repeating instruction (REP RET, REP NOP/PAUSE)
                // For these, REP acts as a hint/padding, not a repeat prefix
                // Restore ECX and return immediately after single execution
                if ($lastInstruction !== null) {
                    foreach (self::NON_REPEATING_INSTRUCTIONS as $nonRepClass) {
                        if ($lastInstruction instanceof $nonRepClass) {
                            // Restore ECX (REP doesn't decrement for these)
                            $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                            return $result;
                        }
                    }
                }

                // After first successful string instruction, save its start position
                if ($firstIteration) {
                    $stringInstructionIp = $ipBeforeExecute;
                    $firstIteration = false;
                }

                // If result is not SUCCESS, stop iteration
                if ($result !== ExecutionStatus::SUCCESS) {
                    return $result;
                }

                // Check if we should continue iterating
                if ($counter <= 0) {
                    break;
                }

                // For string instructions that don't check ZF, continue as long as ECX > 0
                $checksZF = true;
                if ($lastInstruction !== null) {
                    foreach (self::NO_ZF_CHECK_INSTRUCTIONS as $noZfClass) {
                        if ($lastInstruction instanceof $noZfClass) {
                            $checksZF = false;
                            break;
                        }
                    }
                }

                // REPNE/REPE termination for CMPS/SCAS based on ZF
                if ($checksZF) {
                    $zf = $runtime->memoryAccessor()->shouldZeroFlag();
                    if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE: stop when ZF=1
                        break;
                    }
                    if ($opcode === 0xF3 && !$zf) { // REPE/REPZ: stop when ZF=0
                        break;
                    }
                }

                // Reset IP to string instruction start for next iteration
                $executor->setInstructionPointer($stringInstructionIp);
            }

            return $lastResult;
        });

        return ExecutionStatus::CONTINUE;
    }
}
