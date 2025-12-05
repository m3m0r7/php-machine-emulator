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

            // Check ECX before starting - if zero, do nothing
            $counter = $this->readIndex($runtime, RegisterType::ECX);
            if ($counter <= 0) {
                return ExecutionStatus::SUCCESS;
            }

            // Execute first iteration to identify the instruction
            $counter--;
            $this->writeIndex($runtime, RegisterType::ECX, $counter);
            $ipBeforeExecute = $executor->instructionPointer();
            $result = $executor->execute();
            $lastResult = $result;

            // If result is CONTINUE (prefix), restore ECX and return to process more prefixes
            if ($result === ExecutionStatus::CONTINUE) {
                $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                return ExecutionStatus::CONTINUE;
            }

            $lastInstruction = $executor->lastInstruction();

            // Check if this is a non-repeating instruction (REP RET, REP NOP/PAUSE)
            if ($lastInstruction !== null) {
                foreach (self::NON_REPEATING_INSTRUCTIONS as $nonRepClass) {
                    if ($lastInstruction instanceof $nonRepClass) {
                        $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                        return $result;
                    }
                }
            }

            if ($result !== ExecutionStatus::SUCCESS) {
                return $result;
            }

            $stringInstructionIp = $ipBeforeExecute;

            // Check if this is a bulk-optimizable instruction (STOS, MOVS without ZF check)
            $isBulkOptimizable = false;
            if ($lastInstruction !== null) {
                foreach (self::NO_ZF_CHECK_INSTRUCTIONS as $noZfClass) {
                    if ($lastInstruction instanceof $noZfClass) {
                        $isBulkOptimizable = true;
                        break;
                    }
                }
            }

            // Bulk optimization for STOS/MOVS: execute remaining iterations without per-iteration logging
            if ($isBulkOptimizable && $counter > 0) {
                $runtime->option()->logger()->debug(sprintf(
                    'REP %s: bulk executing %d remaining iterations',
                    $lastInstruction !== null ? (new \ReflectionClass($lastInstruction))->getShortName() : 'unknown',
                    $counter
                ));

                while ($counter > 0) {
                    $counter--;
                    $this->writeIndex($runtime, RegisterType::ECX, $counter);

                    // Execute without going through the full executor (which logs)
                    $result = $lastInstruction->process($runtime, $executor->lastOpcode());
                    $lastResult = $result;

                    if ($result !== ExecutionStatus::SUCCESS) {
                        return $result;
                    }
                }

                return $lastResult;
            }

            // Standard loop for instructions that need ZF checking (CMPS, SCAS)
            while ($counter > 0) {
                $counter--;
                $this->writeIndex($runtime, RegisterType::ECX, $counter);

                // Reset IP to string instruction start for next iteration
                $executor->setInstructionPointer($stringInstructionIp);

                // Execute the instruction
                $result = $executor->execute();
                $lastResult = $result;

                if ($result !== ExecutionStatus::SUCCESS) {
                    return $result;
                }

                if ($counter <= 0) {
                    break;
                }

                // REPNE/REPE termination for CMPS/SCAS based on ZF
                $zf = $runtime->memoryAccessor()->shouldZeroFlag();
                if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE: stop when ZF=1
                    break;
                }
                if ($opcode === 0xF3 && !$zf) { // REPE/REPZ: stop when ZF=0
                    break;
                }
            }

            return $lastResult;
        });

        return ExecutionStatus::CONTINUE;
    }
}
