<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Group0;
use PHPMachineEmulator\Instruction\RegisterType;

final class LtrLongMode64DescriptorTest extends InstructionTestCase
{
    private Group0 $group0;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->group0 = new Group0($instructionList);

        // IA-32e mode (compatibility) to enable 16-byte system descriptor parsing.
        $this->cpuContext->setCompatibilityMode(true);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(32);

        $gdtBase = 0x1000;
        $this->cpuContext->setGdtr($gdtBase, 0x100);

        // 64-bit available TSS descriptor (type=0x9, S=0, P=1), 16 bytes wide.
        // Base = 0x00000001_23456000, Limit = 0x0067.
        $tssBase = 0x0000000123456000;
        $baseLow = $tssBase & 0xFFFFFFFF;
        $baseHigh = ($tssBase >> 32) & 0xFFFFFFFF;
        $limit = 0x0067;

        $desc = [
            $limit & 0xFF,
            ($limit >> 8) & 0xFF,
            $baseLow & 0xFF,
            ($baseLow >> 8) & 0xFF,
            ($baseLow >> 16) & 0xFF,
            0x89, // present, system, available TSS
            0x00, // granularity/limit high
            ($baseLow >> 24) & 0xFF,
            $baseHigh & 0xFF,
            ($baseHigh >> 8) & 0xFF,
            ($baseHigh >> 16) & 0xFF,
            ($baseHigh >> 24) & 0xFF,
            0x00, 0x00, 0x00, 0x00,
        ];

        // Place at GDT index 3 (selector 0x18).
        $this->writeBytes($gdtBase + 0x18, $desc);

        $this->setRegister(RegisterType::EAX, 0x18, 16); // r/m16 selector
        $this->setCpl(0);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    public function testLtrReadsWideTssDescriptorBase(): void
    {
        // 0F 00 /3: LTR r/m16, modrm=11 011 000 => 0xD8 (use AX).
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x0F) . chr(0x00) . chr(0xD8));
        $this->memoryStream->setOffset(2);
        $this->group0->process($this->runtime, [0x0F, 0x00]);

        $tr = $this->cpuContext->taskRegister();
        $this->assertSame(0x0018, $tr['selector']);
        $this->assertSame(0x0000000123456000, $tr['base']);
        $this->assertSame(0x0067, $tr['limit']);

        // Busy bit should be set in the access byte in the GDT (0x89 -> 0x8B).
        $this->assertSame(0x8B, $this->readMemory(0x1000 + 0x18 + 5, 8));
    }

    /**
     * @param array<int,int> $bytes
     */
    private function writeBytes(int $address, array $bytes): void
    {
        foreach ($bytes as $i => $byte) {
            $this->writeMemory($address + $i, $byte & 0xFF, 8);
        }
    }
}
