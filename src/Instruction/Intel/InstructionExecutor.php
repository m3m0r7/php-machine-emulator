<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\NotFoundInstructionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class InstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;
    private int $zeroOpcodeCount = 0;

    public function execute(RuntimeInterface $runtime): ExecutionStatus
    {
        $ip = $runtime->memory()->offset();

        $maxOpcodeLength = $runtime->architectureProvider()->instructionList()->getMaxOpcodeLength();
        $memory = $runtime->memory();
        $memoryAccessor = $runtime->memoryAccessor();

        $this->lastInstructionPointer = $memory->offset();
        $memoryAccessor->setInstructionFetch(true);

        // Read up to maxOpcodeLength bytes for instruction matching
        $startPos = $memory->offset();
        $peekBytes = [];
        for ($i = 0; $i < $maxOpcodeLength && !$memory->isEOF(); $i++) {
            $peekBytes[] = $memory->byte();
        }

        // Try to find instruction from longest to shortest pattern
        $instruction = null;
        $lastException = null;
        $matchedLength = 0;
        for ($len = count($peekBytes); $len >= 1; $len--) {
            $tryBytes = array_slice($peekBytes, 0, $len);
            try {
                $instruction = $runtime->architectureProvider()->instructionList()->findInstruction($tryBytes);
                $matchedLength = $len;
                break;
            } catch (NotFoundInstructionException $e) {
                $lastException = $e;
                continue;
            }
        }

        if ($instruction === null && $lastException !== null) {
            throw $lastException;
        }

        // Update peekBytes to only the matched portion
        $peekBytes = array_slice($peekBytes, 0, $matchedLength);

        // Find instruction - uses longest-first matching
        // Returns InstructionInterface directly
        $this->lastOpcodes = $peekBytes;

        $memoryAccessor->setInstructionFetch(false);

        // Rewind to just after consumed bytes
        $memory->setOffset($startPos + count($peekBytes));

        // Detect infinite loop
        if (count($this->lastOpcodes) === 1 && $this->lastOpcodes === [0x00]) {
            $this->zeroOpcodeCount++;
            if ($this->zeroOpcodeCount >= 255) {
                throw new ExecutionException(sprintf(
                    'Infinite loop detected: 255 consecutive 0x00 opcodes at IP=0x%05X',
                    $this->lastInstructionPointer
                ));
            }
        } else {
            $this->zeroOpcodeCount = 0;
        }

        $this->logExecution($runtime, $ip, $this->lastOpcodes);

        // Execute the instruction
        $this->lastInstruction = $instruction;

        try {
            return $instruction->process($runtime, $this->lastOpcodes);
        } catch (FaultException $e) {
            $runtime->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
            if ($runtime->interruptDeliveryHandler()->raiseFault(
                $runtime,
                $e->vector(),
                $runtime->memory()->offset(),
                $e->errorCode()
            )) {
                return ExecutionStatus::SUCCESS;
            }
            throw $e;
        } catch (ExecutionException $e) {
            $runtime->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
            throw $e;
        }
    }

    private function logExecution(RuntimeInterface $runtime, int $ipBefore, array $opcodes): void
    {
        $memoryAccessor = $runtime->memoryAccessor();
        $cf = $memoryAccessor->shouldCarryFlag() ? 1 : 0;
        $zf = $memoryAccessor->shouldZeroFlag() ? 1 : 0;
        $sf = $memoryAccessor->shouldSignFlag() ? 1 : 0;
        $of = $memoryAccessor->shouldOverflowFlag() ? 1 : 0;
        $eax = $memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32);
        $ebx = $memoryAccessor->fetch(RegisterType::EBX)->asBytesBySize(32);
        $ecx = $memoryAccessor->fetch(RegisterType::ECX)->asBytesBySize(32);
        $edx = $memoryAccessor->fetch(RegisterType::EDX)->asBytesBySize(32);
        $esi = $memoryAccessor->fetch(RegisterType::ESI)->asBytesBySize(32);
        $edi = $memoryAccessor->fetch(RegisterType::EDI)->asBytesBySize(32);
        $ebp = $memoryAccessor->fetch(RegisterType::EBP)->asBytesBySize(32);
        $esp = $memoryAccessor->fetch(RegisterType::ESP)->asBytesBySize(32);
        $opcodeStr = implode(' ', array_map(fn($b) => sprintf('0x%02X', $b), $opcodes));
        $runtime->option()->logger()->debug(sprintf(
            'EXEC: IP=0x%05X op=%-12s FL[CF=%d ZF=%d SF=%d OF=%d] EAX=%08X EBX=%08X ECX=%08X EDX=%08X ESI=%08X EDI=%08X EBP=%08X ESP=%08X',
            $ipBefore, $opcodeStr, $cf, $zf, $sf, $of, $eax, $ebx, $ecx, $edx, $esi, $edi, $ebp, $esp
        ));
    }

    public function lastInstruction(): ?InstructionInterface
    {
        return $this->lastInstruction;
    }

    public function lastOpcodes(): ?array
    {
        return $this->lastOpcodes;
    }

    public function lastInstructionPointer(): int
    {
        return $this->lastInstructionPointer;
    }
}
