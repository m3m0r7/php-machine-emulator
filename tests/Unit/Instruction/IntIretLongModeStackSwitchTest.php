<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Iret;
use PHPMachineEmulator\Instruction\RegisterType;

final class IntIretLongModeStackSwitchTest extends InstructionTestCase
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

    public function testIntFromUserSwitchesToTssRsp0AndIretqRestoresUserContext(): void
    {
        $vector = 0x80;
        $gdtBase = 0x1000;
        $idtBase = 0x2000;
        $tssBase = 0x3000;
        $handlerRip = 0x4000;

        $kernelCs = 0x0008;
        $userCs = 0x0018 | 0x3; // RPL=3
        $userSs = 0x0020 | 0x3;

        $this->writeGdtLongModeCodeDescriptor($gdtBase, $kernelCs, dpl: 0);
        $this->writeGdtLongModeCodeDescriptor($gdtBase, $userCs, dpl: 3);
        $this->cpuContext->setGdtr($gdtBase, 0xFF);

        // Allow user-mode INT by setting gate DPL=3 (0xEE: P=1,DPL=3,type=0xE).
        $this->writeIdtGate64($idtBase, $vector, $handlerRip, $kernelCs, typeAttr: 0xEE);
        $this->cpuContext->setIdtr($idtBase, ($vector * 16) + 15);

        $rsp0 = 0x9000;
        $this->cpuContext->setTaskRegister(0x0028, $tssBase, 0x0067);
        $this->writeMemory($tssBase + 0x04, $rsp0, 64); // RSP0

        $userRsp = 0x8000;
        $this->setRegister(RegisterType::CS, $userCs, 16);
        $this->setRegister(RegisterType::SS, $userSs, 16);
        $this->setRegister(RegisterType::ESP, $userRsp, 64);
        $this->setCpl(3);
        $this->cpuContext->setUserMode(true);

        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->setInterruptFlag(true);

        $this->executeBytes([0xCD, $vector]);

        $this->assertSame(0, $this->cpuContext->cpl());
        $this->assertSame($kernelCs, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame(0, $this->getRegister(RegisterType::SS, 16) & 0xFFFF, 'SS should be NULL selector on stack switch');
        $this->assertFalse($this->getInterruptFlag(), 'Interrupt gate clears IF');
        $this->assertSame($handlerRip, $this->memoryStream->offset());

        $newRsp = ($rsp0 & ~0xF) - 40;
        $this->assertSame($newRsp, $this->getRegister(RegisterType::ESP, 64));

        $pushedRip = $this->readMemory($newRsp, 64);
        $pushedCs = $this->readMemory($newRsp + 8, 64);
        $pushedFlags = $this->readMemory($newRsp + 16, 64);
        $pushedRsp = $this->readMemory($newRsp + 24, 64);
        $pushedSs = $this->readMemory($newRsp + 32, 64);

        $this->assertSame(2, $pushedRip);
        $this->assertSame($userCs, $pushedCs & 0xFFFF);
        $this->assertSame(0x243, $pushedFlags & 0xFFFF);
        $this->assertSame($userRsp, $pushedRsp);
        $this->assertSame($userSs, $pushedSs & 0xFFFF);

        $this->executeBytes([0xCF]);

        $this->assertSame(3, $this->cpuContext->cpl());
        $this->assertSame($userCs, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame($userSs, $this->getRegister(RegisterType::SS, 16));
        $this->assertSame($userRsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(2, $this->memoryStream->offset());
        $this->assertTrue($this->getInterruptFlag());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    public function testIntWithIstUsesIstStackAndIretqRestoresOriginalStack(): void
    {
        $vector = 0x81;
        $gdtBase = 0x1100;
        $idtBase = 0x2100;
        $tssBase = 0x3100;
        $handlerRip = 0x5000;

        $kernelCs = 0x0008;
        $this->writeGdtLongModeCodeDescriptor($gdtBase, $kernelCs, dpl: 0);
        $this->cpuContext->setGdtr($gdtBase, 0xFF);

        // IST=1 => use TSS.IST1 (offset 0x24).
        $this->writeIdtGate64($idtBase, $vector, $handlerRip, $kernelCs, typeAttr: 0x8E, ist: 1);
        $this->cpuContext->setIdtr($idtBase, ($vector * 16) + 15);

        $ist1Rsp = 0xA000;
        $this->cpuContext->setTaskRegister(0x0028, $tssBase, 0x0067);
        $this->writeMemory($tssBase + 0x24, $ist1Rsp, 64); // IST1

        $kernelRsp = 0x9000;
        $this->setRegister(RegisterType::CS, $kernelCs, 16);
        $this->setRegister(RegisterType::SS, 0x0000, 16);
        $this->setRegister(RegisterType::ESP, $kernelRsp, 64);
        $this->setCpl(0);
        $this->cpuContext->setUserMode(false);

        $this->setInterruptFlag(true);

        $this->executeBytes([0xCD, $vector]);

        $newRsp = ($ist1Rsp & ~0xF) - 40;
        $this->assertSame($newRsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0, $this->getRegister(RegisterType::SS, 16) & 0xFFFF);
        $this->assertSame($handlerRip, $this->memoryStream->offset());

        $pushedRsp = $this->readMemory($newRsp + 24, 64);
        $this->assertSame($kernelRsp, $pushedRsp);

        $this->executeBytes([0xCF]);

        $this->assertSame($kernelRsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0, $this->getRegister(RegisterType::SS, 16) & 0xFFFF);
        $this->assertSame($kernelCs, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame(2, $this->memoryStream->offset());
    }

    private function writeGdtLongModeCodeDescriptor(int $gdtBase, int $selector, int $dpl): void
    {
        $index = ($selector >> 3) & 0x1FFF;
        $descAddr = $gdtBase + ($index * 8);

        // Null descriptor at index 0
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory($gdtBase + $i, 0x00);
        }

        $access = 0x9A | (($dpl & 0x3) << 5);
        $codeDesc = [0xFF, 0xFF, 0x00, 0x00, 0x00, $access & 0xFF, 0xAF, 0x00];
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory($descAddr + $i, $codeDesc[$i]);
        }
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
    }
}
