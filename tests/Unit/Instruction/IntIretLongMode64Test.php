<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Iret;
use PHPMachineEmulator\Instruction\RegisterType;

final class IntIretLongMode64Test extends InstructionTestCase
{
    private Int_ $int;
    private Iret $iret;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);
        $this->iret = new Iret($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xCD => $this->int,
            0xCF => $this->iret,
            default => null,
        };
    }

    private function writeGdtLongModeCodeDescriptor(int $gdtBase, int $selector): void
    {
        // Code segment descriptor (index=selector>>3) with L=1, D=0, base=0, limit=4GB.
        // bytes: limit=0xFFFF, base=0x00000000, access=0x9A, gran=0xAF (G=1,L=1,limitHi=0xF)
        $codeDesc = [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x9A, 0xAF, 0x00];

        // Null descriptor at index 0
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory($gdtBase + $i, 0x00);
        }

        $index = ($selector >> 3) & 0x1FFF;
        $descAddr = $gdtBase + ($index * 8);
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory($descAddr + $i, $codeDesc[$i]);
        }

        $this->cpuContext->setGdtr($gdtBase, ($index * 8) + 7);
    }

    private function writeIdtGate64(int $idtBase, int $vector, int $handlerRip, int $selector, int $typeAttr = 0x8E, int $ist = 0): void
    {
        $entry = $idtBase + ($vector * 16);

        $offsetLow = $handlerRip & 0xFFFF;
        $offsetMid = ($handlerRip >> 16) & 0xFFFF;
        $offsetHigh = ($handlerRip >> 32) & 0xFFFFFFFF;

        $this->writeMemory($entry + 0, $offsetLow, 16);
        $this->writeMemory($entry + 2, $selector & 0xFFFF, 16);
        $this->writeMemory($entry + 4, $ist & 0x7, 8);
        $this->writeMemory($entry + 5, $typeAttr & 0xFF, 8);
        $this->writeMemory($entry + 6, $offsetMid, 16);
        $this->writeMemory($entry + 8, $offsetHigh, 32);
        $this->writeMemory($entry + 12, 0x00000000, 32);

        $this->cpuContext->setIdtr($idtBase, ($vector * 16) + 15);
    }

    public function testIntThenIretqUses64BitFrameAndRestoresFlags(): void
    {
        $vector = 0x80;
        $codeSelector = 0x0008;
        $gdtBase = 0x0500;
        $idtBase = 0x0600;
        $handlerRip = 0x2000;

        $this->writeGdtLongModeCodeDescriptor($gdtBase, $codeSelector);
        $this->writeIdtGate64($idtBase, $vector, $handlerRip, $codeSelector);

        $this->setRegister(RegisterType::CS, $codeSelector, 16);
        $this->setRegister(RegisterType::SS, 0x0000, 16);

        $rsp = 0x9000;
        $this->setRegister(RegisterType::ESP, $rsp, 64);

        // Flags before INT: CF=1, ZF=1, IF=1.
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->setInterruptFlag(true);

        // Execute INT 0x80.
        $this->executeBytes([0xCD, $vector]);

        // Interrupt gate should clear IF after pushing the frame.
        $this->assertFalse($this->getInterruptFlag());
        $this->assertSame($handlerRip, $this->memoryStream->offset());

        // Verify 64-bit interrupt stack frame: RIP, CS, RFLAGS, RSP, SS (5 qwords).
        $newRsp = $rsp - 40;
        $this->assertSame($newRsp, $this->getRegister(RegisterType::ESP, 64));

        $pushedRip = $this->readMemory($newRsp, 64);
        $pushedCs = $this->readMemory($newRsp + 8, 64);
        $pushedFlags = $this->readMemory($newRsp + 16, 64);
        $pushedRsp = $this->readMemory($newRsp + 24, 64);
        $pushedSs = $this->readMemory($newRsp + 32, 64);

        $this->assertSame(2, $pushedRip, 'Return RIP should be next instruction (2 bytes)');
        $this->assertSame($codeSelector, $pushedCs & 0xFFFF, 'Pushed CS selector should match');
        $this->assertSame(0x243, $pushedFlags & 0xFFFF, 'Pushed RFLAGS should include CF/ZF/IF and reserved bit');
        $this->assertSame($rsp, $pushedRsp, 'Pushed RSP should match current stack pointer');
        $this->assertSame(0, $pushedSs & 0xFFFF, 'Pushed SS should match');

        // Execute IRETQ (0xCF in 64-bit mode) and verify restoration.
        $this->executeBytes([0xCF]);

        $this->assertSame(2, $this->memoryStream->offset());
        $this->assertSame($codeSelector, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame($rsp, $this->getRegister(RegisterType::ESP, 64));

        $this->assertTrue($this->getInterruptFlag());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }
}
