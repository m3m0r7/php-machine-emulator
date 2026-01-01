<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Movsb;
use PHPMachineEmulator\Instruction\Intel\x86\Movsw;
use PHPMachineEmulator\Instruction\Intel\x86\Scasb;
use PHPMachineEmulator\Instruction\Intel\x86\Scasw;
use PHPMachineEmulator\Instruction\Intel\x86\Stosb;
use PHPMachineEmulator\Instruction\Intel\x86\Stosw;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Test implementation of InstructionExecutorInterface for unit tests.
 */
class TestInstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;

    public function __construct(
        private readonly \PHPMachineEmulator\Runtime\RuntimeInterface $runtime,
        private readonly \PHPMachineEmulator\Stream\MemoryStreamInterface $memoryStream,
        private readonly \Tests\Utils\TestCPUContext $cpuContext,
        private readonly Stosb $stosb,
        private readonly Stosw $stosw,
        private readonly Movsb $movsb,
        private readonly Movsw $movsw,
        private readonly Scasb $scasb,
        private readonly Scasw $scasw,
    ) {
    }

    public function execute(RuntimeInterface $runtime): ExecutionStatus
    {
        $this->lastInstructionPointer = $this->memoryStream->offset();

        // Read and handle prefixes, then execute string instruction
        $instruction = null;
        $opcode = null;

        while ($instruction === null) {
            // Read next byte from stream
            $nextByte = $this->memoryStream->byte();

            // Handle prefixes
            if ($nextByte === 0x66) {
                $this->cpuContext->setOperandSizeOverride(true);
                continue;
            }
            if ($nextByte === 0x67) {
                $this->cpuContext->setAddressSizeOverride(true);
                continue;
            }
            if ($this->cpuContext->isLongMode() && !$this->cpuContext->isCompatibilityMode() && $nextByte >= 0x40 && $nextByte <= 0x4F) {
                // REX prefix (0x40-0x4F) in 64-bit mode
                $this->cpuContext->setRex($nextByte & 0x0F);
                continue;
            }

            // Find the string instruction
            $opcode = $nextByte;
            $instruction = match ($nextByte) {
                0xAA => $this->stosb,
                0xAB => $this->stosw,
                0xA4 => $this->movsb,
                0xA5 => $this->movsw,
                0xAE => $this->scasb,
                0xAF => $this->scasw,
                default => throw new \RuntimeException("Unknown opcode: 0x" . sprintf("%02X", $nextByte)),
            };
        }

        $this->lastInstruction = $instruction;
        $this->lastOpcodes = [$opcode];

        $result = $instruction->process($this->runtime, [$opcode]);

        // Reset stream for next iteration
        $this->memoryStream->setOffset(0);

        return $result;
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

    public function invalidateCaches(): void
    {
        // No caching in test executor
    }

    public function invalidateCachesIfExecutedPageOverlaps(int $start, int $length): void
    {
        // No caching in test executor
    }

    public function instructionCount(): int
    {
        return 0;
    }

    public function getIpSampleReport(int $top = 20): array
    {
        return [
            'every' => 0,
            'instructions' => 0,
            'samples' => 0,
            'unique' => 0,
            'top' => [],
        ];
    }
}
