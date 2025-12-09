<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\NotFoundInstructionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandlerInterface;

class InstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;
    private int $zeroOpcodeCount = 0;

    public function __construct(
        private readonly RuntimeInterface $runtime,
        private readonly InstructionListInterface $instructionList,
        private readonly InterruptDeliveryHandlerInterface $interruptDeliveryHandler,
    ) {
    }

    public function execute(): ExecutionStatus
    {
        $ip = $this->runtime->memory()->offset();
        $maxOpcodeLength = $this->instructionList->getMaxOpcodeLength();
        $memory = $this->runtime->memory();
        $memoryAccessor = $this->runtime->memoryAccessor();

        $this->lastInstructionPointer = $memory->offset();
        $memoryAccessor->setInstructionFetch(true);

        // Read up to maxOpcodeLength bytes for instruction matching
        $startPos = $memory->offset();
        $peekBytes = [];
        $instruction = null;
        $lastException = null;
        for ($i = 0; $i < $maxOpcodeLength && !$memory->isEOF(); $i++) {
            $byte = $memory->byte();
            try {
                $instruction = $this->instructionList->findInstruction([...$peekBytes, $byte]);
                $peekBytes[] = $byte;
            } catch (NotFoundInstructionException $e) {
                $lastException = $e;
                break;
            }
        }

        if ($instruction === null && $lastException !== null) {
            throw $lastException;
        }

        // Find instruction - uses longest-first matching
        // Returns InstructionInterface directly
        $this->lastOpcodes = $peekBytes;

        $memoryAccessor->setInstructionFetch(false);

        // Rewind to just after consumed bytes
        $memory->setOffset($startPos + count($peekBytes));

        // Detect infinite loop
        if (count($this->lastOpcodes) === 1 && $this->lastOpcodes[0] === 0x00) {
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

        $this->logExecution($ip, $this->lastOpcodes);

        // Execute the instruction
        $this->lastInstruction = $instruction;

        try {
            return $instruction->process($this->runtime, $this->lastOpcodes);
        } catch (FaultException $e) {
            $this->runtime->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
            if ($this->interruptDeliveryHandler->raiseFault(
                $this->runtime,
                $e->vector(),
                $this->runtime->memory()->offset(),
                $e->errorCode()
            )) {
                return ExecutionStatus::SUCCESS;
            }
            throw $e;
        } catch (ExecutionException $e) {
            $this->runtime->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
            throw $e;
        }
    }

    private function logExecution(int $ipBefore, array $opcodes): void
    {
        $memoryAccessor = $this->runtime->memoryAccessor();
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
        $this->runtime->option()->logger()->debug(sprintf(
            'EXEC: IP=0x%05X op=%-12s FL[CF=%d ZF=%d SF=%d OF=%d] EAX=%08X EBX=%08X ECX=%08X EDX=%08X ESI=%08X EDI=%08X EBP=%08X ESP=%08X',
            $ipBefore, $opcodeStr, $cf, $zf, $sf, $of, $eax, $ebx, $ecx, $edx, $esi, $edi, $ebp, $esp
        ));
    }

    public function lastInstruction(): ?InstructionInterface
    {
        return $this->lastInstruction;
    }

    public function lastOpcode(): ?int
    {
        return $this->lastOpcode;
    }

    public function lastInstructionPointer(): int
    {
        return $this->lastInstructionPointer;
    }

    public function setInstructionPointer(int $ip): void
    {
        $this->runtime->memory()->setOffset($ip);
    }

    public function instructionPointer(): int
    {
        return $this->runtime->memory()->offset();
    }
}
