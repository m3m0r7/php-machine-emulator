<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\System;
use PHPMachineEmulator\Instruction\RegisterType;

final class BiosInt15MoveExtendedMemoryTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    public function testInt15Ah87MovesExtendedMemoryUsingDescriptorBases(): void
    {
        $this->setRealMode16();

        $system = new System();

        $gdtAddr = 0x0500;
        $srcBase = 0x2000;
        $dstBase = 0x3000;

        // ES:SI points to the GDT pointer table for INT 15h AH=87h.
        $this->setRegister(RegisterType::ES, 0x0000, 16);
        $this->setRegister(RegisterType::ESI, $gdtAddr, 16);

        // CX is word count; 2 words => 4 bytes copied.
        $this->setRegister(RegisterType::ECX, 2, 16);

        // Source descriptor at +0x10 (entry 2), destination at +0x18 (entry 3).
        $this->writeDescriptorBase($gdtAddr + 0x10, $srcBase);
        $this->writeDescriptorBase($gdtAddr + 0x18, $dstBase);

        // Fill source bytes.
        $this->writeMemory($srcBase + 0, 0xDE, 8);
        $this->writeMemory($srcBase + 1, 0xAD, 8);
        $this->writeMemory($srcBase + 2, 0xBE, 8);
        $this->writeMemory($srcBase + 3, 0xEF, 8);

        // INT 15h AH=87h
        $this->setRegister(RegisterType::EAX, 0x8700, 16);
        $system->process($this->runtime);

        $this->assertSame(0xDE, $this->readMemory($dstBase + 0, 8));
        $this->assertSame(0xAD, $this->readMemory($dstBase + 1, 8));
        $this->assertSame(0xBE, $this->readMemory($dstBase + 2, 8));
        $this->assertSame(0xEF, $this->readMemory($dstBase + 3, 8));

        // Success: AH=0, CF=0.
        $this->assertSame(0x00, $this->memoryAccessor->fetch(RegisterType::EAX)->asHighBit());
        $this->assertFalse($this->getCarryFlag());
    }

    private function writeDescriptorBase(int $addr, int $base): void
    {
        $this->writeMemory($addr + 2, $base & 0xFF, 8);
        $this->writeMemory($addr + 3, ($base >> 8) & 0xFF, 8);
        $this->writeMemory($addr + 4, ($base >> 16) & 0xFF, 8);
        $this->writeMemory($addr + 7, ($base >> 24) & 0xFF, 8);
    }
}
