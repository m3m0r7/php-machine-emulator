<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Group5;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovToCr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Wrmsr;
use PHPMachineEmulator\Instruction\RegisterType;

final class LongModeEntryActivationTest extends InstructionTestCase
{
    private MovToCr $movToCr;
    private Wrmsr $wrmsr;
    private Group5 $group5;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->movToCr = new MovToCr($instructionList);
        $this->wrmsr = new Wrmsr($instructionList);
        $this->group5 = new Group5($instructionList);

        // Minimal flat GDT:
        // 0x00: null
        // 0x08: 32-bit code (L=0, D=1)
        // 0x10: 64-bit code (L=1, D=0)
        $gdtBase = 0x1000;
        $this->cpuContext->setGdtr($gdtBase, 0x30);

        $this->writeBytes($gdtBase + 0x00, array_fill(0, 8, 0x00));
        $this->writeBytes($gdtBase + 0x08, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x9A, 0xCF, 0x00]);
        $this->writeBytes($gdtBase + 0x10, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x9A, 0xAF, 0x00]);

        $this->setRegister(RegisterType::CS, 0x08, 16);
        $this->setRegister(RegisterType::SS, 0x00, 16);
        $this->setCpl(0);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xFF => $this->group5,
            default => null,
        };
    }

    public function testIa32eBecomesActiveOnEferLmePaeAndPagingAndStartsInCompatibilityMode(): void
    {
        // CR4.PAE=1
        $this->setRegister(RegisterType::EAX, 0x20, 32);
        $this->executeMovToCrWithModrm(0xE0); // MOV CR4, EAX

        // EFER.LME=1 (via WRMSR)
        $this->setRegister(RegisterType::ECX, 0xC0000080, 32);
        $this->setRegister(RegisterType::EAX, 1 << 8, 32);
        $this->setRegister(RegisterType::EDX, 0, 32);
        $this->wrmsr->process($this->runtime, [0x0F, 0x30]);

        // CR0.PE=1, CR0.PG=1
        $this->setRegister(RegisterType::EAX, 0x80000001, 32);
        $this->executeMovToCrWithModrm(0xC0); // MOV CR0, EAX

        $this->assertTrue($this->cpuContext->isLongMode());
        $this->assertTrue($this->cpuContext->isCompatibilityMode());

        $efer = $this->runtime->memoryAccessor()->readEfer();
        $this->assertSame(1, ($efer >> 10) & 1, 'EFER.LMA should be set when IA-32e is active');
    }

    public function testFarJumpTo64bitCodeSegmentEnters64bitMode(): void
    {
        // Activate IA-32e first (stays in compatibility mode with CS=0x08).
        $this->setRegister(RegisterType::EAX, 0x20, 32);
        $this->executeMovToCrWithModrm(0xE0); // MOV CR4, EAX

        $this->setRegister(RegisterType::ECX, 0xC0000080, 32);
        $this->setRegister(RegisterType::EAX, 1 << 8, 32);
        $this->setRegister(RegisterType::EDX, 0, 32);
        $this->wrmsr->process($this->runtime, [0x0F, 0x30]);

        $this->setRegister(RegisterType::EAX, 0x80000001, 32);
        $this->executeMovToCrWithModrm(0xC0); // MOV CR0, EAX

        $this->assertTrue($this->cpuContext->isLongMode());
        $this->assertTrue($this->cpuContext->isCompatibilityMode());

        // Far pointer at absolute address 0x2000: offset32=0x1234, selector=0x10 (64-bit CS).
        $this->writeMemory(0x2000, 0x00001234, 32);
        $this->writeMemory(0x2004, 0x0010, 16);

        // JMP FAR m16:32 via ModR/M disp32 (FF /5, modrm=00 101 101 => 0x2D)
        $this->executeBytes([0xFF, 0x2D, 0x00, 0x20, 0x00, 0x00]);

        $this->assertTrue($this->cpuContext->isLongMode());
        $this->assertFalse($this->cpuContext->isCompatibilityMode());
        $this->assertSame(64, $this->cpuContext->defaultAddressSize());
        $this->assertSame(0x1234, $this->memoryStream->offset());
    }

    private function executeMovToCrWithModrm(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x0F) . chr(0x22) . chr($modrm));
        $this->memoryStream->setOffset(2); // decoder consumed 0F 22
        $this->movToCr->process($this->runtime, [0x0F, 0x22]);
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
