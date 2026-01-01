<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\JccNear;

class JccNearLongModeTest extends TwoByteOpTestCase
{
    private JccNear $jccNear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jccNear = new JccNear($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->jccNear;
    }

    public function testJzNearUsesRel32InLongModeEvenWithOperandOverride(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setOperandSizeOverride(true); // simulate 0x66 prefix
        $this->setZeroFlag(true);

        // rel32 = +16
        $this->executeJcc(0x84, [0x10, 0x00, 0x00, 0x00]);

        // Offset after reading disp32 is 4; 4 + 16 = 20.
        $this->assertSame(20, $this->memoryStream->offset());
    }

    public function testJzNearNegativeRel32InLongMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->setZeroFlag(true);

        // rel32 = -4 (0xFFFFFFFC)
        $this->executeJcc(0x84, [0xFC, 0xFF, 0xFF, 0xFF]);

        $this->assertSame(0, $this->memoryStream->offset());
    }

    public function testJzNearNotTakenLeavesOffsetAtEndOfDisp(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->setZeroFlag(false);

        $this->executeJcc(0x84, [0x10, 0x00, 0x00, 0x00]);

        // Not taken: offset should just advance past disp32.
        $this->assertSame(4, $this->memoryStream->offset());
    }

    private function executeJcc(int $secondByte, array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | $secondByte;
        $this->jccNear->process($this->runtime, [$opcodeKey]);
    }
}
